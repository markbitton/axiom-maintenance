<?php

namespace App\Services\Scheduling;

use App\Models\Technician;
use Carbon\CarbonImmutable;

final class Slot
{
    public function __construct(
        public readonly Technician $technician,
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
        public readonly float $score,
    ) {}
}
