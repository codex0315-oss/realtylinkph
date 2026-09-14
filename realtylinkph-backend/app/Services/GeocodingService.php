<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeocodingService
{
    /**
     * Geocode a free-form address to coordinates using Geoapify. Returns null on
     * any failure so a save/backfill is never blocked by the geocoder.
     *
     * @return array{lat: float, lng: float}|null
     */
    public function geocode(string $address): ?array
    {
        $key = config('services.geoapify.api_key');

        if (! $key || trim($address) === '') {
            return null;
        }

        try {
            $res = Http::timeout(8)->get('https://api.geoapify.com/v1/geocode/search', [
                'text'   => $address,
                'limit'  => 1,
                'filter' => 'countrycode:ph',   // bias results to the Philippines
                'apiKey' => $key,
            ]);

            if (! $res->successful()) {
                Log::warning('Geoapify geocode failed', ['status' => $res->status(), 'address' => $address]);

                return null;
            }

            $props = $res->json('features.0.properties');

            if (! isset($props['lat'], $props['lon'])) {
                return null;
            }

            return ['lat' => (float) $props['lat'], 'lng' => (float) $props['lon']];
        } catch (\Throwable $e) {
            Log::warning('Geoapify geocode error', ['error' => $e->getMessage(), 'address' => $address]);

            return null;
        }
    }
}
