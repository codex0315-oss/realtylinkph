<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use App\Support\Uploads;
use Illuminate\Http\Resources\Json\JsonResource;

class PropertyPhotoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'property_id' => $this->property_id,
            'url'         => Uploads::url($this->url),
            // Card-sized copy. Falls back to the main image for photos that
            // predate thumbnails, so the frontend can always use it for cards.
            'thumb_url'   => Uploads::url($this->thumb_url ?? $this->url),
            'is_360'      => $this->is_360,
            'sort_order'  => $this->sort_order,
        ];
    }
}
