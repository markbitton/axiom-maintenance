<?php

namespace App\Services\Time;

use App\Models\Technician;
use App\Models\TimeEntry;
use Carbon\CarbonImmutable;

/**
 * How a tech's paid day was spent. Utilization = time on work orders / time on the employee clock,
 * where drive time to Axiom Property Management jobs counts as time on work orders.
 */
class Efficiency
{
    /** @return array{shift:int, work:int, travel_axiom:int, travel_other:int, break:int, idle:int, utilization:?float} */
    public function day(Technician $tech, CarbonImmutable $day): array
    {
        $tz = $tech->location?->timezone ?? 'UTC';
        $from = $day->setTimezone($tz)->startOfDay();
        $entries = $tech->timeEntries()->with('workOrder.property')
            ->where('started_at', '>=', $from->utc())->where('started_at', '<', $from->addDay()->utc())->get();

        return $this->summarize($entries);
    }

    /** @return array<string, array> date => day summary, for each day in the range */
    public function range(Technician $tech, CarbonImmutable $from, int $days): array
    {
        $tz = $tech->location?->timezone ?? 'UTC';
        $start = $from->setTimezone($tz)->startOfDay();
        $entries = $tech->timeEntries()->with('workOrder.property')
            ->where('started_at', '>=', $start->utc())->where('started_at', '<', $start->addDays($days)->utc())->get()
            ->groupBy(fn (TimeEntry $e) => $e->started_at->setTimezone($tz)->toDateString());

        $out = [];
        for ($i = 0; $i < $days; $i++) {
            $d = $start->addDays($i)->toDateString();
            $out[$d] = $this->summarize($entries->get($d, collect()));
        }

        return $out;
    }

    public function summarize($entries): array
    {
        $now = CarbonImmutable::now();
        $t = ['shift' => 0, 'work' => 0, 'travel_axiom' => 0, 'travel_other' => 0, 'break' => 0];
        foreach ($entries as $e) {
            $m = $e->minutes($now);
            match ($e->type) {
                TimeEntry::SHIFT => $t['shift'] += $m,
                TimeEntry::WORK => $t['work'] += $m,
                TimeEntry::BREAK => $t['break'] += $m,
                TimeEntry::TRAVEL => ($e->workOrder?->property?->managed_by_axiom ?? true) ? $t['travel_axiom'] += $m : $t['travel_other'] += $m,
                default => null,
            };
        }
        $t['idle'] = max(0, $t['shift'] - $t['work'] - $t['travel_axiom'] - $t['travel_other'] - $t['break']);
        $t['utilization'] = $t['shift'] > 0 ? round(min(1, ($t['work'] + $t['travel_axiom']) / $t['shift']), 3) : null;

        return $t;
    }
}
