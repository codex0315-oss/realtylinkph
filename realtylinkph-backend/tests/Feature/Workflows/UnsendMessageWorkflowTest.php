<?php

declare(strict_types=1);

namespace Tests\Feature\Workflows;

use App\Events\MessageUnsent;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\BuildsActors;
use Tests\TestCase;

/**
 * Unsend, like Messenger: "Unsend for everyone" on your own messages (and an
 * agent on AI replies sent for them), "Remove for you" on any message.
 */
class UnsendMessageWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use BuildsActors;

    private function chat(): array
    {
        $agent = $this->agent(['last_seen_at' => now()]);   // online: no AI auto-reply
        $buyer = $this->buyer();
        $conv  = $this->actingAsUser($buyer)->postJson("/api/properties/{$this->listing($agent)->id}/conversations")->json('data.id');

        return [$agent, $buyer, $conv];
    }

    private function say(User $who, int $conv, string $body): int
    {
        return $this->actingAsUser($who)->postJson("/api/conversations/{$conv}/messages", ['body' => $body])->assertCreated()->json('data.id');
    }

    public function test_unsend_for_everyone_hides_the_text_for_both_but_keeps_it_for_admins(): void
    {
        Event::fake([MessageUnsent::class]);
        [$agent, $buyer, $conv] = $this->chat();
        $id = $this->say($buyer, $conv, 'my secret phone number 0917');

        $this->actingAsUser($buyer)->postJson("/api/messages/{$id}/unsend")->assertOk()
            ->assertJsonPath('data.unsent', true)->assertJsonPath('data.body', null);
        Event::assertDispatched(MessageUnsent::class);

        // Both people see it as unsent, without the text.
        foreach ([$buyer, $agent] as $who) {
            $msg = collect($this->actingAsUser($who)->getJson("/api/conversations/{$conv}/messages")->json('data'))->firstWhere('id', $id);
            $this->assertTrue($msg['unsent']);
            $this->assertNull($msg['body']);
        }
        // The chat list preview is unsent too.
        $this->actingAsUser($agent)->getJson('/api/conversations')->assertJsonPath('data.0.latest_message.unsent', true);
        // The text is kept in the database for admins.
        $this->assertSame('my secret phone number 0917', Message::find($id)->body);
    }

    public function test_you_cannot_unsend_someone_elses_message(): void
    {
        [$agent, $buyer, $conv] = $this->chat();
        $id = $this->say($buyer, $conv, 'hello');

        $this->actingAsUser($agent)->postJson("/api/messages/{$id}/unsend")->assertForbidden();
        $this->actingAsUser($this->buyer())->postJson("/api/messages/{$id}/unsend")->assertForbidden();   // not in the chat
        $this->assertNull(Message::find($id)->unsent_at);
    }

    public function test_an_agent_can_unsend_an_ai_reply_sent_for_them_but_the_buyer_cannot(): void
    {
        [$agent, $buyer, $conv] = $this->chat();
        $ai = User::factory()->create(['role_type' => 'agent', 'is_system' => true]);
        $msg = Message::create(['conversation_id' => $conv, 'sender_id' => $ai->id, 'body' => 'AI reply', 'is_ai' => true, 'is_read' => false]);

        $this->actingAsUser($buyer)->postJson("/api/messages/{$msg->id}/unsend")->assertForbidden();
        $this->actingAsUser($agent)->postJson("/api/messages/{$msg->id}/unsend")->assertOk()->assertJsonPath('data.unsent', true);
    }

    public function test_remove_for_you_hides_it_only_for_you(): void
    {
        [$agent, $buyer, $conv] = $this->chat();
        $first  = $this->say($buyer, $conv, 'first');
        $second = $this->say($agent, $conv, 'second from agent');

        // The buyer removes the agent's message from their own view.
        $this->actingAsUser($buyer)->postJson("/api/messages/{$second}/hide")->assertOk();

        $buyerIds = collect($this->actingAsUser($buyer)->getJson("/api/conversations/{$conv}/messages")->json('data'))->pluck('id');
        $agentIds = collect($this->actingAsUser($agent)->getJson("/api/conversations/{$conv}/messages")->json('data'))->pluck('id');
        $this->assertFalse($buyerIds->contains($second));
        $this->assertTrue($agentIds->contains($second), 'the other person still sees it');

        // The buyer's chat list preview falls back to their latest visible message.
        $this->actingAsUser($buyer)->getJson('/api/conversations')->assertJsonPath('data.0.latest_message.id', $first);
    }

    public function test_unsent_messages_do_not_count_as_unread(): void
    {
        [$agent, $buyer, $conv] = $this->chat();
        $id = $this->say($buyer, $conv, 'oops wrong chat');
        $this->actingAsUser($agent)->getJson('/api/conversations')->assertJsonPath('data.0.unread_count', 1);

        $this->actingAsUser($buyer)->postJson("/api/messages/{$id}/unsend")->assertOk();
        $this->actingAsUser($agent)->getJson('/api/conversations')->assertJsonPath('data.0.unread_count', 0);
    }
}
