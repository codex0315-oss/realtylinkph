<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Support\ChatFiles;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** A chat photo, video or file. Only ever sent to the two people in the conversation. */
class MessageAttachmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return self::shape($this->resource);
    }

    /** Also used by the live broadcast, which has no request. */
    public static function shape(\App\Models\MessageAttachment $a): array
    {
        return [
            'id'        => $a->id,
            'kind'      => $a->kind,
            'name'      => $a->name,
            'mime'      => $a->mime,
            'size'      => $a->size,
            'width'     => $a->width,
            'height'    => $a->height,
            'url'       => ChatFiles::url($a->path),
            'thumb_url' => ChatFiles::url($a->thumb_path) ?? ($a->kind === 'image' ? ChatFiles::url($a->path) : null),
        ];
    }
}
