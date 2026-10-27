<?php

declare(strict_types=1);

namespace Tests\Feature\Workflows;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsActors;
use Tests\TestCase;

/** Buyers and agents rate RealtyLink PH (1–5, optional comment); admins see the results. */
class PlatformRatingWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use BuildsActors;

    public function test_rate_change_and_admin_summary(): void
    {
        $buyer = $this->buyer(['name' => 'Bea Buyer']);
        $this->actingAsUser($buyer);
        $this->getJson('/api/platform-rating')->assertOk()->assertJsonPath('data', null);
        $this->putJson('/api/platform-rating', ['rating' => 6])->assertStatus(422);
        $this->putJson('/api/platform-rating', ['rating' => 3])->assertOk();
        // Changing it updates the one rating rather than adding another.
        $this->putJson('/api/platform-rating', ['rating' => 5, 'comment' => 'Found my condo in a week!'])->assertOk()
            ->assertJsonPath('data.rating', 5);

        $this->actingAsUser($this->agent());
        $this->putJson('/api/platform-rating', ['rating' => 4, 'comment' => '   '])->assertOk()->assertJsonPath('data.comment', null);

        $this->actingAsUser($this->admin());
        $this->putJson('/api/platform-rating', ['rating' => 5])->assertForbidden();
        $this->getJson('/api/admin/platform-ratings')->assertOk()
            ->assertJsonPath('data.count', 2)
            ->assertJsonPath('data.average', 4.5)
            ->assertJsonPath('data.agents.average', 4)
            ->assertJsonPath('data.buyers.average', 5)
            ->assertJsonCount(1, 'data.latest')
            ->assertJsonPath('data.latest.0.name', 'Bea Buyer');

        $this->actingAsUser($buyer)->getJson('/api/admin/platform-ratings')->assertForbidden();
    }
}
