<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast synchronously. As a queued `ShouldBroadcast` the push only left
 * the box when a queue worker happened to be running — if it wasn't, the
 * message still saved but the other side saw nothing until a refresh. Reverb
 * is on localhost, so the direct call costs a few milliseconds.
 */
class MessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly Message $message) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("conversation.{$this->message->conversation_id}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    public function broadcastWith(): array
    {
        return [
            'id'              => $this->message->id,
            'conversation_id' => $this->message->conversation_id,
            'sender_id'       => $this->message->sender_id,
            'body'            => $this->message->body,
            'is_ai'           => (bool) $this->message->is_ai,
            'is_read'         => $this->message->is_read,
            'delivered_at'    => null,
            'read_at'         => null,
            'created_at'      => $this->message->created_at?->toISOString(),
        ];
    }
}
