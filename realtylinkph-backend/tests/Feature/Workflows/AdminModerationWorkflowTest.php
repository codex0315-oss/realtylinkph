<?php

declare(strict_types=1);

namespace Tests\Feature\Workflows;

use App\Models\AdminAction;
use App\Models\AgentReview;
use App\Models\Appointment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsActors;
use Tests\TestCase;

/**
 * Features 14, 15 & 17 — admin takes a listing down with a reason (agent is
 * told, public can no longer see it), hides a review, deletes a user, and
 * every one of those lands in the audit trail with who did it and why.
 */
class AdminModerationWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use BuildsActors;

    public function test_unpublish_with_reason_hides_the_listing_and_tells_the_agent(): void
    {
        $admin   = $this->admin(['name' => 'Site Admin']);
        $agent   = $this->agent();
        $listing = $this->listing($agent);

        $this->getJson("/api/properties/{$listing->id}")->assertOk();

        // A reason is mandatory and must be usable.
        $this->actingAsUser($admin)->postJson("/api/admin/properties/{$listing->id}/unpublish", ['reason' => 'bad'])
            ->assertStatus(422);

        $this->actingAsUser($admin)->postJson("/api/admin/properties/{$listing->id}/unpublish", [
            'reason' => 'Price is far below market and the photos are from a different building.',
        ])->assertOk()->assertJsonPath('data.status', 'draft');

        // Gone from the public side, still visible to its owner as a draft.
        $this->asGuest()->getJson("/api/properties/{$listing->id}")->assertNotFound();
        $this->actingAsUser($agent)->getJson("/api/properties/{$listing->id}")->assertOk()
            ->assertJsonPath('data.unpublish_reason', 'Price is far below market and the photos are from a different building.');

        $this->assertDatabaseHas('notifications', ['notifiable_id' => $agent->id, 'type' => 'listing_unpublished']);

        $row = AdminAction::where('action', 'listing.unpublished')->firstOrFail();
        $this->assertSame('Site Admin', $row->admin_name);
        $this->assertSame($listing->title, $row->subject_label);
        $this->assertStringContainsString('below market', $row->details['reason']);
    }

    public function test_hidden_reviews_disappear_from_the_public_profile(): void
    {
        $admin = $this->admin();
        $agent = $this->agent();
        $buyer = $this->buyer();
        $appt  = Appointment::create([
            'property_id' => $this->listing($agent)->id, 'buyer_id' => $buyer->id, 'agent_id' => $agent->id,
            'preferred_datetime' => now()->subDay(), 'status' => 'completed',
        ]);
        $review = AgentReview::create([
            'appointment_id' => $appt->id, 'buyer_id' => $buyer->id, 'agent_id' => $agent->id,
            'rating' => 1, 'review_text' => 'Contains a phone number and an insult.', 'is_visible' => true,
        ]);

        $this->assertCount(1, $this->getJson("/api/agents/{$agent->id}/reviews")->json('data'));

        $this->actingAsUser($agent)->postJson("/api/admin/reviews/{$review->id}/toggle-visibility")->assertForbidden();
        $this->actingAsUser($admin)->postJson("/api/admin/reviews/{$review->id}/toggle-visibility")
            ->assertOk()->assertJsonPath('data.is_visible', false);

        $this->assertCount(0, $this->asGuest()->getJson("/api/agents/{$agent->id}/reviews")->json('data'));
        $this->assertDatabaseHas('admin_actions', ['action' => 'review.hidden', 'subject_id' => $review->id]);

        // Restoring is logged as its own action.
        $this->actingAsUser($admin)->postJson("/api/admin/reviews/{$review->id}/toggle-visibility")->assertOk();
        $this->assertDatabaseHas('admin_actions', ['action' => 'review.shown', 'subject_id' => $review->id]);
    }

    public function test_user_deletion_guards_and_audit(): void
    {
        $admin  = $this->admin();
        $buyer  = $this->buyer(['email' => 'gone@example.com']);

        // Cannot delete yourself, nor the last admin.
        $this->actingAsUser($admin)->deleteJson("/api/admin/users/{$admin->id}")->assertStatus(422);

        $this->actingAsUser($admin)->deleteJson("/api/admin/users/{$buyer->id}")->assertOk();
        $this->assertDatabaseMissing('users', ['id' => $buyer->id]);

        // The audit row survives the user it describes.
        $row = AdminAction::where('action', 'user.deleted')->firstOrFail();
        $this->assertSame($buyer->id, $row->subject_id);
        $this->assertStringContainsString('gone@example.com', $row->subject_label);

        // A second admin can be created, then the first can be removed by them.
        $this->actingAsUser($admin)->postJson('/api/admin/admins', [
            'name' => 'Second Admin', 'email' => 'second@example.com', 'password' => 'Secret123',
        ])->assertCreated();
        $second = User::where('email', 'second@example.com')->firstOrFail();
        $this->assertTrue($second->isAdmin());
        $this->assertDatabaseHas('admin_actions', ['action' => 'admin.created', 'subject_id' => $second->id]);

        $this->actingAsUser($second)->deleteJson("/api/admin/users/{$admin->id}")->assertOk();

        // Activity feed: newest first, searchable.
        $feed = $this->actingAsUser($second)->getJson('/api/admin/actions?search=gone@example.com')->assertOk();
        $this->assertCount(1, $feed->json('data'));
        $this->assertSame('Deleted user', $feed->json('data.0.label'));
    }
}
