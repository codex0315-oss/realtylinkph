<?php

declare(strict_types=1);

namespace Tests\Feature\Workflows;

use App\Models\AdminAction;
use App\Models\AgentProfile;
use App\Notifications\AgentApplicationHoldNotice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsActors;
use Tests\TestCase;

/**
 * When PRC's lookup is down, an admin puts applications on hold — one, some,
 * or all — and each applicant is told in-app and by email. "Resume review"
 * closes the loop with a second notice.
 */
class AgentApplicationHoldWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use BuildsActors;

    private const MESSAGE = "PRC's license verification website is currently unavailable. Your application is safe.";

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    private function pending(string $name): AgentProfile
    {
        return AgentProfile::create([
            'user_id' => $this->buyer(['name' => $name])->id, 'applicant_type' => 'broker', 'prc_number' => (string) random_int(100000, 999999), 'status' => 'pending',
        ]);
    }

    private function inApp(AgentProfile $p, string $type): int
    {
        return DB::table('notifications')->where('notifiable_id', $p->user_id)->where('type', $type)->count();
    }

    public function test_one_application_goes_on_hold_and_the_applicant_is_told_both_ways(): void
    {
        $p = $this->pending('Juan Dela Cruz');

        $this->actingAsUser($this->admin())->postJson("/api/admin/agent-applications/{$p->id}/hold", [
            'reason' => 'prc_unavailable', 'message' => self::MESSAGE,
        ])->assertOk()->assertJsonPath('data.on_hold', true);

        $p->refresh();
        $this->assertSame('pending', $p->status, 'a hold is a pause, not a decision');
        $this->assertSame('prc_unavailable', $p->hold_reason);
        Notification::assertSentTo($p->user, AgentApplicationHoldNotice::class, fn ($n) => $n->kind === 'held' && $n->message === self::MESSAGE);
        $this->assertSame(1, $this->inApp($p, 'agent_application_on_hold'));
        $this->assertTrue(AdminAction::where('action', 'agent.held')->where('subject_id', $p->id)->exists());

        // The applicant sees it on their own application.
        $this->actingAsUser($p->user->fresh())->getJson('/api/me')->assertOk()
            ->assertJsonPath('data.agent_profile.on_hold', true)
            ->assertJsonPath('data.agent_profile.hold_message', self::MESSAGE);
    }

    public function test_select_all_holds_every_pending_one_and_skips_those_already_on_hold(): void
    {
        $admin = $this->admin();
        $a = $this->pending('Ana');
        $b = $this->pending('Ben');
        $c = $this->pending('Carlo');
        $approved = $this->agent();   // approved: never touched

        $this->actingAsUser($admin)->postJson("/api/admin/agent-applications/{$a->id}/hold", ['reason' => 'prc_unavailable', 'message' => self::MESSAGE])->assertOk();

        $this->postJson('/api/admin/agent-applications/hold', ['all' => true, 'reason' => 'prc_unavailable', 'message' => self::MESSAGE])
            ->assertOk()->assertJsonPath('data.held', 2)->assertJsonPath('data.skipped', 1);

        // Ana got one notice, not two.
        Notification::assertSentToTimes($a->user, AgentApplicationHoldNotice::class, 1);
        foreach ([$b, $c] as $p) {
            $this->assertNotNull($p->fresh()->held_at);
        }
        $this->assertNull($approved->agentProfile->fresh()->held_at);

        $this->getJson('/api/admin/applications')->assertJsonPath('data.counts.on_hold', 3);
    }

    public function test_hold_the_ones_picked_only(): void
    {
        $a = $this->pending('Ana');
        $b = $this->pending('Ben');

        $this->actingAsUser($this->admin())->postJson('/api/admin/agent-applications/hold', ['ids' => [$b->id], 'reason' => 'documents', 'message' => 'We need a closer look at your documents.'])
            ->assertOk()->assertJsonPath('data.held', 1);

        $this->assertNull($a->fresh()->held_at);
        $this->assertSame('documents', $b->fresh()->hold_reason);
    }

    public function test_resume_review_tells_each_applicant_and_clears_the_hold(): void
    {
        $admin = $this->admin();
        $a = $this->pending('Ana');
        $b = $this->pending('Ben');
        $this->actingAsUser($admin)->postJson('/api/admin/agent-applications/hold', ['all' => true, 'reason' => 'prc_unavailable', 'message' => self::MESSAGE])->assertOk();

        $this->postJson('/api/admin/agent-applications/resume', ['all' => true])
            ->assertOk()->assertJsonPath('data.resumed', 2);

        foreach ([$a, $b] as $p) {
            $p->refresh();
            $this->assertNull($p->held_at);
            $this->assertNull($p->hold_message);
            Notification::assertSentTo($p->user, AgentApplicationHoldNotice::class, fn ($n) => $n->kind === 'resumed');
            $this->assertSame(1, $this->inApp($p, 'agent_application_resumed'));
        }
        $this->assertSame(2, AdminAction::where('action', 'agent.resumed')->count());
    }

    public function test_approving_a_held_application_clears_the_hold(): void
    {
        $p = $this->pending('Ana');
        $this->actingAsUser($this->admin())->postJson("/api/admin/agent-applications/{$p->id}/hold", ['reason' => 'prc_unavailable', 'message' => self::MESSAGE])->assertOk();

        $this->postJson("/api/admin/agent-applications/{$p->id}/review", ['action' => 'approve'])->assertOk();

        $p->refresh();
        $this->assertSame('approved', $p->status);
        $this->assertNull($p->held_at);
    }

    public function test_only_admins_can_hold_and_a_message_is_required(): void
    {
        $p = $this->pending('Ana');

        $this->actingAsUser($this->buyer())->postJson('/api/admin/agent-applications/hold', ['all' => true, 'reason' => 'prc_unavailable', 'message' => self::MESSAGE])->assertForbidden();

        $this->actingAsUser($this->admin())->postJson("/api/admin/agent-applications/{$p->id}/hold", ['reason' => 'prc_unavailable', 'message' => ''])->assertStatus(422);
        $this->postJson("/api/admin/agent-applications/{$p->id}/hold", ['reason' => 'made_up', 'message' => self::MESSAGE])->assertStatus(422);
        $this->assertNull($p->fresh()->held_at);
    }
}
