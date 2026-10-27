<?php

declare(strict_types=1);

namespace Tests\Feature\Workflows;

use App\Models\AgentReview;
use App\Models\Appointment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsActors;
use Tests\TestCase;

/**
 * The buyer's Reviews page: only their own reviews, newest first, with the
 * agent and the viewed listing (title + photo) attached.
 */
class BuyerReviewsWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use BuildsActors;

    public function test_a_buyer_sees_only_their_own_reviews_with_agent_and_listing(): void
    {
        $agent   = $this->agent();
        $other   = $this->agent();
        $buyer   = $this->buyer();
        $someone = $this->buyer();
        $home    = $this->listing($agent, ['title' => 'Sunny Condo']);
        $home->photos()->create(['url' => 'https://example.test/a.jpg', 'thumb_url' => 'https://example.test/a-t.jpg', 'is_360' => false, 'sort_order' => 0]);

        $viewing = Appointment::create([
            'property_id' => $home->id, 'buyer_id' => $buyer->id, 'agent_id' => $agent->id,
            'preferred_datetime' => now()->subDays(3), 'status' => 'completed',
        ]);

        $old = AgentReview::create(['agent_id' => $other->id, 'buyer_id' => $buyer->id, 'rating' => 3, 'review_text' => 'Okay.', 'is_visible' => true]);
        $old->forceFill(['created_at' => now()->subWeek()])->save();
        AgentReview::create(['agent_id' => $agent->id, 'buyer_id' => $buyer->id, 'appointment_id' => $viewing->id, 'rating' => 5, 'review_text' => 'Great.', 'is_visible' => true]);
        AgentReview::create(['agent_id' => $agent->id, 'buyer_id' => $someone->id, 'rating' => 1, 'is_visible' => true]);

        Sanctum::actingAs($buyer);
        $res = $this->getJson('/api/me/reviews')->assertOk();

        $res->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.rating', 5)                          // newest first
            ->assertJsonPath('data.0.agent.id', $agent->id)
            ->assertJsonPath('data.0.property_title', 'Sunny Condo')
            ->assertJsonPath('data.0.property_photo', 'https://example.test/a-t.jpg')
            ->assertJsonPath('data.1.rating', 3);
    }

    public function test_guests_cannot_list_reviews(): void
    {
        $this->getJson('/api/me/reviews')->assertUnauthorized();
    }
}
