<?php

namespace App\Http\Controllers;

use App\Models\Location;
use App\Models\Unit;
use App\Models\WorkOrder;
use App\Services\Parts\PartSuggester;
use App\Services\Scheduling\AutoScheduler;
use App\Services\Triage\Classifier;
use Illuminate\Http\Request;

/** Office staff entering a request by hand (phone call, walk-in, inspection finding). */
class WorkOrderController extends Controller
{
    public const MINUTES = [30, 45, 60, 90, 120, 180, 240, 360, 480];

    public function create(Request $request)
    {
        $location = Location::find($request->integer('location')) ?? $request->user()->location
            ?? Location::enabled()->orderBy('rm_location_id')->firstOrFail();

        return view('work-orders.create', [
            'location' => $location,
            'units' => Unit::with(['property', 'tenants'])
                ->whereHas('property', fn ($q) => $q->where('location_id', $location->id))
                ->get()->sortBy(fn ($u) => $u->property->name.' '.str_pad($u->name, 8, '0', STR_PAD_LEFT))
                ->groupBy(fn ($u) => $u->property->name),
            'minutes' => self::MINUTES,
        ]);
    }

    public function store(Request $request, Classifier $classifier, PartSuggester $parts, AutoScheduler $scheduler)
    {
        $data = $request->validate([
            'location_id' => 'required|exists:locations,id',
            'unit_id' => 'nullable|exists:units,id',
            'address_text' => 'required_without:unit_id|nullable|string|max:200',
            'contact_name' => 'nullable|string|max:120',
            'contact_phone' => 'nullable|string|max:30',
            'description' => 'required|string|min:5|max:4000',
            'trade' => 'nullable|in:plumbing,electrical,hvac,appliance,locks,pest,general',
            'urgency' => 'nullable|in:emergency,urgent,routine',
            'estimated_minutes' => 'nullable|integer|in:'.implode(',', self::MINUTES),
            'permission_to_enter' => 'nullable|boolean',
            'best_times' => 'nullable|string|max:200',
            'then' => 'required|in:auto,place',
        ]);

        $unit = isset($data['unit_id']) ? Unit::with('property', 'tenants')->find($data['unit_id']) : null;
        $tenant = $unit?->tenants->first();
        $result = $classifier->classify($data['description']);

        $wo = WorkOrder::create([
            'location_id' => $data['location_id'],
            'property_id' => $unit?->property_id,
            'unit_id' => $unit?->id,
            'tenant_id' => $tenant?->id,
            'source' => 'manual',
            'description' => $data['description'],
            'trade' => $data['trade'] ?? $result->trade,
            'urgency' => $data['urgency'] ?? $result->urgency,
            'estimated_minutes' => $data['estimated_minutes'] ?? $result->estimatedMinutes,
            'triage' => $result->toArray(),
            'triage_confidence' => isset($data['trade']) ? 1.0 : $result->confidence,
            'permission_to_enter' => (bool) ($data['permission_to_enter'] ?? false),
            'best_times' => $data['best_times'] ?? null,
            'contact_name' => $data['contact_name'] ?? $tenant?->name,
            'contact_phone' => $data['contact_phone'] ?? $tenant?->phone,
            'address_text' => $data['address_text'] ?? null,
            'status' => WorkOrder::STATUS_TRIAGED,
        ]);
        $wo->log('entered_by_office', ['then' => $data['then']], $request->user()->id);
        $parts->suggest($wo);

        if ($data['then'] === 'place') {
            return redirect()->route('schedule', ['find' => $wo->id])
                ->with('status', $wo->reference.' saved. Pick one of the suggested times, or drag it onto the calendar.');
        }

        $outcome = $scheduler->schedule($wo->fresh());
        $wo->refresh();
        $message = match ($outcome) {
            'booked' => $wo->reference.' was booked with '.$wo->technician->name.' on '.$wo->scheduled_start->setTimezone($wo->location->timezone)->format('D M j \a\t g:i A').'.',
            'offered' => $wo->reference.' saved. The tenant was texted three times to pick from.',
            'emergency' => $wo->reference.' was sent to the on-call tech as an emergency.',
            default => $wo->reference.' needs a person: '.$wo->review_reason.'.',
        };

        return redirect()->route('schedule', array_filter([
            'date' => $wo->scheduled_start?->setTimezone($wo->location->timezone)->toDateString(),
            'find' => $outcome === 'flagged' ? $wo->id : null,
        ]))->with('status', $message);
    }
}
