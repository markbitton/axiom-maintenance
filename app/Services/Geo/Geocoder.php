<?php

namespace App\Services\Geo;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/** Address to coordinates through OpenStreetMap's free Nominatim service. Results are cached for a month. */
class Geocoder
{
    /** @return array{0: float, 1: float}|null */
    public function locate(string $address): ?array
    {
        $address = trim($address);
        if ($address === '' || app()->runningUnitTests()) {
            return null;
        }

        try {
            return Cache::remember('geocode:'.md5(strtolower($address)), now()->addDays(30), function () use ($address) {
                $hit = Http::timeout(8)
                    ->withHeaders(['User-Agent' => 'AxiomMaintenance/1.0 (scheduling app)'])
                    ->get('https://nominatim.openstreetmap.org/search', ['q' => $address, 'format' => 'json', 'limit' => 1, 'countrycodes' => 'us'])
                    ->json('0');

                return $hit ? [(float) $hit['lat'], (float) $hit['lon']] : null;
            });
        } catch (Throwable) {
            return null;
        }
    }
}
