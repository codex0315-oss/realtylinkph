<?php

declare(strict_types=1);

namespace Tests\Feature\Workflows;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsActors;
use Tests\TestCase;

/** Browse map: "Search this area" limits results to the visible box; a pin placed by the agent is kept. */
class MapAreaSearchWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use BuildsActors;

    public function test_search_this_area_returns_only_listings_inside_the_map(): void
    {
        $agent = $this->agent();
        $itPark  = $this->listing($agent, ['title' => 'IT Park condo', 'lat' => 10.3301, 'lng' => 123.9056]);
        $mandaue = $this->listing($agent, ['title' => 'Mandaue house', 'lat' => 10.3236, 'lng' => 123.9223]);
        $talisay = $this->listing($agent, ['title' => 'Talisay lot', 'lat' => 10.2447, 'lng' => 123.8494]);

        // A box around IT Park and Mandaue only.
        $ids = collect($this->getJson('/api/properties?bounds=10.30,123.88,10.35,123.94')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$itPark->id, $mandaue->id], $ids);
        $this->assertNotContains($talisay->id, $ids);

        // A broken box is ignored rather than returning nothing.
        $this->assertCount(3, $this->getJson('/api/properties?bounds=10.35,123.94,10.30,123.88')->json('data'));
        $this->assertCount(3, $this->getJson('/api/properties?bounds=nope')->json('data'));
    }

    public function test_a_pin_placed_by_the_agent_is_saved_as_given(): void
    {
        \Illuminate\Support\Facades\Bus::fake();
        $agent = $this->agent();
        $p = $this->listing($agent, ['lat' => 10.3, 'lng' => 123.9]);

        $this->actingAsUser($agent)->putJson("/api/properties/{$p->id}", ['address' => 'Lot 4, Banilad, Cebu City', 'lat' => 10.3478123, 'lng' => 123.9135456])->assertOk();

        $p->refresh();
        $this->assertEqualsWithDelta(10.3478123, (float) $p->lat, 0.000001);
        $this->assertEqualsWithDelta(123.9135456, (float) $p->lng, 0.000001);
        \Illuminate\Support\Facades\Bus::assertNotDispatched(\App\Jobs\GeocodeProperty::class);
    }
}
