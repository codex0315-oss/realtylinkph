<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PropertyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'agent_id'    => $this->agent_id,
            'title'       => $this->title,
            'description' => $this->description,
            'price'       => (float) $this->price,
            'type'        => $this->type,
            'offer_type'  => $this->offer_type ?? 'sale',
            'bedrooms'    => $this->bedrooms,
            'bathrooms'   => $this->bathrooms,
            'floor_area'  => $this->floor_area ? (float) $this->floor_area : null,
            'lot_area'    => $this->lot_area ? (float) $this->lot_area : null,
            'address'     => $this->address,
            'lat'         => $this->lat ? (float) $this->lat : null,
            'lng'         => $this->lng ? (float) $this->lng : null,
            'status'         => $this->status,
            'sold_at'        => $this->sold_at?->toISOString(),
            // Admin take-down reason — only meaningful to the owner and admins;
            // a draft is never shown publicly anyway.
            'unpublish_reason' => $this->when(
                $this->unpublish_reason !== null
                    && ($request->user('sanctum')?->isAdmin() || $request->user('sanctum')?->id === $this->agent_id),
                $this->unpublish_reason,
            ),
            'unpublished_at' => $this->unpublished_at?->toISOString(),
            'price_flagged'  => $this->priceLooksOff(),
            'featured_score' => round((float) ($this->featured_score ?? 0), 1),
            'is_featured'    => (bool) ($this->is_featured ?? false),
            'views'       => (int) ($this->views ?? 0),
            'is_favorited' => $request->user('sanctum')?->hasFavorited($this->id) ?? false,
            'created_at'  => $this->created_at?->toISOString(),
            'updated_at'  => $this->updated_at?->toISOString(),
            'photos'      => PropertyPhotoResource::collection($this->whenLoaded('photos')),
            'agent'       => UserResource::make($this->whenLoaded('agent')),
        ];
    }
}
