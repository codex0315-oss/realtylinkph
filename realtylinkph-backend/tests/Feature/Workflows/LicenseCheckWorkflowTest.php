<?php

declare(strict_types=1);

namespace Tests\Feature\Workflows;

use App\Models\AdminAction;
use App\Models\AgentProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsActors;
use Tests\TestCase;

/**
 * The admin checks a license on PRC or, when PRC is down, on DHSUD's registry,
 * and records what they found. Admin-only evidence behind the decision.
 */
class LicenseCheckWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use BuildsActors;

    private function pending(): AgentProfile
    {
        return AgentProfile::create([
            'user_id' => $this->buyer(['name' => 'Maria Santos'])->id, 'applicant_type' => 'broker', 'prc_number' => '004512', 'status' => 'pending',
        ]);
    }

    public function test_a_dhsud_match_is_saved_with_its_valid_until_date_and_logged(): void
    {
        $p = $this->pending();
        $until = now()->addMonths(2)->toDateString();

        $this->actingAsUser($this->admin(['name' => 'Checking Admin']))
            ->postJson("/api/admin/agent-applications/{$p->id}/license-check", ['result' => 'dhsud_found', 'valid_until' => $until, 'note' => 'PRC down; found on DHSUD list'])
            ->assertOk()
            ->assertJsonPath('data.license_check.result', 'dhsud_found')
            ->assertJsonPath('data.license_check.valid_until', $until)
            ->assertJsonPath('data.license_check.by', 'Checking Admin');

        $this->assertTrue(AdminAction::where('action', 'agent.license_checked')->where('subject_id', $p->id)->exists());

        // The applicant never sees it.
        $this->actingAsUser($p->user->fresh())->getJson('/api/me')->assertOk()
            ->assertJsonMissingPath('data.agent_profile.license_check');
    }

    public function test_a_dhsud_match_needs_a_date_that_has_not_passed(): void
    {
        $p = $this->pending();
        $this->actingAsUser($this->admin());

        $this->postJson("/api/admin/agent-applications/{$p->id}/license-check", ['result' => 'dhsud_found'])
            ->assertStatus(422)->assertJsonValidationErrors('valid_until');
        $this->postJson("/api/admin/agent-applications/{$p->id}/license-check", ['result' => 'dhsud_found', 'valid_until' => now()->subDay()->toDateString()])
            ->assertStatus(422)->assertJsonValidationErrors('valid_until');

        $this->assertNull($p->fresh()->license_check);
    }

    public function test_found_on_prc_and_not_found_need_no_date_and_saving_again_replaces_it(): void
    {
        $p = $this->pending();
        $this->actingAsUser($this->admin());

        $this->postJson("/api/admin/agent-applications/{$p->id}/license-check", ['result' => 'prc_found'])->assertOk();
        $this->postJson("/api/admin/agent-applications/{$p->id}/license-check", ['result' => 'not_found', 'valid_until' => now()->addYear()->toDateString()])
            ->assertOk()->assertJsonPath('data.license_check.result', 'not_found')
            ->assertJsonPath('data.license_check.valid_until', null);   // no date kept for "not found"
    }

    public function test_only_admins_and_only_pending_applications(): void
    {
        $p = $this->pending();
        $this->actingAsUser($this->buyer())->postJson("/api/admin/agent-applications/{$p->id}/license-check", ['result' => 'prc_found'])->assertForbidden();

        $p->update(['status' => 'approved']);
        $this->actingAsUser($this->admin())->postJson("/api/admin/agent-applications/{$p->id}/license-check", ['result' => 'prc_found'])->assertStatus(422);
    }

    public function test_the_dhsud_link_points_at_the_searchable_list(): void
    {
        $links = \App\Support\AgentCredentialReference::links('broker');
        $this->assertContains('https://dhsud.gov.ph/broker-agent/list-of-brokers-salespersons/', array_column($links, 'url'));
    }
}
