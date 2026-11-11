<?php

namespace App\Services\Scheduling;

use App\Models\SlotOffer;
use App\Models\WorkOrder;
use Carbon\CarbonInterface;

/** Every tenant-facing text in one place, so wording can be tuned without touching logic. */
class Texts
{
    public static function window(CarbonInterface $start, CarbonInterface $end, string $tz): string
    {
        $s = $start->copy()->setTimezone($tz);
        $e = $end->copy()->setTimezone($tz);

        return $s->format('D M j').', '.$s->format('g:i').'-'.$e->format('g:i A');
    }

    public static function received(WorkOrder $wo): string
    {
        return "Axiom Maintenance: we got your request {$wo->reference}. We'll text you times to choose from shortly. "
            ."Track it here: {$wo->statusUrl()} Reply STOP to opt out.";
    }

    /** @param  SlotOffer[]  $offers */
    public static function offers(WorkOrder $wo, array $offers, string $tz): string
    {
        $lines = ["Axiom Maintenance: pick a time for {$wo->reference}. Reply with the number:"];
        foreach ($offers as $o) {
            $lines[] = $o->option_number.') '.self::window($o->starts_at, $o->ends_at, $tz);
        }
        $lines[] = 'Reply 0 if none of these work.';

        return implode("\n", $lines);
    }

    public static function booked(WorkOrder $wo, string $tz, bool $autoBooked = false): string
    {
        $when = self::window($wo->scheduled_start, $wo->scheduled_end, $tz);
        $who = $wo->technician?->first_name ?: 'A technician';
        $entry = $autoBooked ? ' Since you gave permission to enter, you do not need to be home.' : '';

        return "Axiom Maintenance: you're booked. {$who} will arrive {$when} for {$wo->reference}.{$entry} "
            .'Reply here if you need to change it.';
    }

    public static function reminder(WorkOrder $wo, string $tz): string
    {
        return 'Axiom Maintenance reminder: your visit is tomorrow, '.self::window($wo->scheduled_start, $wo->scheduled_end, $tz)
            .'. Reply here if that no longer works.';
    }

    public static function onTheWay(WorkOrder $wo, int $minutes): string
    {
        $who = $wo->technician?->first_name ?: 'Your technician';

        return "Axiom Maintenance: {$who} is on the way, about {$minutes} minutes out.";
    }

    public static function completed(WorkOrder $wo): string
    {
        return "Axiom Maintenance: work on {$wo->reference} is done. How did we do? Reply 1 to 5 (5 = great).";
    }

    public static function emergency(WorkOrder $wo, ?string $advice): string
    {
        $who = $wo->technician?->first_name;
        $text = 'Axiom Maintenance: we received your emergency request '.$wo->reference.'. ';
        $text .= $who ? "{$who} has been dispatched and will contact you shortly. " : 'Our on-call team has been alerted and will contact you shortly. ';
        if ($advice) {
            $text .= 'Until then: '.$advice;
        }

        return trim($text);
    }

    public static function noneWork(): string
    {
        return 'Axiom Maintenance: no problem. Someone from our office will reach out to find a time that works.';
    }
}
