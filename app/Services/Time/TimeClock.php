<?php

namespace App\Services\Time;

use App\Models\LocationPing;
use App\Models\Technician;
use App\Models\TimeEntry;
use App\Models\WorkOrder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The tech's two clocks. The employee clock (shift) must be running before any job clock.
 * Inside a shift only one of travel / work / break runs at a time; starting one stops the other.
 * Every clock change needs the phone's location, which is also saved as a GPS check-in.
 */
class TimeClock
{
    public function shift(Technician $tech): ?TimeEntry
    {
        return $tech->timeEntries()->open()->where('type', TimeEntry::SHIFT)->latest('started_at')->first();
    }

    /** The running travel, work or break entry, if any. */
    public function activity(Technician $tech): ?TimeEntry
    {
        return $tech->timeEntries()->open()->where('type', '!=', TimeEntry::SHIFT)->latest('started_at')->first();
    }

    public function clockIn(Technician $tech, array $gps): TimeEntry
    {
        return $this->shift($tech) ?? DB::transaction(function () use ($tech, $gps) {
            $this->ping($tech, $gps, 'clock_in');

            return $this->open($tech, TimeEntry::SHIFT, null, $gps);
        });
    }

    public function clockOut(Technician $tech, array $gps): void
    {
        DB::transaction(function () use ($tech, $gps) {
            $this->stopActivity($tech, $gps);
            $this->shift($tech)?->update($this->closing($gps));
            $this->ping($tech, $gps, 'clock_out');
        });
    }

    public function travel(Technician $tech, WorkOrder $wo, array $gps): TimeEntry
    {
        return $this->switchTo($tech, TimeEntry::TRAVEL, $wo, $gps);
    }

    public function work(Technician $tech, WorkOrder $wo, array $gps): TimeEntry
    {
        return $this->switchTo($tech, TimeEntry::WORK, $wo, $gps);
    }

    /** Break pauses whatever is running. Resume restarts the job clock for the job that was paused. */
    public function startBreak(Technician $tech, array $gps): TimeEntry
    {
        $paused = $this->activity($tech);

        return $this->switchTo($tech, TimeEntry::BREAK, $paused?->workOrder, $gps);
    }

    public function endBreak(Technician $tech, array $gps): ?TimeEntry
    {
        $break = $this->activity($tech);
        if (! $break || $break->type !== TimeEntry::BREAK) {
            return null;
        }
        $wo = $break->workOrder;

        return $wo ? $this->switchTo($tech, TimeEntry::WORK, $wo, $gps) : tap(null, fn () => $this->stopActivity($tech, $gps));
    }

    public function stopActivity(Technician $tech, array $gps): void
    {
        $this->activity($tech)?->update($this->closing($gps));
    }

    public function ping(Technician $tech, array $gps, string $reason = 'interval'): LocationPing
    {
        return $tech->pings()->create([
            'lat' => $gps['lat'], 'lng' => $gps['lng'], 'accuracy_m' => $gps['accuracy'] ?? null,
            'reason' => $reason, 'recorded_at' => CarbonImmutable::now(),
        ]);
    }

    private function switchTo(Technician $tech, string $type, ?WorkOrder $wo, array $gps): TimeEntry
    {
        if (! $this->shift($tech)) {
            throw new RuntimeException('Clock in for the day first.');
        }

        return DB::transaction(function () use ($tech, $type, $wo, $gps) {
            $this->stopActivity($tech, $gps);
            $this->ping($tech, $gps, 'job');

            return $this->open($tech, $type, $wo, $gps);
        });
    }

    private function open(Technician $tech, string $type, ?WorkOrder $wo, array $gps): TimeEntry
    {
        return $tech->timeEntries()->create([
            'type' => $type, 'work_order_id' => $wo?->id, 'started_at' => CarbonImmutable::now(),
            'start_lat' => $gps['lat'], 'start_lng' => $gps['lng'],
        ]);
    }

    private function closing(array $gps): array
    {
        return ['ended_at' => CarbonImmutable::now(), 'end_lat' => $gps['lat'], 'end_lng' => $gps['lng']];
    }
}
