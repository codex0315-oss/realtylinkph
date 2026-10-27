<?php

declare(strict_types=1);

namespace Tests\Feature\Workflows;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsActors;
use Tests\TestCase;

/**
 * "What's nearby" and "how far from me" on a listing. Geoapify is faked: the
 * places come back as two pharmacies (one unnamed), routes as fixed times.
 */
class NearbyWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use BuildsActors;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.geoapify.api_key' => 'test-key']);

        Http::fake([
            'api.geoapify.com/v2/places*' => function ($request) {
                if (! str_contains($request->url(), 'healthcare.pharmacy')) {
                    return Http::response(['features' => []]);
                }

                return Http::response(['features' => [
                    ['properties' => ['lat' => 10.30, 'lon' => 123.90, 'distance' => 300, 'address_line1' => 'Colon St']],
                    ['properties' => ['name' => 'La Nueva Pharmacy', 'lat' => 10.31, 'lon' => 123.91, 'distance' => 400, 'address_line2' => 'Cebu City']],
                ]]);
            },
            'api.geoapify.com/v1/routematrix*' => fn ($request) => Http::response(['sources_to_targets' => [[
                ['time' => $request['mode'] === 'walk' ? 420 : 120, 'distance' => 450],
                ['time' => $request['mode'] === 'walk' ? 600 : 180, 'distance' => 520],
            ]]]),
            'api.geoapify.com/v1/routing*' => Http::response(['features' => [['properties' => ['time' => 1500, 'distance' => 12340]]]]),
        ]);
    }

    public function test_nearby_lists_named_places_first_with_drive_and_walk_minutes(): void
    {
        $listing = $this->listing($this->agent(), ['lat' => 10.2935, 'lng' => 123.9018]);

        $res = $this->getJson("/api/properties/{$listing->id}/nearby")->assertOk();

        $res->assertJsonPath('data.available', true)
            ->assertJsonPath('data.kinds.0.key', 'pharmacy')
            ->assertJsonPath('data.kinds.0.items.0.name', 'La Nueva Pharmacy')
            ->assertJsonPath('data.kinds.0.items.1.name', 'Pharmacy')          // unnamed → labelled by kind
            ->assertJsonPath('data.kinds.0.items.0.drive_min', 2)
            ->assertJsonPath('data.kinds.0.items.0.walk_min', 7);

        // Cached: a second visit makes no new Geoapify calls.
        $calls = count(Http::recorded());
        $this->getJson("/api/properties/{$listing->id}/nearby")->assertOk();
        $this->assertSame($calls, count(Http::recorded()));
    }

    public function test_travel_time_from_the_visitors_location(): void
    {
        $listing = $this->listing($this->agent(), ['lat' => 10.2935, 'lng' => 123.9018]);

        $this->getJson("/api/properties/{$listing->id}/travel-time?lat=10.3157&lng=123.8854")
            ->assertOk()
            ->assertJsonPath('data.drive_min', 25)
            ->assertJsonPath('data.distance_km', 12.3);

        $this->getJson("/api/properties/{$listing->id}/travel-time?lat=200&lng=123")->assertStatus(422);
    }

    public function test_drafts_and_listings_without_a_location_reveal_nothing(): void
    {
        $agent = $this->agent();
        $draft = $this->listing($agent, ['lat' => 10.29, 'lng' => 123.90, 'status' => 'draft']);
        $this->getJson("/api/properties/{$draft->id}/nearby")->assertNotFound();
        $this->getJson("/api/properties/{$draft->id}/travel-time?lat=10.3&lng=123.9")->assertNotFound();

        $noCoords = $this->listing($agent, ['lat' => null, 'lng' => null]);
        $this->getJson("/api/properties/{$noCoords->id}/nearby")
            ->assertOk()
            ->assertJsonPath('data.available', false);
    }
}
