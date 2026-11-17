<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Property;
use App\Services\ListingIntegrityService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** RealtyLink AI checks a published listing in the background, so saving stays instant. */
class ScanListing implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(public int $propertyId)
    {
    }

    public function handle(ListingIntegrityService $integrity): void
    {
        $property = Property::find($this->propertyId);
        // Only listings buyers can see (or that RealtyLink AI paused) are checked.
        if ($property && ($property->status === 'published' || $integrity->isPaused($property))) {
            $integrity->scan($property);
        }
    }
}
