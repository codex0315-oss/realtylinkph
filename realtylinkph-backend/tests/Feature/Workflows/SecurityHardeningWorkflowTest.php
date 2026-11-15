<?php

declare(strict_types=1);

namespace Tests\Feature\Workflows;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\BuildsActors;
use Tests\TestCase;

/**
 * Pre-defense hardening: chat messages are rate-limited (with a plain-words
 * reply), and account settings stay private to the owner and admins.
 */
class SecurityHardeningWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use BuildsActors;

    public function test_sending_more_than_thirty_messages_a_minute_is_paused_with_a_clear_message(): void
    {
        Event::fake();
        $agent = $this->agent(['last_seen_at' => now()]);   // online: no AI auto-reply
        $buyer = $this->buyer();
        $conv  = $this->actingAsUser($buyer)->postJson("/api/properties/{$this->listing($agent)->id}/conversations")->json('data.id');

        for ($i = 1; $i <= 30; $i++) {
            $this->postJson("/api/conversations/{$conv}/messages", ['body' => "Message {$i}"])->assertCreated();
        }

        $res = $this->postJson("/api/conversations/{$conv}/messages", ['body' => 'One too many'])
            ->assertStatus(429)
            ->assertHeader('Retry-After');
        $this->assertStringStartsWith("You're sending messages too fast.", $res->json('message'));
        $this->assertSame([], $res->json('errors') ?? []);
    }

    public function test_an_agents_account_settings_are_not_in_public_listings(): void
    {
        $agent   = $this->agent(['theme' => 'dark', 'property_alerts' => true]);
        $listing = $this->listing($agent);

        $public = $this->asGuest()->getJson("/api/properties/{$listing->id}")->assertOk()->json('data.agent');
        $this->assertSame($agent->name, $public['name']);
        foreach (['theme', 'property_alerts', 'has_gcal', 'email_verified_at', 'password_set'] as $field) {
            $this->assertArrayNotHasKey($field, $public, "{$field} should not be public");
        }

        // The agent still sees their own settings.
        $this->actingAsUser($agent)->getJson('/api/me')->assertOk()
            ->assertJsonPath('data.theme', 'dark')
            ->assertJsonPath('data.property_alerts', true);
    }
}
