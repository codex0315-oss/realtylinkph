<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'agent_id'       => $this->agent_id,
            'buyer_id'       => $this->buyer_id,
            'appointment_id' => $this->appointment_id,
            'conversation_id' => $this->conversation_id,
            'rating'         => $this->rating,
            'review_text'    => $this->review_text,
            'is_visible'     => $this->is_visible,
            // "Verified viewing" badge: the review rests on a viewing that took place.
            'is_verified'    => $this->relationLoaded('appointment') && $this->isVerifiedViewing(),
            'property_title' => $this->whenLoaded('appointment', fn () => $this->appointment?->property?->title),
            'is_edited'      => $this->updated_at !== null && $this->created_at !== null && $this->updated_at->gt($this->created_at->copy()->addMinute()),
            'created_at'     => $this->created_at?->toISOString(),
            'updated_at'     => $this->updated_at?->toISOString(),
            'agent'          => UserResource::make($this->whenLoaded('agent')),
            'buyer'          => UserResource::make($this->whenLoaded('buyer')),
        ];
    }
}
