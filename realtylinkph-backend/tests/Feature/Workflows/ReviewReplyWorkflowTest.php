<?php

declare(strict_types=1);

namespace Tests\Feature\Workflows;

use App\Models\AgentReview;
use App\Models\Appointment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsActors;
use Tests\TestCase;

/**
 * An agent answers a review publicly: only the reviewed agent can, the buyer
 * is told once, the reply shows on the public profile, and the review itself
 * never turns "edited" because of it.
 */
class ReviewReplyWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use BuildsActors;

    public function test_the_reviewed_agent_replies_and_the_buyer_sees_it(): void
    {
        $agent = $this->agent();
        $other = $this->agent();
        $buyer = $this->buyer();
        $appt  = Appointment::create([
            'property_id' => $this->listing($agent)->id, 'buyer_id' => $buyer->id, 'agent_id' => $agent->id,
            'preferred_datetime' => now()->subDay(), 'status' => 'completed',
        ]);
        $review = AgentReview::create([
            'appointment_id' => $appt->id, 'buyer_id' => $buyer->id, 'agent_id' => $agent->id,
            'rating' => 4, 'review_text' => 'Helpful, a little late.', 'is_visible' => true,
        ]);

        // Only the reviewed agent; not another agent, not the buyer.
        $this->actingAsUser($other)->putJson("/api/reviews/{$review->id}/reply", ['reply' => 'Thanks!'])->assertForbidden();
        $this->actingAsUser($buyer)->putJson("/api/reviews/{$review->id}/reply", ['reply' => 'Thanks!'])->assertForbidden();
        $this->actingAsUser($agent)->putJson("/api/reviews/{$review->id}/reply", ['reply' => ''])->assertStatus(422);

        $this->actingAsUser($agent)->putJson("/api/reviews/{$review->id}/reply", ['reply' => 'Thank you! Sorry for the traffic delay.'])
            ->assertOk()
            ->assertJsonPath('data.agent_reply', 'Thank you! Sorry for the traffic delay.')
            ->assertJsonPath('data.is_edited', false);

        // Editing the reply doesn't notify again.
        $this->actingAsUser($agent)->putJson("/api/reviews/{$review->id}/reply", ['reply' => 'Thank you, see you again!'])->assertOk();
        $this->assertSame(1, $buyer->notifications()->where('type', 'review_replied')->count());

        // Public on the agent's profile.
        $this->asGuest()->getJson("/api/agents/{$agent->id}/reviews")
            ->assertJsonPath('data.0.agent_reply', 'Thank you, see you again!');

        $this->actingAsUser($agent)->deleteJson("/api/reviews/{$review->id}/reply")
            ->assertOk()->assertJsonPath('data.agent_reply', null);
    }
}
