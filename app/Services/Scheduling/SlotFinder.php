<?php

namespace App\Services\Scheduling;

use App\Models\SlotOffer;
use App\Models\Technician;
use App\Models\WorkOrder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Finds the best arrival windows for a job across all qualified techs.
 *
 * Score = skill fit + closeness to the tech's other stops that day + same-building bonus
 *         - days out - how full the day already is.
 */
class SlotFinder
{
    /** @return Slot[] best first, at most $count, each at a different day/time */
    public function find(WorkOrder $wo, int $count = 3, ?CarbonImmutable $now = null): array
    {
        $candidates = $this->candidates($wo, $now);

        $picked = [];
        $seen = [];
        foreach ($candidates as $slot) {
            $key = $slot->start->format('Y-m-d H:i');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $picked[] = $slot;
            if (count($picked) >= $count) {
                break;
            }
        }

        usort($picked, fn (Slot $a, Slot $b) => $a->start <=> $b->start);

        return $picked;
    }

    /**
     * "Find best tech and time": the best few openings for each tech who has the skill and room
     * on their schedule for the whole job, techs ranked by their best opening.
     *
     * @return Slot[] best tech first
     */
    public function bestPerTech(WorkOrder $wo, int $perTech = 2, int $maxTechs = 4, ?CarbonImmutable $now = null): array
    {
        $byTech = [];
        foreach ($this->candidates($wo, $now) as $slot) {
            $byTech[$slot->technician->id][] = $slot; // already best first, and techs end up ordered by their best slot
        }

        $out = [];
        foreach (array_slice($byTech, 0, $maxTechs, true) as $slots) {
            // the tech's best opening, then their best ones on other days
            $picked = [array_shift($slots)];
            $days = [$picked[0]->start->toDateString() => true];
            foreach ($slots as $slot) {
                if (count($picked) >= $perTech) {
                    break;
                }
                if (! isset($days[$slot->start->toDateString()])) {
                    $picked[] = $slot;
                    $days[$slot->start->toDateString()] = true;
                }
            }
            array_push($out, ...$picked);
        }

        return $out;
    }

    /** @return Slot[] every open slot for every qualified tech, best score first */
    private function candidates(WorkOrder $wo, ?CarbonImmutable $now = null): array
    {
        $wo->loadMissing('location', 'property');
        $tz = $wo->location->timezone ?: config('app.timezone');
        $now = ($now ?? CarbonImmutable::now())->setTimezone($tz);
        $cfg = config('axiom.scheduling');
        $window = (int) $cfg['window_minutes'];
        $minutes = max(15, (int) ($wo->estimated_minutes ?: 60));
        $target = $wo->coordinates();

        $candidates = [];
        foreach ($this->qualifiedTechs($wo) as $tech) {
            $level = $wo->trade ? max(1, $tech->skillLevel($wo->trade)) : 2;
            // horizon counts the tech's working days, so a Friday request still sees next week
            $workdays = 0;
            for ($d = 0; $d <= 21 && $workdays < (int) $cfg['horizon_days']; $d++) {
                $day = $now->startOfDay()->addDays($d);
                if (! in_array($day->isoWeekday(), $tech->workDays(), true)) {
                    continue;
                }
                $workdays++;
                // routine work starts tomorrow; urgent can go today
                if ($d === 0 && $wo->urgency === 'routine') {
                    continue;
                }

                $busy = $this->busyBlocks($tech, $day, $tz);
                if (count(array_filter($busy, fn ($b) => $b['job'])) >= $tech->max_jobs_per_day) {
                    continue;
                }

                $dayStart = $day->setTimeFromTimeString((string) $tech->work_start);
                $dayEnd = $day->setTimeFromTimeString((string) $tech->work_end);
                $openMinutes = max(0, (int) $dayStart->diffInMinutes($dayEnd)
                    - array_sum(array_map(fn ($b) => (int) $b['start']->diffInMinutes($b['end']), $busy)));
                $earliest = $now->addMinutes(90);

                for ($ws = $dayStart; $ws->addMinutes($this->reserve() + $minutes)->lte($dayEnd); $ws = $ws->addMinutes(30)) {
                    if ($ws->lt($earliest)) {
                        continue;
                    }
                    // hold part of the arrival window plus the job itself
                    $blockEnd = $ws->addMinutes($this->reserve() + $minutes);
                    if ($this->overlaps($busy, $ws, $blockEnd)) {
                        continue;
                    }

                    $nearest = $this->nearestKm($target, $busy, $tech);
                    $sameBuilding = $wo->property_id && collect($busy)->contains(fn ($b) => $b['property_id'] === $wo->property_id);
                    $jobsThatDay = count(array_filter($busy, fn ($b) => $b['job']));

                    $score = $level * 10
                        - ($nearest ?? 15) * 0.8
                        + ($sameBuilding ? 15 : 0)
                        - $d * 4
                        - $jobsThatDay * 1.5
                        - ($ws->hour >= 15 ? 1 : 0);

                    $candidates[] = new Slot($tech, $ws->utc(), $ws->addMinutes($window)->utc(), round($score, 3), [
                        'skill_level' => $level, 'nearest_km' => $nearest === null ? null : round($nearest, 1),
                        'same_building' => $sameBuilding, 'jobs_that_day' => $jobsThatDay, 'days_out' => $d, 'job_minutes' => $minutes,
                        'open_minutes' => $openMinutes,
                    ]);
                }
            }
        }

        usort($candidates, fn (Slot $a, Slot $b) => $b->score <=> $a->score ?: $a->start <=> $b->start);

        return $candidates;
    }

    /** True if the slot is still free (used when a tenant replies hours later). */
    public function stillFree(Technician $tech, CarbonImmutable $start, int $minutes, ?int $ignoreWorkOrderId = null, string $tz = 'UTC'): bool
    {
        $day = $start->setTimezone($tz)->startOfDay();
        $busy = array_filter($this->busyBlocks($tech, $day, $tz), fn ($b) => $b['work_order_id'] !== $ignoreWorkOrderId);
        $end = $start->addMinutes($this->reserve() + $minutes);

        return ! $this->overlaps($busy, $start->setTimezone($tz), $end->setTimezone($tz));
    }

    /** @return Collection<int, Technician> */
    public function qualifiedTechs(WorkOrder $wo): Collection
    {
        $techs = Technician::with('skills', 'user')
            ->where('location_id', $wo->location_id)
            ->where('active', true)
            ->get();

        if (! $wo->trade || $wo->trade === 'general') {
            return $techs;
        }

        $skilled = $techs->filter(fn (Technician $t) => $t->skillLevel($wo->trade) > 0);

        return $skilled->isNotEmpty() ? $skilled : $techs->filter(fn (Technician $t) => $t->skillLevel('general') > 0);
    }

    /** Booked jobs plus fresh slot offers still waiting on a tenant reply (soft holds). */
    private function busyBlocks(Technician $tech, CarbonImmutable $day, string $tz): array
    {
        $from = $day->utc();
        $to = $day->addDay()->utc();
        $blocks = [];

        $jobs = WorkOrder::with('property')
            ->where('technician_id', $tech->id)
            ->whereIn('status', [WorkOrder::STATUS_SCHEDULED, WorkOrder::STATUS_EN_ROUTE, WorkOrder::STATUS_IN_PROGRESS])
            ->whereBetween('scheduled_start', [$from, $to])
            ->get();
        foreach ($jobs as $job) {
            $start = CarbonImmutable::parse($job->scheduled_start)->setTimezone($tz);
            $blocks[] = [
                'start' => $start,
                'end' => $start->addMinutes($this->reserve() + (int) ($job->estimated_minutes ?: 60)),
                'coords' => $job->coordinates(),
                'property_id' => $job->property_id,
                'work_order_id' => $job->id,
                'job' => true,
            ];
        }

        $holds = SlotOffer::open()
            ->with('workOrder.property')
            ->where('technician_id', $tech->id)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->whereBetween('starts_at', [$from, $to])
            ->get();
        foreach ($holds as $offer) {
            $start = CarbonImmutable::parse($offer->starts_at)->setTimezone($tz);
            $blocks[] = [
                'start' => $start,
                'end' => $start->addMinutes($this->reserve() + (int) ($offer->workOrder?->estimated_minutes ?: 60)),
                'coords' => $offer->workOrder?->coordinates(),
                'property_id' => $offer->workOrder?->property_id,
                'work_order_id' => $offer->work_order_id,
                'job' => false,
            ];
        }

        // Times suggested for other unbooked jobs that the office hasn't approved yet.
        $proposed = WorkOrder::with('property')
            ->where('proposed_technician_id', $tech->id)->where('proposed_kind', 'place')
            ->whereNull('scheduled_start')
            ->whereBetween('proposed_start', [$from, $to])
            ->get();
        foreach ($proposed as $job) {
            $start = CarbonImmutable::parse($job->proposed_start)->setTimezone($tz);
            $blocks[] = [
                'start' => $start,
                'end' => $start->addMinutes($this->reserve() + (int) ($job->estimated_minutes ?: 60)),
                'coords' => $job->coordinates(),
                'property_id' => $job->property_id,
                'work_order_id' => $job->id,
                'job' => true,
            ];
        }

        return $blocks;
    }

    /** Minutes of the arrival window held on the calendar besides the job itself. */
    private function reserve(): int
    {
        return min((int) config('axiom.scheduling.window_minutes'), (int) config('axiom.scheduling.window_reserve_minutes', 30));
    }

    private function overlaps(array $busy, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        foreach ($busy as $b) {
            // 15-minute travel buffer on each side
            if ($start->lt($b['end']->addMinutes(15)) && $end->gt($b['start']->subMinutes(15))) {
                return true;
            }
        }

        return false;
    }

    private function nearestKm(?array $target, array $busy, Technician $tech): ?float
    {
        $points = array_filter(array_column($busy, 'coords'));
        if ($tech->start_lat !== null && $tech->start_lng !== null) {
            $points[] = [$tech->start_lat, $tech->start_lng];
        }
        $best = null;
        foreach ($points as $p) {
            $km = Geo::km($target, $p);
            if ($km !== null && ($best === null || $km < $best)) {
                $best = $km;
            }
        }

        return $best;
    }
}
