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
            'body'            => $this->body,
            'is_ai'           => (bool) $this->is_ai,
            'is_read'         => $this->is_read,
            'delivered_at'    => $this->delivered_at?->toISOString(),
            'read_at'         => $this->read_at?->toISOString(),
            'created_at'      => $this->created_at?->toISOString(),
            'sender'          => UserResource::make($this->whenLoaded('sender')),
        ];
    }
}
