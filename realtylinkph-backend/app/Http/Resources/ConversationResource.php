<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConversationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id'              => $this->id,
            'property_id'     => $this->property_id,
            'buyer_id'        => $this->buyer_id,
            'agent_id'        => $this->agent_id,
            'last_message_at' => $this->last_message_at?->toISOString(),
            'unread_count'    => $user ? $this->unreadCountFor($user) : 0,
            'property'        => PropertyResource::make($this->whenLoaded('property')),
            'buyer'           => UserResource::make($this->whenLoaded('buyer')),
            'agent'           => UserResource::make($this->whenLoaded('agent')),
            'latest_message'  => MessageResource::make($this->whenLoaded('messages', fn () => $this->messages->first())),
        ];
    }
}
