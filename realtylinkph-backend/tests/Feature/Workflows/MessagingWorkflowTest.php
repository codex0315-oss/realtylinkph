<?php

declare(strict_types=1);

namespace Tests\Feature\Workflows;

use App\Events\MessageSent;
use App\Events\MessagesReceipt;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\BuildsActors;
use Tests\TestCase;

/**
 * Feature 7 — buyer messages an agent; the message is broadcast; the
 * agent's app acknowledges delivery (✓✓), then reads it (✓✓ gold); each
 * step is stored and pushed back to the sender as a receipt.
 */
class MessagingWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use BuildsActors;

    public function test_send_deliver_read_receipts(): void
    {
        Event::fake([MessageSent::class, MessagesReceipt::class]);

        // Agent online, so the "agent is away → RealtyLink AI replies" branch stays out of this test.
        $agent   = $this->agent(['last_seen_at' => now()]);
        $buyer   = $this->buyer();
        $listing = $this->listing($agent);

        // Start the thread from the listing and send.
        $conv = $this->actingAsUser($buyer)->postJson("/api/properties/{$listing->id}/conversations")->assertOk();
        $convId = $conv->json('data.id');

        $sent = $this->actingAsUser($buyer)->postJson("/api/conversations/{$convId}/messages", ['body' => 'Is the unit still available?'])
            ->assertCreated();
        $msgId = $sent->json('data.id');
        $this->assertNull($sent->json('data.delivered_at'));
        $this->assertNull($sent->json('data.read_at'));
        Event::assertDispatched(MessageSent::class, fn ($e) => $e->message->id === $msgId);

        // Strangers cannot read or ack the thread.
        $this->actingAsUser($this->buyer())->getJson("/api/conversations/{$convId}/messages")->assertForbidden();
        $this->actingAsUser($this->buyer())->postJson("/api/conversations/{$convId}/delivered")->assertForbidden();

        // Agent's app receives it (notification handler) → delivered, receipt to the buyer.
        $this->actingAsUser($agent)->postJson("/api/conversations/{$convId}/delivered")->assertOk();
        $m = Message::find($msgId);
        $this->assertNotNull($m->delivered_at);
        $this->assertNull($m->read_at, 'delivered is not read');
        Event::assertDispatched(MessagesReceipt::class, fn ($e) => $e->kind === 'delivered' && $e->messageIds === [$msgId] && $e->byUserId === $agent->id);

        // A second ack is a no-op — no duplicate receipt.
        $this->actingAsUser($agent)->postJson("/api/conversations/{$convId}/delivered")->assertOk();
        Event::assertDispatchedTimes(MessagesReceipt::class, 1);

        // Agent opens the thread → read, receipt to the buyer.
        $this->actingAsUser($agent)->getJson("/api/conversations/{$convId}/messages")->assertOk();
        $m->refresh();
        $this->assertTrue($m->is_read);
        $this->assertNotNull($m->read_at);
        Event::assertDispatched(MessagesReceipt::class, fn ($e) => $e->kind === 'read' && $e->messageIds === [$msgId]);

        // The sender's own messages never get receipts from themselves.
        $this->actingAsUser($buyer)->postJson("/api/conversations/{$convId}/delivered")->assertOk();
        $this->actingAsUser($buyer)->postJson("/api/conversations/{$convId}/read")->assertOk();
        Event::assertDispatchedTimes(MessagesReceipt::class, 2);

        // The buyer's next load shows both timestamps.
        $list = $this->actingAsUser($buyer)->getJson("/api/conversations/{$convId}/messages")->assertOk();
        $row  = collect($list->json('data'))->firstWhere('id', $msgId);
        $this->assertNotNull($row['delivered_at']);
        $this->assertNotNull($row['read_at']);
    }

    public function test_heartbeat_marks_everything_delivered_at_once(): void
    {
        Event::fake([MessageSent::class, MessagesReceipt::class]);

        $agent = $this->agent(['last_seen_at' => now()]);
        $buyer = $this->buyer();
        $l1 = $this->listing($agent);
        $l2 = $this->listing($agent, ['title' => 'Second listing']);

        foreach ([$l1, $l2] as $l) {
            $id = $this->actingAsUser($buyer)->postJson("/api/properties/{$l->id}/conversations")->json('data.id');
            $this->actingAsUser($buyer)->postJson("/api/conversations/{$id}/messages", ['body' => 'Hello'])->assertCreated();
            $this->actingAsUser($buyer)->postJson("/api/conversations/{$id}/messages", ['body' => 'Are you there?'])->assertCreated();
        }
        $this->assertSame(4, Message::whereNull('delivered_at')->count());

        // Agent's app comes online (any page) → all four delivered, one receipt per thread.
        $this->actingAsUser($agent)->postJson('/api/heartbeat')->assertOk();
        $this->assertSame(0, Message::whereNull('delivered_at')->count());
        Event::assertDispatchedTimes(MessagesReceipt::class, 2);

        // Nothing addressed to the buyer changed.
        $this->assertSame(0, Message::where('sender_id', $agent->id)->count());
    }
}
