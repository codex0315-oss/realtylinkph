<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InquiryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'property_id'    => $this->property_id,
            'user_id'        => $this->user_id,
            'name'           => $this->name,
            'email'          => $this->email,
            'phone'          => $this->phone,
            'message'        => $this->message,
            'is_read'        => $this->is_read,
            'is_ghost_buyer' => $this->isGhostBuyer(),
            'created_at'     => $this->created_at?->toISOString(),
            'property'       => PropertyResource::make($this->whenLoaded('property')),
        ];
    }
}
