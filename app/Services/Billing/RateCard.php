<?php

namespace App\Services\Billing;

use App\Models\Setting;
use App\Models\Skill;
use App\Models\Technician;

/**
 * Hourly rate for a tech doing a kind of work, most specific first:
 * the tech's rate for that trade, then the tech's own rate, then the trade's rate, then the default.
 */
class RateCard
{
    public function hourly(?Technician $tech, ?string $trade): float
    {
        $skill = $trade ? Skill::where('slug', $trade)->first() : null;

        if ($tech && $skill) {
            $override = $tech->skills()->whereKey($skill->id)->first()?->pivot?->hourly_rate;
            if ($override !== null && (float) $override > 0) {
                return (float) $override;
            }
        }
        if ($tech && $tech->hourly_rate !== null && (float) $tech->hourly_rate > 0) {
            return (float) $tech->hourly_rate;
        }
        if ($skill && $skill->hourly_rate > 0) {
            return (float) $skill->hourly_rate;
        }

        return (float) Setting::get('default_hourly_rate');
    }

    /** Where the hourly rate came from, for showing on the rates page and invoices. */
    public function source(?Technician $tech, ?string $trade): string
    {
        $skill = $trade ? Skill::where('slug', $trade)->first() : null;
        if ($tech && $skill && (float) ($tech->skills()->whereKey($skill->id)->first()?->pivot?->hourly_rate ?? 0) > 0) {
            return 'tech + trade rate';
        }
        if ($tech && (float) ($tech->hourly_rate ?? 0) > 0) {
            return 'tech rate';
        }

        return $skill && $skill->hourly_rate > 0 ? 'trade rate' : 'default rate';
    }

    public function serviceCallFee(?string $trade): float
    {
        return (float) (Skill::where('slug', $trade ?: 'general')->value('service_call_fee') ?? 0);
    }

    public function travelPercent(): float
    {
        return Setting::get('bill_travel') === '1' ? (float) Setting::get('travel_rate_percent') : 0.0;
    }
}
