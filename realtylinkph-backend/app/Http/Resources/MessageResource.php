<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'conversation_id' => $this->conversation_id,
            'sender_id'       => $this->sender_id,
            // Unsent for everyone: the text stays in the database for admins only.
            'body'            => $this->unsent_at ? null : $this->body,
            'unsent'          => $this->unsent_at !== null,
            'edited'          => $this->edited_at !== null,
            'edited_at'       => $this->edited_at?->toISOString(),
            'pinned_at'       => $this->pinned_at?->toISOString(),
            'pinned_by_id'    => $this->pinned_by_id,
            'is_ai'           => (bool) $this->is_ai,
            'is_read'         => $this->is_read,
            'delivered_at'    => $this->delivered_at?->toISOString(),
            'read_at'         => $this->read_at?->toISOString(),
            'created_at'      => $this->created_at?->toISOString(),
            'sender'          => UserResource::make($this->whenLoaded('sender')),
            // Gone once unsent (the files are deleted too).
            // Loaded here when a caller didn't, so an edit or pin response never drops them.
            'attachments'     => $this->unsent_at ? [] : MessageAttachmentResource::collection($this->resource->loadMissing('attachments')->attachments),
            'attachment_summary' => $this->unsent_at ? null : $this->attachmentSummary(),
        ];
    }
}
