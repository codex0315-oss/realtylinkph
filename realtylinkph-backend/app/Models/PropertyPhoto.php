<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PropertyPhoto extends Model
{
    protected $fillable = [
        'property_id',
        'url',
        'thumb_url',
        'is_360',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_360'     => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
