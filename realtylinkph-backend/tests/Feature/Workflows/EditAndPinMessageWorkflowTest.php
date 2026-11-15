<?php

declare(strict_types=1);

namespace Tests\Feature\Workflows;

use App\Events\MessageUpdated;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\BuildsActors;
use Tests\TestCase;

/** Edit your own messages and pin any message, like Messenger. */
class EditAndPinMessageWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use BuildsActors;

    private function chat(): array
    {
        $agent = $this->agent(['last_seen_at' => now()]);
        $buyer = $this->buyer();
        $conv  = $this->actingAsUser($buyer)->postJson("/api/properties/{$this->listing($agent)->id}/conversations")->json('data.id');

        return [$agent, $buyer, $conv];
    }

    private function say(User $who, int $conv, string $body): int
    {
        return $this->actingAsUser($who)->postJson("/api/conversations/{$conv}/messages", ['body' => $body])->assertCreated()->json('data.id');
    }

    public function test_the_sender_can_edit_with_no_time_limit_and_the_first_version_is_kept_for_admins(): void
    {
        Event::fake([MessageUpdated::class]);
        [$agent, $buyer, $conv] = $this->chat();
        $id = $this->say($buyer, $conv, 'Is it availble?');

        $this->travel(30)->days();   // no time limit
        $this->actingAsUser($buyer)->patchJson("/api/messages/{$id}", ['body' => 'Is it available?'])->assertOk()
            ->assertJsonPath('data.body', 'Is it available?')->assertJsonPath('data.edited', true)
            ->assertJsonMissingPath('data.original_body');
        Event::assertDispatched(MessageUpdated::class);

        // The other person sees the new text and "Edited".
        $msg = collect($this->actingAsUser($agent)->getJson("/api/conversations/{$conv}/messages")->json('data'))->firstWhere('id', $id);
        $this->assertSame('Is it available?', $msg['body']);
        $this->assertTrue($msg['edited']);

        // A second edit keeps the very first version for admins.
        $this->actingAsUser($buyer)->patchJson("/api/messages/{$id}", ['body' => 'Is it still available?'])->assertOk();
        $this->assertSame('Is it availble?', Message::find($id)->original_body);
    }

    public function test_you_cannot_edit_someone_elses_message_an_ai_reply_or_an_unsent_message(): void
    {
        [$agent, $buyer, $conv] = $this->chat();
        $id = $this->say($buyer, $conv, 'hello');
        $this->actingAsUser($agent)->patchJson("/api/messages/{$id}", ['body' => 'hacked'])->assertForbidden();

        $ai = User::factory()->create(['role_type' => 'agent', 'is_system' => true]);
        $aiMsg = Message::create(['conversation_id' => $conv, 'sender_id' => $ai->id, 'body' => 'AI reply', 'is_ai' => true, 'is_read' => false]);
        $this->actingAsUser($agent)->patchJson("/api/messages/{$aiMsg->id}", ['body' => 'changed'])->assertForbidden();

        $this->actingAsUser($buyer)->postJson("/api/messages/{$id}/unsend")->assertOk();
        $this->actingAsUser($buyer)->patchJson("/api/messages/{$id}", ['body' => 'again'])->assertStatus(422);
        $this->actingAsUser($buyer)->patchJson("/api/messages/{$id}", ['body' => ''])->assertStatus(422);
    }

    public function test_either_person_can_pin_any_message_and_both_see_it(): void
    {
        [$agent, $buyer, $conv] = $this->chat();
        $mine   = $this->say($buyer, $conv, 'My budget is 5M');
        $theirs = $this->say($agent, $conv, 'Viewing is Saturday 10 AM');

        $this->actingAsUser($buyer)->postJson("/api/messages/{$theirs}/pin")->assertOk()->assertJsonPath('data.pinned_by_id', $buyer->id);
        $this->actingAsUser($agent)->postJson("/api/messages/{$mine}/pin")->assertOk();

        foreach ([$buyer, $agent] as $who) {
            $ids = collect($this->actingAsUser($who)->getJson("/api/conversations/{$conv}/pinned")->assertOk()->json('data'))->pluck('id')->all();
            $this->assertEqualsCanonicalizing([$mine, $theirs], $ids);
        }

        $this->actingAsUser($agent)->deleteJson("/api/messages/{$theirs}/pin")->assertOk();
        $this->actingAsUser($buyer)->getJson("/api/conversations/{$conv}/pinned")->assertJsonCount(1, 'data');

        // Outsiders can't pin or see pins.
        $this->actingAsUser($this->buyer())->postJson("/api/messages/{$mine}/pin")->assertForbidden();
    }

    public function test_unsending_a_message_unpins_it(): void
    {
        [$agent, $buyer, $conv] = $this->chat();
        $id = $this->say($buyer, $conv, 'pin me');
        $this->actingAsUser($agent)->postJson("/api/messages/{$id}/pin")->assertOk();

        $this->actingAsUser($buyer)->postJson("/api/messages/{$id}/unsend")->assertOk();
        $this->actingAsUser($agent)->getJson("/api/conversations/{$conv}/pinned")->assertJsonCount(0, 'data');
        $this->actingAsUser($agent)->postJson("/api/messages/{$id}/pin")->assertStatus(422);
    }
}
