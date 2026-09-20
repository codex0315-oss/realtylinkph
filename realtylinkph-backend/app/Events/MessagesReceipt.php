<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * "Your messages were delivered / seen" — pushed to the sender on the
 * conversation channel so their ticks move without a refresh.
 *
 * A server event on purpose, not a client whisper: whispers need "client
 * events" switched on in the Pusher app and are lost if the other tab is
 * closed at that instant. This one is recorded in the database first, then
 * broadcast, so a refresh always agrees with what the ticks showed.
 */
class MessagesReceipt implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @param 'delivered'|'read' $kind
     * @param int[]              $messageIds  the sender's messages this receipt covers
     */
    public function __construct(
        public readonly int $conversationId,
        public readonly int $byUserId,
        public readonly string $kind,
        public readonly array $messageIds,
        public readonly string $at,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("conversation.{$this->conversationId}")];
    }

    public function broadcastAs(): string
    {
        return 'messages.receipt';
    }

    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'by_user_id'      => $this->byUserId,
            'kind'            => $this->kind,
            'message_ids'     => $this->messageIds,
            'at'              => $this->at,
        ];
    }
}
