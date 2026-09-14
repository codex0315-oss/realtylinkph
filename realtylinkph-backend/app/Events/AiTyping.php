<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired the moment the auto-reply job starts, so the buyer sees "RealtyLink AI
 * is typing…" for the few seconds Gemini takes instead of dead air. The real
 * reply follows as a normal MessageSent.
 */
class AiTyping implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public readonly int $conversationId) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("conversation.{$this->conversationId}")];
    }

    public function broadcastAs(): string
    {
        return 'ai.typing';
    }

    public function broadcastWith(): array
    {
        return ['conversation_id' => $this->conversationId];
    }
}
