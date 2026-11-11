<?php

namespace App\Services\Scheduling;

class Geo
{
    /** Straight-line distance in km. */
    public static function km(?array $a, ?array $b): ?float
    {
        if (! $a || ! $b) {
            return null;
        }
        [$lat1, $lng1] = array_map('deg2rad', $a);
        [$lat2, $lng2] = array_map('deg2rad', $b);
        $h = sin(($lat2 - $lat1) / 2) ** 2 + cos($lat1) * cos($lat2) * sin(($lng2 - $lng1) / 2) ** 2;

        return 6371 * 2 * asin(min(1, sqrt($h)));
    }

    /** Rough drive minutes from straight-line distance (roads add ~30%). */
    public static function driveMinutes(?float $km): int
    {
        if ($km === null) {
            return 20;
        }
        $speed = max(10, (int) config('axiom.scheduling.travel_speed_kmh', 40));

        return (int) ceil(($km * 1.3) / $speed * 60);
    }
}
