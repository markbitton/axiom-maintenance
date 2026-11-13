<?php

namespace App\Http\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Jobs\PushToRentManager;
use App\Models\Part;
use App\Models\Photo;
use App\Models\StockLevel;
use App\Models\WorkOrder;
use App\Services\Parts\PartSuggester;
use App\Services\Photos\R2Storage;
use App\Services\Scheduling\RoutePlanner;
use App\Services\Scheduling\Texts;
use App\Services\Sms\Messenger;
use App\Services\Time\TimeClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/** The tech's phone view: today's route, job details, and the buttons that drive tenant texts. */
class JobController extends Controller
{
    public function today(Request $request, RoutePlanner $planner, TimeClock $clock)
    {
        $tech = $request->user()->technician()->with('location', 'user')->firstOrFail();
        $day = CarbonImmutable::parse($request->query('day', now($tech->location->timezone)->toDateString()), $tech->location->timezone);
        $activity = $clock->activity($tech);

        return view('tech.today', ['tech' => $tech, 'day' => $day, 'stops' => $planner->planDay($tech, $day),
            'shift' => $clock->shift($tech), 'activity' => $activity?->load('workOrder')]);
    }

    public function show(Request $request, WorkOrder $workOrder, PartSuggester $parts, R2Storage $r2, TimeClock $clock)
    {
        $this->authorizeJob($request, $workOrder);
        $workOrder->load('tenant', 'property', 'unit', 'location', 'messages.user', 'parts', 'photos');
        $history = $workOrder->unit_id
            ? WorkOrder::where('unit_id', $workOrder->unit_id)->where('id', '!=', $workOrder->id)->latest()->limit(5)->get()
            : collect();

        return view('tech.show', [
            'wo' => $workOrder,
            'history' => $history,
            'missing' => $parts->missingFromTruck($workOrder),
            'catalog' => Part::orderBy('name')->get(['id', 'name']),
            'mapsLink' => RoutePlanner::mapsLink($workOrder),
            'appleLink' => RoutePlanner::appleMapsLink($workOrder),
            'shift' => ($tech = $request->user()->technician) ? $clock->shift($tech) : null,
            'activity' => $tech ? $clock->activity($tech) : null,
            'jobMinutes' => $workOrder->timeEntries()->get()->groupBy('type')->map(fn ($g) => $g->sum(fn ($e) => $e->minutes())),
            'photosEnabled' => $r2->isConfigured(),
        ]);
    }

    public function onMyWay(Request $request, WorkOrder $workOrder, RoutePlanner $planner, Messenger $messenger, TimeClock $clock)
    {
        $this->authorizeJob($request, $workOrder);
        $this->clockJob($request, $clock, $workOrder, 'travel');
        $workOrder->update(['status' => WorkOrder::STATUS_EN_ROUTE, 'en_route_at' => now()]);
        $minutes = $planner->etaMinutes($workOrder);
        $messenger->toTenant($workOrder, Texts::onTheWay($workOrder, $minutes), $request->user());
        $workOrder->log('en_route', ['eta_minutes' => $minutes], $request->user()->id);

        return back()->with('status', "Tenant told you're about {$minutes} minutes away.");
    }

    public function start(Request $request, WorkOrder $workOrder, TimeClock $clock)
    {
        $this->authorizeJob($request, $workOrder);
        $this->clockJob($request, $clock, $workOrder, 'work');
        $workOrder->update(['status' => WorkOrder::STATUS_IN_PROGRESS, 'started_at' => $workOrder->started_at ?? now()]);
        $workOrder->log('started', [], $request->user()->id);

        return back()->with('status', 'Job clock running.');
    }

    /** Stop the job clock and leave the job open for a return visit (bill what's done so far). */
    public function pause(Request $request, WorkOrder $workOrder, TimeClock $clock)
    {
        $this->authorizeJob($request, $workOrder);
        $data = $request->validate(['notes' => 'required|string|max:4000']);
        if ($tech = $request->user()->technician) {
            $clock->stopActivity($tech, $request->filled('lat') ? ClockController::gps($request) : ['lat' => null, 'lng' => null]);
        }
        $workOrder->update([
            'status' => WorkOrder::STATUS_TRIAGED, 'scheduled_start' => null, 'scheduled_end' => null, 'route_position' => null,
            'completion_notes' => trim(($workOrder->completion_notes ? $workOrder->completion_notes."\n" : '').now()->format('M j').': '.$data['notes']),
        ]);
        $workOrder->log('return_visit_needed', ['notes' => $data['notes']], $request->user()->id);
        $workOrder->flagForReview('Tech needs to come back to finish');

        return redirect()->route('tech.today')->with('status', $workOrder->reference.' saved. The office will book the return visit.');
    }

    public function complete(Request $request, WorkOrder $workOrder, Messenger $messenger)
    {
        $this->authorizeJob($request, $workOrder);
        $data = $request->validate([
            'notes' => 'required|string|max:4000',
            'lat' => 'nullable|numeric', 'lng' => 'nullable|numeric',
            'labor_minutes' => 'nullable|integer|min:0|max:1440',
            'used' => 'nullable|array',
            'used.*' => 'nullable|integer|min:0|max:99',
            'extra_part_id' => 'nullable|exists:parts,id',
            'extra_qty' => 'nullable|integer|min:1|max:99',
            'needs_return' => 'boolean',
        ]);

        $truck = $workOrder->technician?->truck;
        foreach ($workOrder->parts as $line) {
            $qty = (int) ($data['used'][$line->id] ?? 0);
            $line->update(['used' => $qty > 0, 'quantity' => max(1, $qty ?: $line->quantity), 'stock_location_id' => $qty > 0 ? $truck?->id : null]);
            if ($qty > 0 && $truck && $line->part_id) {
                StockLevel::firstOrCreate(['stock_location_id' => $truck->id, 'part_id' => $line->part_id])->decrement('quantity', $qty);
            }
        }
        if (! empty($data['extra_part_id'])) {
            $part = Part::find($data['extra_part_id']);
            $qty = (int) ($data['extra_qty'] ?? 1);
            $workOrder->parts()->create(['part_id' => $part->id, 'part_name' => $part->name, 'quantity' => $qty, 'used' => true, 'stock_location_id' => $truck?->id]);
            if ($truck) {
                StockLevel::firstOrCreate(['stock_location_id' => $truck->id, 'part_id' => $part->id])->decrement('quantity', $qty);
            }
        }

        $tech = $request->user()->technician;
        if ($tech && $request->filled('lat')) {
            app(TimeClock::class)->stopActivity($tech, ClockController::gps($request));
        }
        $worked = $workOrder->timeEntries()->where('type', 'work')->get()->sum(fn ($e) => $e->minutes());

        $workOrder->update([
            'status' => WorkOrder::STATUS_COMPLETED,
            'completed_at' => now(),
            'completion_notes' => $data['notes'],
            'labor_minutes' => $data['labor_minutes'] ?? ($worked ?: ($workOrder->started_at ? (int) $workOrder->started_at->diffInMinutes(now()) : null)),
        ]);
        $workOrder->log('completed', ['needs_return' => (bool) ($data['needs_return'] ?? false)], $request->user()->id);
        if (! empty($data['needs_return'])) {
            $workOrder->flagForReview('Tech says a return visit is needed');
        }

        $messenger->toTenant($workOrder, Texts::completed($workOrder), $request->user());
        PushToRentManager::dispatch($workOrder->id);

        return redirect()->route('tech.today')->with('status', $workOrder->reference.' completed.');
    }

    public function message(Request $request, WorkOrder $workOrder, Messenger $messenger)
    {
        $this->authorizeJob($request, $workOrder);
        $data = $request->validate(['body' => 'required|string|max:600']);
        $messenger->toTenant($workOrder, $data['body'], $request->user());

        return back()->with('status', 'Sent from the Axiom number.');
    }

    public function photoUrl(Request $request, WorkOrder $workOrder, R2Storage $r2)
    {
        $this->authorizeJob($request, $workOrder);
        $data = $request->validate(['content_type' => 'required|in:image/jpeg,image/png,image/heic,image/webp']);
        $key = $r2->newKey('jobs/'.$workOrder->reference, $data['content_type']);

        return response()->json(['upload_url' => $r2->uploadUrl($key), 'photo_key' => $key]);
    }

    public function photoSaved(Request $request, WorkOrder $workOrder)
    {
        $this->authorizeJob($request, $workOrder);
        $data = $request->validate([
            'photo_key' => 'required|string|starts_with:jobs/'.$workOrder->reference.'/',
            'kind' => 'required|in:before,after',
            'content_type' => 'required|string',
            'size' => 'nullable|integer',
        ]);
        Photo::firstOrCreate(['object_key' => $data['photo_key']], [
            'work_order_id' => $workOrder->id,
            'kind' => $data['kind'],
            'content_type' => $data['content_type'],
            'size_bytes' => $data['size'] ?? null,
            'uploaded_by_user_id' => $request->user()->id,
        ]);

        return response()->json(['ok' => true]);
    }

    /** Techs start the job clock (travel or work) from the job buttons. Clocks them in first if they forgot. */
    private function clockJob(Request $request, TimeClock $clock, WorkOrder $workOrder, string $type): void
    {
        $tech = $request->user()->technician;
        if (! $tech || $tech->id !== $workOrder->technician_id) {
            return;
        }
        $gps = ClockController::gps($request);
        if (! $clock->shift($tech)) {
            $clock->clockIn($tech, $gps);
            session()->flash('clocked_in', true);
        }
        $type === 'travel' ? $clock->travel($tech, $workOrder, $gps) : $clock->work($tech, $workOrder, $gps);
    }

    private function authorizeJob(Request $request, WorkOrder $workOrder): void
    {
        $user = $request->user();
        if ($user->hasRole('admin', 'scheduler')) {
            return;
        }
        abort_unless($user->technician && $workOrder->technician_id === $user->technician->id, 403);
    }
}
