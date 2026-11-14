<?php

namespace App\Services\Scheduling;

use App\Models\Location;
use App\Models\Technician;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Sms\Messenger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Suggestions the office approves with a checkbox before anything is booked or any tenant is texted:
 *  - place: best tech and time for each job that isn't booked yet
 *  - move:  new times for booked visits that cut a tech's driving (only visits at least 24 hours away)
 */
class Planner
{
    public const NOTICE_HOURS = 24;

    public const MIN_SAVING_MINUTES = 10;

    public function __construct(
        private SlotFinder $finder,
        private RouteOptimizer $optimizer,
        private AutoScheduler $scheduler,
        private Messenger $messenger,
    ) {}

    /** Best tech and time for every job waiting to be booked. Each suggestion holds its time so the next one doesn't collide. */
    public function proposeUnbooked(Location $loc): int
    {
        $jobs = WorkOrder::with('location', 'property')->where('location_id', $loc->id)
            ->whereIn('status', [WorkOrder::STATUS_NEW, WorkOrder::STATUS_TRIAGED])
            ->whereNull('scheduled_start')->where('urgency', '!=', 'emergency')
            ->orderByRaw("case urgency when 'urgent' then 0 else 1 end")->oldest()->get();
        $jobs->each->clearProposal();

        $count = 0;
        foreach ($jobs as $wo) {
            $slot = $this->finder->find($wo, 1)[0] ?? null;
            if (! $slot) {
                continue;
            }
            $wo->forceFill([
                'proposed_technician_id' => $slot->technician->id, 'proposed_start' => $slot->start,
                'proposed_kind' => 'place', 'proposed_reason' => $this->placeReason($wo, $slot),
            ])->save();
            $count++;
        }

        return $count;
    }

    /** New visit times that shorten each tech's driving, for days where every visit is at least 24 hours away. */
    public function proposeMoves(Location $loc, int $days = 10): array
    {
        WorkOrder::where('location_id', $loc->id)->where('proposed_kind', 'move')->get()->each->clearProposal();
        $tz = $loc->timezone;
        $cutoff = CarbonImmutable::now()->addHours(self::NOTICE_HOURS);
        $moves = 0;
        $saved = 0;

        foreach (Technician::with('location')->where('location_id', $loc->id)->where('active', true)->get() as $tech) {
            for ($d = 1; $d <= $days; $d++) {
                $day = CarbonImmutable::now($tz)->startOfDay()->addDays($d);
                $booked = WorkOrder::where('technician_id', $tech->id)
                    ->whereIn('status', [WorkOrder::STATUS_SCHEDULED, WorkOrder::STATUS_EN_ROUTE, WorkOrder::STATUS_IN_PROGRESS])
                    ->whereBetween('scheduled_start', [$day->utc(), $day->addDay()->utc()]);
                if ((clone $booked)->count() < 2 || (clone $booked)->where('scheduled_start', '<', $cutoff)->exists()) {
                    continue; // too soon to give the tenant 24 hours' notice
                }
                $r = $this->optimizer->optimizeDay($tech, $day, dryRun: true);
                $saving = $r['before'] - $r['after'];
                if ($saving < self::MIN_SAVING_MINUTES || ! $r['moved']) {
                    continue;
                }
                foreach ($r['plan'] as [$wo, $start, $position]) {
                    if ($wo->scheduled_start->equalTo($start->utc()) || $start->utc()->lt($cutoff)) {
                        continue;
                    }
                    $wo->forceFill([
                        'proposed_technician_id' => $tech->id, 'proposed_start' => $start->utc(), 'proposed_kind' => 'move',
                        'proposed_reason' => "Saves about {$saving} min of driving for {$tech->name} that day (stop {$position} of {$r['stops']})",
                    ])->save();
                    $moves++;
                }
                $saved += $saving;
            }
        }

        return ['moves' => $moves, 'saved' => $saved];
    }

    /** Book or move the approved suggestions and text each tenant. @return int how many were approved */
    public function approve(Collection $ids, ?User $by = null): int
    {
        $done = 0;
        $window = (int) config('axiom.scheduling.window_minutes');
        WorkOrder::with('location', 'proposedTechnician.user', 'technician.user')->whereIn('id', $ids)->whereNotNull('proposed_start')->get()
            ->each(function (WorkOrder $wo) use (&$done, $window, $by) {
                $start = CarbonImmutable::parse($wo->proposed_start);
                $tech = $wo->proposedTechnician;
                if (! $tech) {
                    return;
                }
                if ($wo->proposed_kind === 'move') {
                    if ($wo->scheduled_start === null || $start->lt(CarbonImmutable::now()->addHours(self::NOTICE_HOURS))) {
                        $wo->clearProposal(); // too late now to give 24 hours' notice

                        return;
                    }
                    DB::transaction(function () use ($wo, $start, $window, $by) {
                        $wo->forceFill(['scheduled_start' => $start, 'scheduled_end' => $start->addMinutes($window)])->save();
                        $wo->log('route_optimized', ['start' => $start->toIso8601String(), 'approved' => true], $by?->id);
                        $wo->clearProposal();
                    });
                    $this->messenger->toTenant($wo, Texts::moved($wo, $wo->location->timezone));
                } else {
                    $this->scheduler->book($wo, $tech, $start, $start->addMinutes($window), by: $by);
                }
                $done++;
            });

        return $done;
    }

    public function dismiss(Collection $ids): void
    {
        WorkOrder::whereIn('id', $ids)->get()->each->clearProposal();
    }

    private function placeReason(WorkOrder $wo, Slot $slot): string
    {
        $w = $slot->why;
        $levels = [1 => 'basic', 2 => 'solid', 3 => 'expert'];
        $bits = [];
        if ($wo->trade) {
            $bits[] = ($levels[$w['skill_level'] ?? 2] ?? 'solid').' at '.$wo->tradeLabel();
        }
        if (! empty($w['same_building'])) {
            $bits[] = 'already at this building that day';
        } elseif (($w['nearest_km'] ?? null) !== null) {
            $bits[] = Geo::driveMinutes($w['nearest_km']).' min from their nearest stop';
        }
        $bits[] = 'room for the whole job';

        return ucfirst(implode(', ', $bits));
    }
}
