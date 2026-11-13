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
        /** What drove the score, for showing a person why this time was picked. */
        public readonly array $why = [],
    ) {}
}
