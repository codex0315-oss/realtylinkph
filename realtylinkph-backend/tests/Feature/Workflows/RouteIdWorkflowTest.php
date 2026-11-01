<?php

declare(strict_types=1);

namespace Tests\Feature\Workflows;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsActors;
use Tests\TestCase;

/** A mistyped ID in a link is a plain "not found", never a server error (QA defect D2). */
class RouteIdWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use BuildsActors;

    public function test_non_numeric_ids_are_not_found(): void
    {
        foreach (['/api/properties/abc', '/api/agents/abc', '/api/properties/abc/nearby', '/api/agents/1x/reviews'] as $url) {
            $this->getJson($url)->assertNotFound();
        }

        $this->actingAsUser($this->buyer());
        $this->getJson('/api/appointments/abc')->assertNotFound();
        $this->getJson('/api/conversations/abc')->assertNotFound();

        // Static routes next to {property} still work.
        $this->getJson('/api/properties/featured')->assertOk();
        $this->getJson('/api/properties/cities')->assertOk();
    }
}
