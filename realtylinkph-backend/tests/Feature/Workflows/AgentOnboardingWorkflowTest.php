<?php

declare(strict_types=1);

namespace Tests\Feature\Workflows;

use App\Jobs\AssessAgentApplication;
use App\Models\AdminAction;
use App\Notifications\AgentApplicationApproved;
use App\Notifications\AgentApplicationRejected;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsActors;
use Tests\TestCase;

/**
 * Features 2 & 16 — a buyer applies to become an agent, documents land on
 * the PRIVATE disk, the AI pre-check is queued, an admin approves, and the
 * new agent can use agent-only routes. Plus the audit row.
 */
class AgentOnboardingWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use BuildsActors;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config(['filesystems.uploads' => 'public']);
        $this->useScratchDocumentsDisk();
    }

    public function test_broker_application_is_stored_privately_and_approved_by_an_admin(): void
    {
        Queue::fake();
        Notification::fake();

        $applicant = $this->buyer(['name' => 'Ramon Cruz']);

        // Not an agent yet: agent-only routes are refused.
        $this->actingAsUser($applicant)->postJson('/api/properties', [])->assertForbidden();

        $submit = $this->actingAsUser($applicant)->post('/api/agent/verify', [
            'applicant_type' => 'broker',
            'full_name'      => 'Ramon Cruz',
            'mobile'         => '09171234567',
            'prc_number'     => '0012345',
            'face_image'     => UploadedFile::fake()->image('selfie.jpg', 640, 480),
            'license_doc'    => UploadedFile::fake()->image('license.jpg', 1200, 800),
        ]);
        $submit->assertCreated()->assertJsonPath('data.status', 'pending');

        // The applicant never sees the AI assessment fields.
        $submit->assertJsonMissingPath('data.ai_comment');

        $profile = $applicant->fresh()->agentProfile;
        $this->assertNotNull($profile->license_doc);
        Storage::disk('local')->assertExists($profile->license_doc);
        Storage::disk('local')->assertExists($profile->face_image);
        Storage::disk('public')->assertMissing($profile->license_doc);   // never on the public disk

        Queue::assertPushed(AssessAgentApplication::class);

        // Admin sees the application with SIGNED document links, not public URLs.
        $admin = $this->admin();
        $list  = $this->actingAsUser($admin)->getJson('/api/admin/agent-applications')->assertOk();
        $row   = collect($list->json('data'))->firstWhere('id', $profile->id);
        $this->assertNotNull($row);
        $this->assertStringContainsString('signature=', $row['license_doc']);
        $this->assertTrue($row['ai_pending']);

        // Approve.
        $this->actingAsUser($admin)
            ->postJson("/api/admin/agent-applications/{$profile->id}/review", ['action' => 'approve'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $applicant->refresh();
        $this->assertSame('agent', $applicant->role_type);
        $this->assertTrue($applicant->isVerifiedAgent());
        Notification::assertSentTo($applicant, AgentApplicationApproved::class);

        // Audit trail has the decision, with who made it.
        $this->assertDatabaseHas('admin_actions', [
            'admin_id'     => $admin->id,
            'action'       => 'agent.approved',
            'subject_type' => 'AgentProfile',
            'subject_id'   => $profile->id,
        ]);

        // Now agent-only routes work.
        $this->actingAsUser($applicant)->getJson('/api/my-listings')->assertOk();
    }

    public function test_rejection_records_the_reason_and_keeps_the_applicant_a_buyer(): void
    {
        Queue::fake();
        Notification::fake();

        $applicant = $this->buyer();
        $this->actingAsUser($applicant)->post('/api/agent/verify', [
            'applicant_type'    => 'salesperson',
            'full_name'         => 'Liza Reyes',
            'prc_number'        => 'SP-778',
            'face_image'        => UploadedFile::fake()->image('selfie.jpg'),
            'accreditation_doc' => UploadedFile::fake()->image('accreditation.jpg'),
            'valid_id'          => UploadedFile::fake()->image('id.jpg'),
        ])->assertCreated();

        $profile = $applicant->fresh()->agentProfile;
        $admin   = $this->admin();

        // A rejection without a reason is refused.
        $this->actingAsUser($admin)
            ->postJson("/api/admin/agent-applications/{$profile->id}/review", ['action' => 'reject'])
            ->assertStatus(422);

        $this->actingAsUser($admin)
            ->postJson("/api/admin/agent-applications/{$profile->id}/review", [
                'action' => 'reject',
                'reason' => 'The accreditation number does not match the PRC record.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $this->assertSame('buyer', $applicant->fresh()->role_type);
        Notification::assertSentTo($applicant, AgentApplicationRejected::class);

        $action = AdminAction::where('action', 'agent.rejected')->firstOrFail();
        $this->assertSame('The accreditation number does not match the PRC record.', $action->details['reason']);

        // The activity feed shows it to admins only.
        $this->actingAsUser($admin)->getJson('/api/admin/actions?action=agent.rejected')
            ->assertOk()
            ->assertJsonPath('data.0.label', 'Rejected agent application');
        $this->actingAsUser($applicant)->getJson('/api/admin/actions')->assertForbidden();
    }
}
