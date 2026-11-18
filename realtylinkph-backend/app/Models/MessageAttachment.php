<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageAttachment extends Model
{
    public const IMAGE = 'image';
    public const VIDEO = 'video';
    public const FILE  = 'file';

    protected $fillable = ['message_id', 'kind', 'path', 'thumb_path', 'name', 'mime', 'size', 'width', 'height'];

    protected function casts(): array
    {
        return ['size' => 'integer', 'width' => 'integer', 'height' => 'integer'];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
