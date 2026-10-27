<?php

declare(strict_types=1);

namespace Tests\Feature\Workflows;

use App\Support\Uploads;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsActors;
use Tests\TestCase;

/**
 * Agent cover photo — upload (resized), reposition, remove; buyers can't set
 * one; it shows on the public profile; an admin can take it down with a
 * reason that lands in the audit trail.
 */
class AgentCoverWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use BuildsActors;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(Uploads::name());
    }

    public function test_an_agent_uploads_repositions_and_removes_a_cover(): void
    {
        $agent = $this->agent();

        $res = $this->actingAsUser($agent)->post('/api/me/cover', [
            'cover'    => UploadedFile::fake()->image('cover.jpg', 3200, 1800),
            'position' => 30,
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.cover_position', 30);

        $path = $agent->fresh()->cover_photo;
        $this->assertNotNull($res->json('data.cover_url'));
        Storage::disk(Uploads::name())->assertExists($path);

        // Resized: the stored banner is capped at 1600 px wide.
        $size = getimagesizefromstring(Storage::disk(Uploads::name())->get($path));
        $this->assertSame(1600, $size[0]);

        // It's on the public profile buyers see.
        $this->asGuest()->getJson("/api/agents/{$agent->id}")
            ->assertJsonPath('data.cover_position', 30)
            ->assertJsonPath('data.cover_url', $res->json('data.cover_url'));

        $this->actingAsUser($agent)->putJson('/api/me/cover/position', ['position' => 120])->assertStatus(422);
        $this->actingAsUser($agent)->putJson('/api/me/cover/position', ['position' => 72])
            ->assertOk()->assertJsonPath('data.cover_position', 72);

        $this->actingAsUser($agent)->deleteJson('/api/me/cover')->assertOk()->assertJsonPath('data.cover_url', null);
        Storage::disk(Uploads::name())->assertMissing($path);
    }

    public function test_buyers_can_set_a_cover_admins_cannot_and_non_images_are_rejected(): void
    {
        $buyer = $this->buyer();
        $this->actingAsUser($buyer)->post('/api/me/cover', [
            'cover' => UploadedFile::fake()->image('cover.jpg', 1200, 600),
        ], ['Accept' => 'application/json'])->assertOk();
        $this->assertNotNull($buyer->fresh()->cover_photo);

        $this->actingAsUser($this->admin())->post('/api/me/cover', [
            'cover' => UploadedFile::fake()->image('cover.jpg', 1200, 600),
        ], ['Accept' => 'application/json'])->assertForbidden();

        $this->actingAsUser($this->agent())->post('/api/me/cover', [
            'cover' => UploadedFile::fake()->create('cover.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_an_admin_can_take_a_cover_down_with_a_logged_reason(): void
    {
        $admin = $this->admin(['name' => 'Site Admin']);
        $agent = $this->agent();
        $this->actingAsUser($agent)->post('/api/me/cover', [
            'cover' => UploadedFile::fake()->image('cover.jpg', 1600, 900),
        ], ['Accept' => 'application/json'])->assertOk();

        $this->actingAsUser($agent)->deleteJson("/api/admin/agents/{$agent->id}/cover", ['reason' => 'Not mine'])->assertForbidden();
        $this->actingAsUser($admin)->deleteJson("/api/admin/agents/{$agent->id}/cover", ['reason' => 'no'])->assertStatus(422);

        $this->actingAsUser($admin)->deleteJson("/api/admin/agents/{$agent->id}/cover", [
            'reason' => 'Cover shows another brokerage’s logo.',
        ])->assertOk();

        $this->assertNull($agent->fresh()->cover_photo);
        $this->assertDatabaseHas('admin_actions', ['action' => 'user.cover_removed', 'subject_id' => $agent->id]);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $agent->id, 'type' => 'cover_removed']);
    }
}
