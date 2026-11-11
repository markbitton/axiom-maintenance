<?php

namespace App\Services\Scheduling;

use App\Models\Technician;
use App\Models\WorkOrder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Orders a tech's day. Promised windows come first; inside the same window, the nearest next stop wins.
 * Straight-line distances for now; a Google Routes adapter can replace Geo later without touching callers.
 */
class RoutePlanner
{
    /** @return Collection<int, WorkOrder> ordered stops */
    public function planDay(Technician $tech, CarbonImmutable $day): Collection
    {
        $tz = $tech->location->timezone ?? 'UTC';
        $from = $day->setTimezone($tz)->startOfDay()->utc();
        $to = $from->addDay();

        $jobs = WorkOrder::with('property', 'unit', 'tenant')
            ->where('technician_id', $tech->id)
            ->whereIn('status', [WorkOrder::STATUS_SCHEDULED, WorkOrder::STATUS_EN_ROUTE, WorkOrder::STATUS_IN_PROGRESS, WorkOrder::STATUS_COMPLETED])
            ->whereBetween('scheduled_start', [$from, $to])
            ->get();

        $emergencies = $jobs->filter->isEmergency()->sortBy('scheduled_start');
        $rest = $jobs->reject->isEmergency()->groupBy(fn ($j) => $j->scheduled_start->format('H'))->sortKeys();

        $ordered = collect($emergencies->values());
        $here = ($tech->start_lat !== null) ? [$tech->start_lat, $tech->start_lng] : null;
        foreach ($rest as $group) {
            $remaining = $group->values();
            while ($remaining->isNotEmpty()) {
                $next = $remaining->sortBy(fn ($j) => Geo::km($here, $j->coordinates()) ?? PHP_INT_MAX)->first();
                $ordered->push($next);
                $here = $next->coordinates() ?? $here;
                $remaining = $remaining->reject(fn ($j) => $j->id === $next->id)->values();
            }
        }

        foreach ($ordered->values() as $i => $job) {
            if ($job->route_position !== $i + 1) {
                $job->forceFill(['route_position' => $i + 1])->save();
            }
        }

        return $ordered->values();
    }

    /** Minutes from the tech's previous stop (or start point) to this job. */
    public function etaMinutes(WorkOrder $wo): int
    {
        $tech = $wo->technician;
        $prev = WorkOrder::where('technician_id', $tech->id)
            ->where('status', WorkOrder::STATUS_COMPLETED)
            ->whereDate('completed_at', now()->toDateString())
            ->latest('completed_at')
            ->first();
        $from = $prev?->coordinates() ?? (($tech->start_lat !== null) ? [$tech->start_lat, $tech->start_lng] : null);

        return max(5, Geo::driveMinutes(Geo::km($from, $wo->coordinates())));
    }

    public static function mapsLink(WorkOrder $wo): string
    {
        $coords = $wo->coordinates();
        $dest = $coords ? implode(',', $coords) : $wo->displayAddress();

        return 'https://www.google.com/maps/dir/?api=1&destination='.urlencode($dest);
    }
}
