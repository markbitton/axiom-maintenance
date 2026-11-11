<?php

namespace App\Support;

class Phone
{
    /** Normalize a US number to E.164 (+15551234567). Returns null if it can't be read. */
    public static function normalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $raw);
        if (strlen($digits) === 10) {
            return '+1'.$digits;
        }
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            return '+'.$digits;
        }
        if (str_starts_with(trim($raw), '+') && strlen($digits) >= 11 && strlen($digits) <= 15) {
            return '+'.$digits;
        }

        return null;
    }
}
