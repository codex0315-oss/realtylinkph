<?php

declare(strict_types=1);

namespace Tests\Feature\Workflows;

use App\Models\AccountSignal;
use App\Models\AdminAction;
use App\Models\AgentReview;
use App\Models\AgentSanction;
use App\Models\Appointment;
use App\Models\User;
use App\Support\AccountSignals;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsActors;
use Tests\TestCase;

/**
 * An agent with poor ratings makes fake buyer accounts to review themselves.
 * Three layers: prevention (who may review), detection (flags for an admin),
 * and sanctions (warning, suspension, revocation).
 */
class ReviewIntegrityWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use BuildsActors;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    private function oldBuyer(array $attrs = []): User
    {
        return $this->buyer(['created_at' => now()->subDays(30), ...$attrs]);
    }

    /** A viewing that took place, so the buyer has a basis to review. */
    private function viewed(User $buyer, User $agent, string $status = 'completed'): Appointment
    {
        return Appointment::create([
            'property_id' => $this->listing($agent)->id, 'buyer_id' => $buyer->id, 'agent_id' => $agent->id,
            'preferred_datetime' => now()->subDays(2), 'status' => $status, 'late_cancellation' => false,
        ]);
    }

    private function signal(User $user, string $ip, string $ua = 'Chrome'): void
    {
        AccountSignal::create(['user_id' => $user->id, 'network_hash' => AccountSignals::hash('net|' . $ip),
            'device_hash' => AccountSignals::hash('dev|' . $ip . '|' . $ua), 'event' => 'login', 'created_at' => now()]);
    }

    // ── Prevention ──────────────────────────────────────────────────────────

    public function test_an_unverified_email_cannot_review(): void
    {
        $agent = $this->agent();
        $buyer = $this->oldBuyer(['email_verified_at' => null]);
        $this->viewed($buyer, $agent);

        $this->actingAsUser($buyer)->getJson("/api/agents/{$agent->id}/reviewable")
            ->assertJsonPath('data.eligible', false)->assertJsonPath('data.blocked', 'verify_email');
        $this->postJson('/api/reviews', ['agent_id' => $agent->id, 'rating' => 5])
            ->assertStatus(422)->assertJsonPath('message', 'Please verify your email address before leaving a review.');
    }

    public function test_a_brand_new_account_waits_three_days_before_reviewing(): void
    {
        $agent = $this->agent();
        $buyer = $this->buyer(['created_at' => now()->subDay()]);
        $this->viewed($buyer, $agent);

        $this->actingAsUser($buyer)->getJson("/api/agents/{$agent->id}/reviewable")->assertJsonPath('data.blocked', 'account_age');
        $this->postJson('/api/reviews', ['agent_id' => $agent->id, 'rating' => 5])->assertStatus(422);

        $this->travel(3)->days();
        $this->actingAsUser($buyer->fresh())->postJson('/api/reviews', ['agent_id' => $agent->id, 'rating' => 5])->assertCreated();
    }

    // ── Detection ───────────────────────────────────────────────────────────

    public function test_a_review_from_the_agents_own_device_is_flagged_and_an_admin_can_clear_it(): void
    {
        $agent = $this->agent();
        $fake  = $this->oldBuyer();
        $this->signal($agent, '203.0.113.7');
        $this->signal($fake, '203.0.113.7');
        $this->viewed($fake, $agent);

        $id = $this->actingAsUser($fake)->postJson('/api/reviews', ['agent_id' => $agent->id, 'rating' => 5])->assertCreated()->json('data.id');
        $review = AgentReview::find($id);
        $this->assertNotNull($review->flagged_at);
        $this->assertContains('shared_device', $review->flag_reasons);
        $this->assertTrue($review->is_visible, 'flagging never hides anything by itself');

        $admin = $this->admin();
        $this->actingAsUser($admin)->getJson('/api/admin/reviews?flagged=1')->assertOk()
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.flag.reasons.0.code', 'shared_device');
        $this->getJson('/api/admin/reviews/stats')->assertJsonPath('data.counts.flagged', 1);

        // The buyer doesn't see why it was flagged.
        $this->actingAsUser($fake)->getJson('/api/me/reviews')->assertJsonMissingPath('data.0.flag');

        $this->actingAsUser($admin)->postJson("/api/admin/reviews/{$id}/clear-flag")->assertOk();
        $this->getJson('/api/admin/reviews?flagged=1')->assertJsonCount(0, 'data');
        $this->assertTrue(AdminAction::where('action', 'review.flag_cleared')->exists());
    }

    public function test_a_new_account_that_only_ever_dealt_with_this_agent_is_flagged(): void
    {
        $agent = $this->agent();
        $fake  = $this->buyer(['created_at' => now()->subDays(5)]);
        $this->viewed($fake, $agent);

        $id = $this->actingAsUser($fake)->postJson('/api/reviews', ['agent_id' => $agent->id, 'rating' => 5])->assertCreated()->json('data.id');
        $this->assertContains('new_single_agent', AgentReview::find($id)->flag_reasons);
    }

    public function test_an_honest_review_is_not_flagged(): void
    {
        $agent = $this->agent();
        $other = $this->agent();
        $buyer = $this->oldBuyer();
        $this->signal($agent, '198.51.100.1');
        $this->signal($buyer, '198.51.100.99');
        $this->viewed($buyer, $other);
        $this->viewed($buyer, $agent);

        $id = $this->actingAsUser($buyer)->postJson('/api/reviews', ['agent_id' => $agent->id, 'rating' => 4])->assertCreated()->json('data.id');
        $this->assertNull(AgentReview::find($id)->flagged_at);
    }

    public function test_a_sudden_burst_of_praise_is_flagged(): void
    {
        $agent = $this->agent();
        $ids = [];
        foreach (range(1, 3) as $i) {
            $b = $this->oldBuyer();
            $this->viewed($b, $this->agent());   // they have other history, so only the burst stands out
            $this->viewed($b, $agent);
            $ids[] = $this->actingAsUser($b)->postJson('/api/reviews', ['agent_id' => $agent->id, 'rating' => 5])->assertCreated()->json('data.id');
        }
        $this->assertNull(AgentReview::find($ids[0])->flagged_at);
        $this->assertContains('burst', AgentReview::find($ids[2])->flag_reasons);
    }

    // ── Sanctions ───────────────────────────────────────────────────────────

    public function test_warn_then_suspend_then_lift(): void
    {
        $agent = $this->agent();
        $listing = $this->listing($agent);
        $admin = $this->admin();

        $this->actingAsUser($admin)->postJson("/api/admin/users/{$agent->id}/agent-sanctions", ['type' => 'warning', 'reason' => 'Reviews from accounts on your own device.'])->assertOk();
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $agent->id)->where('type', 'agent_warning')->count());

        $this->postJson("/api/admin/users/{$agent->id}/agent-sanctions", ['type' => 'suspension', 'days' => 7, 'reason' => 'Repeated fake reviews from linked accounts.'])
            ->assertOk()->assertJsonPath('data.history.0.type', 'suspension');
        $listing->refresh();
        $this->assertSame('draft', $listing->status);
        $this->assertStringContainsString('Listings suspended until', $listing->unpublish_reason);

        // While suspended: no publishing, no new listings; the agent can see until when.
        $this->actingAsUser($agent->fresh())->postJson("/api/properties/{$listing->id}/publish")->assertStatus(403);
        $this->getJson('/api/me')->assertJsonPath('data.listing_suspended_until', fn ($v) => $v !== null);

        $this->actingAsUser($admin)->postJson("/api/admin/users/{$agent->id}/agent-sanctions", ['type' => 'lift', 'reason' => 'Agent explained; reviews removed.'])->assertOk();
        $this->assertNull($agent->fresh()->listingSuspendedUntil());
        foreach (['agent.warned', 'agent.suspended', 'agent.suspension_lifted'] as $a) {
            $this->assertTrue(AdminAction::where('action', $a)->exists(), $a);
        }
    }

    public function test_a_suspension_ends_by_itself(): void
    {
        $agent = $this->agent();
        $this->actingAsUser($this->admin())->postJson("/api/admin/users/{$agent->id}/agent-sanctions", ['type' => 'suspension', 'days' => 7, 'reason' => 'Fake reviews from linked accounts.'])->assertOk();
        $this->travel(8)->days();
        $this->assertNull($agent->fresh()->listingSuspendedUntil());
    }

    public function test_revoking_verification_makes_them_a_buyer_for_thirty_days(): void
    {
        $agent = $this->agent();
        $listing = $this->listing($agent);

        $this->actingAsUser($this->admin())->postJson("/api/admin/users/{$agent->id}/agent-sanctions", ['type' => 'revocation', 'reason' => 'Ran several fake buyer accounts.'])
            ->assertOk()->assertJsonPath('data.revoked', true);

        $agent->refresh();
        $this->assertSame('buyer', $agent->role_type);
        $this->assertSame('rejected', $agent->agentProfile->status);
        $this->assertSame('draft', $listing->fresh()->status);
        $this->assertTrue($agent->agentProfile->reapplyAt()->gt(now()->addDays(29)));
        $this->assertSame(1, AgentSanction::where('user_id', $agent->id)->where('type', 'revocation')->count());
    }

    public function test_only_admins_can_sanction_and_a_reason_is_required(): void
    {
        $agent = $this->agent();
        $this->actingAsUser($this->buyer())->postJson("/api/admin/users/{$agent->id}/agent-sanctions", ['type' => 'warning', 'reason' => 'Not my business at all.'])->assertForbidden();
        $this->actingAsUser($this->admin())->postJson("/api/admin/users/{$agent->id}/agent-sanctions", ['type' => 'warning', 'reason' => 'short'])->assertStatus(422);
        $this->postJson("/api/admin/users/{$agent->id}/agent-sanctions", ['type' => 'suspension', 'days' => 3, 'reason' => 'Not a length admins can choose.'])->assertStatus(422);
    }
}
