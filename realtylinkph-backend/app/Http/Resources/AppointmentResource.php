<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AppointmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'property_id'        => $this->property_id,
            'buyer_id'           => $this->buyer_id,
            'agent_id'           => $this->agent_id,
            'preferred_datetime' => $this->preferred_datetime?->toISOString(),
            'status'             => $this->status,
            'can_review'         => $this->canBeReviewedBy($request->user()),
            'gcal_event_id'      => $this->gcal_event_id,
            'notes'              => $this->notes,
            'created_at'         => $this->created_at?->toISOString(),
            'property'           => PropertyResource::make($this->whenLoaded('property')),
            'buyer'              => UserResource::make($this->whenLoaded('buyer')),
            'agent'              => UserResource::make($this->whenLoaded('agent')),
        ];
    }
}
