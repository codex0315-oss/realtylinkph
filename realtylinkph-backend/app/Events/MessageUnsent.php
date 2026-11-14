<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A message was unsent for everyone. Only the id goes out, never the text:
 * the other person's app swaps the bubble for "… unsent a message".
 */
class MessageUnsent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public readonly Message $message) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("conversation.{$this->message->conversation_id}")];
    }

    public function broadcastAs(): string
    {
        return 'message.unsent';
    }

    public function broadcastWith(): array
    {
        return [
            'id'              => $this->message->id,
            'conversation_id' => $this->message->conversation_id,
            'unsent_at'       => $this->message->unsent_at?->toISOString(),
        ];
    }
}
