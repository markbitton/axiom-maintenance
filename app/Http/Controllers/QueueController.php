<?php

namespace App\Http\Controllers;

use App\Jobs\PushToRentManager;
use App\Models\Technician;
use App\Models\WorkOrder;
use App\Services\Billing\Invoicer;
use App\Services\Photos\R2Storage;
use App\Services\Scheduling\AutoScheduler;
use App\Services\Scheduling\SlotFinder;
use App\Services\Sms\Messenger;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/** The scheduler's (and admin's) view: only the jobs the system could not settle, plus a day board. */
class QueueController extends Controller
{
    public function index(Request $request)
    {
        $day = CarbonImmutable::parse($request->query('day', now()->toDateString()));

        return view('queue.index', [
            'review' => WorkOrder::with('tenant', 'property', 'technician.user', 'location')->needsReview()
                ->orderByRaw("case urgency when 'emergency' then 0 when 'urgent' then 1 else 2 end")
                ->orderBy('needs_review_at')->get(),
            'waiting' => WorkOrder::with('tenant', 'property')->where('status', WorkOrder::STATUS_OFFERED)->latest()->get(),
            'day' => $day,
            'board' => Technician::with(['user', 'workOrders' => fn ($q) => $q
                ->whereBetween('scheduled_start', [$day->startOfDay()->utc(), $day->endOfDay()->utc()])
                ->orderBy('scheduled_start')])->where('active', true)->get(),
        ]);
    }

    public function show(WorkOrder $workOrder, SlotFinder $finder, R2Storage $r2, Invoicer $invoicer)
    {
        $workOrder->load('tenant', 'property', 'unit', 'technician.user', 'location', 'messages.user', 'parts.part', 'offers.technician.user', 'activity', 'photos',
            'timeEntries.technician.user', 'timeEntries.invoice', 'invoices');

        return view('queue.show', [
            'wo' => $workOrder,
            'suggestions' => in_array($workOrder->status, WorkOrder::OPEN_STATUSES, true) ? $finder->find($workOrder, 5) : [],
            'techs' => Technician::with('user')->where('location_id', $workOrder->location_id)->where('active', true)->get(),
            'unbilled' => $invoicer->preview($workOrder),
            'photoUrls' => $r2->isConfigured() ? $workOrder->photos->whereNull('purged_at')->mapWithKeys(fn ($p) => [$p->id => $r2->viewUrl($p->object_key, 900)]) : collect(),
        ]);
    }

    public function book(Request $request, WorkOrder $workOrder, AutoScheduler $scheduler)
    {
        $data = $request->validate(['technician_id' => 'required|exists:technicians,id', 'start' => 'required|date']);
        $tz = $workOrder->location->timezone;
        $start = CarbonImmutable::parse($data['start'], $tz)->utc();
        $scheduler->book($workOrder, Technician::with('user')->findOrFail($data['technician_id']), $start,
            $start->addMinutes((int) config('axiom.scheduling.window_minutes')), by: $request->user());
        $workOrder->clearReview();

        return redirect()->route('queue.show', $workOrder)->with('status', 'Booked and the tenant was texted.');
    }

    public function reoffer(WorkOrder $workOrder, SlotFinder $finder, AutoScheduler $scheduler)
    {
        $slots = $finder->find($workOrder, (int) config('axiom.scheduling.offers_per_request'));
        if ($slots === []) {
            return back()->withErrors(['offer' => 'No open slots found.']);
        }
        $scheduler->offer($workOrder, $slots, $workOrder->location->timezone);
        $workOrder->clearReview();

        return back()->with('status', 'New times texted to the tenant.');
    }

    public function update(Request $request, WorkOrder $workOrder)
    {
        $data = $request->validate([
            'trade' => 'nullable|in:plumbing,electrical,hvac,appliance,locks,pest,general',
            'urgency' => 'nullable|in:emergency,urgent,routine',
            'estimated_minutes' => 'nullable|integer|min:15|max:480',
        ]);
        $workOrder->update(array_filter($data));
        $workOrder->log('edited', $data, $request->user()->id);

        return back()->with('status', 'Updated.');
    }

    public function resolve(Request $request, WorkOrder $workOrder)
    {
        $workOrder->clearReview();
        $workOrder->log('review_cleared', [], $request->user()->id);

        return redirect()->route('queue')->with('status', $workOrder->reference.' cleared from the queue.');
    }

    public function cancel(Request $request, WorkOrder $workOrder)
    {
        $workOrder->offers()->open()->update(['withdrawn_at' => now()]);
        $workOrder->update(['status' => WorkOrder::STATUS_CANCELLED]);
        $workOrder->clearReview();
        $workOrder->log('cancelled', ['reason' => $request->input('reason')], $request->user()->id);
        PushToRentManager::dispatch($workOrder->id);

        return redirect()->route('queue')->with('status', $workOrder->reference.' cancelled.');
    }

    public function message(Request $request, WorkOrder $workOrder, Messenger $messenger)
    {
        $data = $request->validate(['body' => 'required|string|max:600']);
        $messenger->toTenant($workOrder, $data['body'], $request->user());

        return back()->with('status', 'Sent.');
    }
}
