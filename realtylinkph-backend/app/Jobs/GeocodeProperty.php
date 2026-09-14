<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Property;
use App\Services\GeocodingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Geocode a property's address to lat/lng in the background, so saving a listing
 * returns immediately instead of waiting on the external geocoding call.
 */
class GeocodeProperty implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $propertyId) {}

    public function handle(GeocodingService $geocoder): void
    {
        $property = Property::find($this->propertyId);
        if (! $property || ! $property->address) {
            return;
        }

        $coords = $geocoder->geocode($property->address);
        if ($coords) {
            $property->update(['lat' => $coords['lat'], 'lng' => $coords['lng']]);
        }
    }
}
