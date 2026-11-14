<?php

namespace App\Services\Scheduling;

use App\Models\Technician;
use App\Models\WorkOrder;
use App\Services\Sms\Messenger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Puts a tech's booked stops for one day in the order with the least driving (starting and ending
 * at the home office), then lays the arrival windows out along that route.
 * Jobs already under way and emergencies keep their place at the front.
 */
class RouteOptimizer
{
    public function __construct(private Messenger $messenger) {}

    /**
     * @param  bool  $dryRun  work out the better order and times without changing anything
     * @return array{stops:int, before:int, after:int, moved:int, plan:list<array{0:WorkOrder,1:CarbonImmutable,2:int}>} drive minutes before and after
     */
    public function optimizeDay(Technician $tech, CarbonImmutable $day, bool $notify = false, bool $dryRun = false): array
    {
        $tech->loadMissing('location');
        $tz = $tech->location->timezone;
        $from = $day->setTimezone($tz)->startOfDay();
        $home = $tech->location->office() ?? ($tech->start_lat !== null ? [(float) $tech->start_lat, (float) $tech->start_lng] : null);

        $jobs = WorkOrder::with('property', 'location', 'technician.user')
            ->where('technician_id', $tech->id)
            ->where('status', WorkOrder::STATUS_SCHEDULED)
            ->whereBetween('scheduled_start', [$from->utc(), $from->addDay()->utc()])
            ->orderBy('scheduled_start')->get();

        $result = ['stops' => $jobs->count(), 'before' => $this->driveMinutes($home, $jobs), 'after' => 0, 'moved' => 0, 'plan' => []];
        if ($jobs->count() < 2 || ! $home) {
            $result['after'] = $result['before'];

            return $result;
        }

        $fixed = $jobs->filter->isEmergency()->values();
        $ordered = $fixed->concat($this->bestOrder($fixed->last()?->coordinates() ?? $home, $home, $jobs->reject->isEmergency()->values()));
        $result['after'] = $this->driveMinutes($home, $ordered);

        // Lay the windows out along the route: arrive, work, drive to the next one.
        $workStart = $from->setTimeFromTimeString((string) ($tech->work_start ?: '08:00'));
        $clock = $from->isSameDay(CarbonImmutable::now($tz)) ? max($workStart, $this->roundUp(CarbonImmutable::now($tz)->addMinutes(60))) : $workStart;
        $here = $home;
        $window = (int) config('axiom.scheduling.window_minutes');
        $previousStart = null;
        foreach ($ordered->values() as $i => $wo) {
            $clock = $clock->addMinutes($i === 0 ? 0 : Geo::driveMinutes(Geo::km($here, $wo->coordinates())));
            $start = $this->roundDown($clock);
            if ($previousStart && $start->lte($previousStart)) {
                $start = $previousStart->addMinutes(30);
            }
            $changed = ! $wo->scheduled_start->equalTo($start->utc());
            $result['plan'][] = [$wo, $start, $i + 1];
            if ($dryRun) {
                $result['moved'] += $changed ? 1 : 0;
                $clock = $clock->addMinutes((int) ($wo->estimated_minutes ?: 60));
                $here = $wo->coordinates() ?? $here;
                $previousStart = $start;

                continue;
            }
            $wo->forceFill([
                'scheduled_start' => $start->utc(), 'scheduled_end' => $start->addMinutes($window)->utc(), 'route_position' => $i + 1,
            ])->save();
            if ($changed) {
                $result['moved']++;
                $wo->log('route_optimized', ['start' => $start->toIso8601String()]);
                if ($notify) {
                    $this->messenger->toTenant($wo, Texts::moved($wo, $tz));
                }
            }
            $clock = $clock->addMinutes((int) ($wo->estimated_minutes ?: 60));
            $here = $wo->coordinates() ?? $here;
            $previousStart = $start;
        }

        return $result;
    }

    /** Nearest-neighbour start, then 2-opt swaps until no swap shortens the loop back to the office. */
    private function bestOrder(?array $start, ?array $home, Collection $stops): Collection
    {
        $left = $stops->values()->all();
        $route = [];
        $here = $start;
        while ($left) {
            usort($left, fn ($a, $b) => (Geo::km($here, $a->coordinates()) ?? 999) <=> (Geo::km($here, $b->coordinates()) ?? 999));
            $next = array_shift($left);
            $route[] = $next;
            $here = $next->coordinates() ?? $here;
        }

        $n = count($route);
        $length = fn (array $r) => $this->loopKm($start, $home, $r);
        $best = $length($route);
        for ($improved = true, $guard = 0; $improved && $guard < 50; $guard++) {
            $improved = false;
            for ($i = 0; $i < $n - 1; $i++) {
                for ($k = $i + 1; $k < $n; $k++) {
                    $try = array_merge(array_slice($route, 0, $i), array_reverse(array_slice($route, $i, $k - $i + 1)), array_slice($route, $k + 1));
                    $len = $length($try);
                    if ($len + 0.01 < $best) {
                        [$route, $best, $improved] = [$try, $len, true];
                    }
                }
            }
        }

        return collect($route);
    }

    private function loopKm(?array $start, ?array $home, array $route): float
    {
        $km = 0.0;
        $here = $start;
        foreach ($route as $wo) {
            $km += Geo::km($here, $wo->coordinates()) ?? 0;
            $here = $wo->coordinates() ?? $here;
        }

        return $km + (Geo::km($here, $home) ?? 0);
    }

    private function driveMinutes(?array $home, Collection $jobs): int
    {
        $total = 0;
        $here = $home;
        foreach ($jobs as $wo) {
            $total += Geo::driveMinutes(Geo::km($here, $wo->coordinates()));
            $here = $wo->coordinates() ?? $here;
        }

        return $total + ($home ? Geo::driveMinutes(Geo::km($here, $home)) : 0);
    }

    private function roundDown(CarbonImmutable $t): CarbonImmutable
    {
        return $t->setTime($t->hour, $t->minute < 30 ? 0 : 30);
    }

    private function roundUp(CarbonImmutable $t): CarbonImmutable
    {
        return $t->minute === 0 || $t->minute === 30 ? $t->setSecond(0) : $this->roundDown($t)->addMinutes(30);
    }
}
