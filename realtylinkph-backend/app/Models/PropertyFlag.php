<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Why a listing needs an admin's look: found by RealtyLink AI, or reported by a buyer. */
class PropertyFlag extends Model
{
    public const AI     = 'ai';
    public const REPORT = 'report';

    protected $fillable = ['property_id', 'source', 'reasons', 'details', 'note', 'reporter_id', 'status', 'paused', 'resolved_by', 'resolved_at'];

    protected function casts(): array
    {
        return ['reasons' => 'array', 'details' => 'array', 'paused' => 'boolean', 'resolved_at' => 'datetime'];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->where('status', 'open');
    }
}
