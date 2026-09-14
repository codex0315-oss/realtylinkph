<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Property;
use App\Services\GeocodingService;
use Illuminate\Console\Command;

class GeocodeProperties extends Command
{
    protected $signature = 'properties:geocode {--all : Re-geocode every property, not just those missing coordinates}';

    protected $description = 'Fill in missing lat/lng for properties via Geoapify so they appear on the map.';

    public function handle(GeocodingService $geocoder): int
    {
        $query = Property::query();

        if (! $this->option('all')) {
            $query->where(fn ($q) => $q->whereNull('lat')->orWhereNull('lng'));
        }

        $properties = $query->get();
        $this->info("Geocoding {$properties->count()} propert(y/ies)…");

        $ok = 0;
        foreach ($properties as $property) {
            if (! $property->address) {
                $this->warn("#{$property->id} has no address — skipped.");

                continue;
            }

            $coords = $geocoder->geocode($property->address);

            if ($coords) {
                $property->update(['lat' => $coords['lat'], 'lng' => $coords['lng']]);
                $this->line("#{$property->id} ✓ {$coords['lat']}, {$coords['lng']}  —  {$property->address}");
                $ok++;
            } else {
                $this->warn("#{$property->id} ✗ could not geocode: {$property->address}");
            }

            usleep(250_000); // be gentle on the Geoapify API
        }

        $this->info("Done. {$ok}/{$properties->count()} geocoded.");

        return self::SUCCESS;
    }
}
