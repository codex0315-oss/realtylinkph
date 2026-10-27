<?php

declare(strict_types=1);

namespace Tests\Feature\Workflows;

use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsActors;
use Tests\TestCase;

/**
 * The notifications panel — chat messages stay out of the list, items can be
 * read / unread / deleted, read ones cleared, and nobody can touch another
 * user's notifications.
 */
class NotificationPanelWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use BuildsActors;

    public function test_the_panel_lists_alerts_but_not_chat_messages(): void
    {
        $buyer = $this->buyer();
        $svc = app(NotificationService::class);
        $svc->send($buyer, 'appointment_confirmed', ['message' => 'Your viewing is confirmed']);
        $svc->send($buyer, 'new_message', ['message' => 'Hi there', 'conversation_id' => 1]);

        $types = array_column($this->actingAsUser($buyer)->getJson('/api/notifications')->assertOk()->json('data'), 'type');

        $this->assertSame(['appointment_confirmed'], $types);
    }

    public function test_read_unread_delete_and_clear_read(): void
    {
        $buyer = $this->buyer();
        $other = $this->buyer();
        $svc = app(NotificationService::class);
        $svc->send($buyer, 'appointment_confirmed', ['message' => 'A']);
        $svc->send($buyer, 'inquiry_replied', ['message' => 'B']);
        $svc->send($other, 'appointment_confirmed', ['message' => 'Not yours']);

        [$a, $b] = $buyer->notifications()->orderBy('created_at')->pluck('id')->all();
        $theirs = $other->notifications()->value('id');

        $this->actingAsUser($buyer)->postJson("/api/notifications/{$a}/read")->assertOk();
        $this->assertNotNull($buyer->notifications()->find($a)->read_at);
        $this->actingAsUser($buyer)->postJson("/api/notifications/{$a}/unread")->assertOk();
        $this->assertNull($buyer->notifications()->find($a)->read_at);

        // Another user's notification is out of reach.
        $this->actingAsUser($buyer)->deleteJson("/api/notifications/{$theirs}")->assertOk();
        $this->assertNotNull($other->notifications()->find($theirs));

        $this->actingAsUser($buyer)->deleteJson("/api/notifications/{$b}")->assertOk();
        $this->assertNull($buyer->notifications()->find($b));

        // Clear read: only read ones go.
        $svc->send($buyer, 'review_received', ['message' => 'C']);
        $this->actingAsUser($buyer)->postJson("/api/notifications/{$a}/read")->assertOk();
        $this->actingAsUser($buyer)->deleteJson('/api/notifications/clear-read')
            ->assertOk()->assertJsonPath('data.deleted', 1);
        $this->assertSame(['review_received'], $buyer->notifications()->pluck('type')->all());
    }
}
