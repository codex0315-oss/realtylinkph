<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A message was edited, pinned, or unpinned. The other person's app updates
 * the bubble (new text + "Edited") and the "Pinned messages" bar in place.
 */
class MessageUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public readonly Message $message) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("conversation.{$this->message->conversation_id}")];
    }

    public function broadcastAs(): string
    {
        return 'message.updated';
    }

    public function broadcastWith(): array
    {
        $m = $this->message;

        return [
            'id'              => $m->id,
            'conversation_id' => $m->conversation_id,
            'sender_id'       => $m->sender_id,
            'body'            => $m->unsent_at ? null : $m->body,
            'is_ai'           => (bool) $m->is_ai,
            'created_at'      => $m->created_at?->toISOString(),
            'edited'          => $m->edited_at !== null,
            'edited_at'       => $m->edited_at?->toISOString(),
            'pinned_at'       => $m->pinned_at?->toISOString(),
            'pinned_by_id'    => $m->pinned_by_id,
            'attachments'     => $m->unsent_at ? [] : $m->loadMissing('attachments')->attachments->map(fn ($a) => \App\Http\Resources\MessageAttachmentResource::shape($a))->values()->all(),
        ];
    }
}
