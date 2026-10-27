<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Property;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * "What's nearby" for a listing, and how long it takes to get there.
 *
 * Geoapify Places finds the nearest of each kind of essential (one request per
 * kind, run in parallel); one Route Matrix call per mode then gives real road
 * times from the property to all of them. The result is cached for 30 days per
 * listing location, so each listing costs ~14 requests a month at most.
 *
 * travelTime() answers "how far is it from me?" for a point the browser sends.
 * That point is used for the one routing call and never stored or logged.
 */
class NearbyService
{
    private const BASE = 'https://api.geoapify.com';

    /**
     * key => [label, group, Geoapify categories, search radius in metres]
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: int}>
     */
    public const KINDS = [
        'hospital' => ['Hospitals',        'health',    'healthcare.hospital',                          10000],
        'clinic'   => ['Clinics',          'health',    'healthcare.clinic_or_praxis',                   4000],
        'pharmacy' => ['Pharmacies',       'health',    'healthcare.pharmacy',                           3000],
        'mall'     => ['Malls',            'shopping',  'commercial.shopping_mall',                     10000],
        'grocery'  => ['Supermarkets',     'shopping',  'commercial.supermarket',                        5000],
        'market'   => ['Public markets',   'shopping',  'commercial.marketplace',                        6000],
        'church'   => ['Churches',         'faith',     'religion.place_of_worship',                     4000],
        'school'   => ['Schools',          'faith',     'education.school',                              4000],
        'transit'  => ['Transport stops',  'transport', 'public_transport',                              3000],
        'bank'     => ['Banks & ATMs',     'transport', 'service.financial.bank,service.financial.atm',  3000],
        'police'   => ['Police stations',  'transport', 'service.police',                                8000],
        'fire'     => ['Fire stations',    'transport', 'service.fire_station',                         10000],
    ];

    private const PER_KIND = 2;

    /** @return array{available: bool, kinds: array<int, array<string, mixed>>} */
    public function forProperty(Property $property): array
    {
        $key = config('services.geoapify.api_key');
        if (! $key || ! $property->lat || ! $property->lng) {
            return ['available' => false, 'kinds' => []];
        }

        $lat = round((float) $property->lat, 5);
        $lng = round((float) $property->lng, 5);
        $cacheKey = "nearby:v1:{$property->id}:{$lat}:{$lng}";

        if ($hit = Cache::get($cacheKey)) {
            return $hit;
        }

        $result = $this->lookup($key, $lat, $lng);
        // A failed lookup is retried within the hour rather than cached for a month.
        Cache::put($cacheKey, $result, $result['kinds'] ? now()->addDays(30) : now()->addHour());

        return $result;
    }

    /** @return array{available: bool, kinds: array<int, array<string, mixed>>} */
    private function lookup(string $key, float $lat, float $lng): array
    {
        try {
            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn (string $kind) => $pool->as($kind)->timeout(10)->get(self::BASE . '/v2/places', [
                    'categories' => self::KINDS[$kind][2],
                    'filter'     => "circle:{$lng},{$lat}," . self::KINDS[$kind][3],
                    'bias'       => "proximity:{$lng},{$lat}",
                    'limit'      => self::PER_KIND + 3,
                    'apiKey'     => $key,
                ]),
                array_keys(self::KINDS),
            ));
        } catch (\Throwable $e) {
            Log::warning('Geoapify places error', ['error' => $e->getMessage()]);

            return ['available' => true, 'kinds' => []];
        }

        // Nearest places per kind.
        $places = [];
        foreach (self::KINDS as $kind => [$label]) {
            $res = $responses[$kind] ?? null;
            if (! $res instanceof \Illuminate\Http\Client\Response || ! $res->successful()) {
                continue;
            }
            // Named places first: an unnamed one would otherwise show as just a
            // street ("Legaspi Street" listed as a mall).
            $features = array_filter($res->json('features', []), fn ($f) => isset($f['properties']['lat'], $f['properties']['lon']));
            usort($features, fn ($a, $b) => (int) empty($a['properties']['name']) <=> (int) empty($b['properties']['name']));
            $seen = [];
            foreach ($features as $f) {
                $p = $f['properties'];
                $named = ! empty($p['name']);
                // One entry per place: OSM often maps a mall twice (building + area).
                $dedupe = $named ? mb_strtolower($p['name']) : null;
                if (count($seen) >= self::PER_KIND || ($dedupe && isset($seen[$dedupe]))) {
                    continue;
                }
                $seen[$dedupe ?? 'unnamed-' . count($seen)] = true;
                $places[] = [
                    'kind'       => $kind,
                    'name'       => $named ? $p['name'] : self::singular($label),
                    'address'    => $named ? ($p['address_line2'] ?? null) : ($p['address_line1'] ?? null),
                    'lat'        => (float) $p['lat'],
                    'lng'        => (float) $p['lon'],
                    'distance_m' => isset($p['distance']) ? (int) $p['distance'] : null,
                ];
            }
        }

        if (! $places) {
            return ['available' => true, 'kinds' => []];
        }

        // Road times from the property to every place, by car and on foot.
        $drive = $this->matrix($key, 'drive', $lat, $lng, $places);
        $walk  = $this->matrix($key, 'walk', $lat, $lng, $places);

        foreach ($places as $i => &$place) {
            $place['drive_min'] = isset($drive[$i]['time']) ? max(1, (int) round($drive[$i]['time'] / 60)) : null;
            $place['walk_min']  = isset($walk[$i]['time']) ? max(1, (int) round($walk[$i]['time'] / 60)) : null;
            if (isset($drive[$i]['distance'])) {
                $place['distance_m'] = (int) $drive[$i]['distance'];   // by road beats as-the-crow-flies
            }
        }
        unset($place);

        $kinds = [];
        foreach (self::KINDS as $kind => [$label, $group]) {
            $items = array_values(array_filter($places, fn ($p) => $p['kind'] === $kind));
            if (! $items) {
                continue;
            }
            usort($items, fn ($a, $b) => ($a['drive_min'] ?? PHP_INT_MAX) <=> ($b['drive_min'] ?? PHP_INT_MAX));
            $kinds[] = [
                'key'   => $kind,
                'label' => $label,
                'group' => $group,
                'items' => array_map(fn ($p) => array_diff_key($p, ['kind' => true]), $items),
            ];
        }

        return ['available' => true, 'kinds' => $kinds];
    }

    /** "Banks & ATMs" → "Bank / ATM", "Churches" → "Church", for unnamed places. */
    private static function singular(string $label): string
    {
        return match ($label) {
            'Banks & ATMs'    => 'Bank / ATM',
            'Churches'        => 'Church',
            'Pharmacies'      => 'Pharmacy',
            'Transport stops' => 'Transport stop',
            default           => preg_replace('/s$/', '', $label),
        };
    }

    /**
     * One-to-many road times. Returns the row for our single source, aligned
     * with $targets; empty on failure (the places still show, just without minutes).
     *
     * @param  array<int, array{lat: float, lng: float}>  $targets
     * @return array<int, array{time?: float, distance?: float}|null>
     */
    private function matrix(string $key, string $mode, float $lat, float $lng, array $targets): array
    {
        try {
            $res = Http::timeout(12)->post(self::BASE . "/v1/routematrix?apiKey={$key}", [
                'mode'    => $mode,
                'sources' => [['location' => [$lng, $lat]]],
                'targets' => array_map(fn ($t) => ['location' => [$t['lng'], $t['lat']]], $targets),
            ]);
            if (! $res->successful()) {
                Log::warning('Geoapify route matrix failed', ['status' => $res->status(), 'mode' => $mode]);

                return [];
            }

            return $res->json('sources_to_targets.0', []);
        } catch (\Throwable $e) {
            Log::warning('Geoapify route matrix error', ['error' => $e->getMessage(), 'mode' => $mode]);

            return [];
        }
    }

    /**
     * Drive time and road distance from a point (the buyer's location) to the
     * listing. The point isn't cached, stored or logged.
     *
     * @return array{drive_min: int, distance_km: float}|null
     */
    public function travelTime(Property $property, float $fromLat, float $fromLng): ?array
    {
        $key = config('services.geoapify.api_key');
        if (! $key || ! $property->lat || ! $property->lng) {
            return null;
        }

        try {
            $res = Http::timeout(10)->get(self::BASE . '/v1/routing', [
                'waypoints' => "{$fromLat},{$fromLng}|{$property->lat},{$property->lng}",
                'mode'      => 'drive',
                'apiKey'    => $key,
            ]);
            $p = $res->successful() ? $res->json('features.0.properties') : null;
            if (! isset($p['time'], $p['distance'])) {
                return null;
            }

            return [
                'drive_min'   => max(1, (int) round($p['time'] / 60)),
                'distance_km' => round($p['distance'] / 1000, 1),
            ];
        } catch (\Throwable $e) {
            Log::warning('Geoapify routing error', ['error' => $e->getMessage()]);

            return null;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // RealtyLink AI: "homes near a school"
    // ─────────────────────────────────────────────────────────────────────────

    /** Area cells are this many degrees square (~11 km). */
    private const CELL = 0.1;

    /** Geoapify's free plan allows 5 requests a second; one chat message stays under it. */
    private const MAX_AREA_CALLS = 4;

    /**
     * The nearest place of each kind for several listings at once, so the AI
     * finder can rank "homes near a school". Rather than one lookup per listing,
     * it fetches each kind's places once per ~11 km area cell (cached a week)
     * and measures straight-line distance locally.
     *
     * For each listing and kind: ['name', 'distance_m'] for the nearest place,
     * null when there is none within that kind's search radius, and the key is
     * missing when it couldn't be checked (no location, or the lookup failed).
     *
     * @param  array<int, Property>  $properties
     * @param  array<int, string>  $kinds  keys of self::KINDS
     * @return array<int, array<string, array{name: string, distance_m: int}|null>>
     */
    public function nearestTo(array $properties, array $kinds): array
    {
        $key     = config('services.geoapify.api_key');
        $kinds   = array_values(array_intersect(array_unique($kinds), array_keys(self::KINDS)));
        $located = array_filter($properties, fn (Property $p) => $p->lat && $p->lng);
        if (! $key || ! $kinds || ! $located) {
            return [];
        }

        $wanted = [];
        foreach ($located as $p) {
            foreach ($kinds as $kind) {
                [$cellKey, $cell] = $this->cell($p, $kind);
                $wanted[$cellKey] = $cell + ['kind' => $kind];
            }
        }

        $lists   = [];
        $missing = [];
        foreach ($wanted as $cellKey => $cell) {
            $hit = Cache::get($cellKey);
            is_array($hit) ? $lists[$cellKey] = $hit : $missing[$cellKey] = $cell;
        }
        $missing = array_slice($missing, 0, self::MAX_AREA_CALLS, true);

        if ($missing) {
            try {
                $responses = Http::pool(fn (Pool $pool) => array_map(
                    function (string $cellKey) use ($pool, $missing, $key) {
                        $c   = $missing[$cellKey];
                        $pad = self::KINDS[$c['kind']][3] / 111_000;   // the radius, in degrees
                        $w   = $c['lng'] - $pad;
                        $e   = $c['lng'] + self::CELL + $pad;
                        $s   = $c['lat'] - $pad;
                        $n   = $c['lat'] + self::CELL + $pad;

                        return $pool->as($cellKey)->timeout(8)->get(self::BASE . '/v2/places', [
                            'categories' => self::KINDS[$c['kind']][2],
                            'filter'     => "rect:{$w},{$n},{$e},{$s}",
                            'bias'       => 'proximity:' . ($c['lng'] + self::CELL / 2) . ',' . ($c['lat'] + self::CELL / 2),
                            'limit'      => 300,
                            'apiKey'     => $key,
                        ]);
                    },
                    array_keys($missing),
                ));
            } catch (\Throwable $e) {
                Log::warning('Geoapify area places error', ['error' => $e->getMessage()]);
                $responses = [];
            }

            foreach (array_keys($missing) as $cellKey) {
                $res = $responses[$cellKey] ?? null;
                if (! $res instanceof \Illuminate\Http\Client\Response || ! $res->successful()) {
                    continue;   // unknown, not "none nearby"; retried next time
                }
                $places = [];
                foreach ($res->json('features', []) as $f) {
                    $fp = $f['properties'] ?? [];
                    if (isset($fp['lat'], $fp['lon'])) {
                        $places[] = ['name' => $fp['name'] ?? null, 'lat' => (float) $fp['lat'], 'lng' => (float) $fp['lon']];
                    }
                }
                Cache::put($cellKey, $places, now()->addDays(7));
                $lists[$cellKey] = $places;
            }
        }

        $out = [];
        foreach ($located as $p) {
            foreach ($kinds as $kind) {
                [$cellKey] = $this->cell($p, $kind);
                if (! isset($lists[$cellKey])) {
                    continue;
                }
                $best = null;
                foreach ($lists[$cellKey] as $place) {
                    $d = self::metres((float) $p->lat, (float) $p->lng, $place['lat'], $place['lng']);
                    if ($d <= self::KINDS[$kind][3] && ($best === null || $d < $best['distance_m'])) {
                        $best = ['name' => $place['name'] ?: self::singular(self::KINDS[$kind][0]), 'distance_m' => (int) round($d)];
                    }
                }
                $out[$p->id][$kind] = $best;
            }
        }

        return $out;
    }

    /** @return array{0: string, 1: array{lat: float, lng: float}} cache key and south-west corner */
    private function cell(Property $p, string $kind): array
    {
        $y = (int) floor((float) $p->lat / self::CELL);
        $x = (int) floor((float) $p->lng / self::CELL);

        return ["nearest:v1:{$kind}:{$y}:{$x}", ['lat' => $y * self::CELL, 'lng' => $x * self::CELL]];
    }

    /** Great-circle distance in metres. */
    private static function metres(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a    = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 6_371_000 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
