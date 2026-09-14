<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PropertyPhotoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'property_id' => $this->property_id,
            'url'         => asset('storage/' . $this->url),
            'is_360'      => $this->is_360,
            'sort_order'  => $this->sort_order,
        ];
    }
}
