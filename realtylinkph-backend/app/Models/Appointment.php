<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Appointment extends Model
{
    protected $fillable = [
        'property_id',
        'buyer_id',
        'agent_id',
        'preferred_datetime',
        'status',
        'gcal_event_id',
        'gcal_event_id_buyer',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'preferred_datetime' => 'datetime',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function review(): HasOne
    {
        return $this->hasOne(AgentReview::class);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    public function scopeConfirmed(Builder $query): Builder
    {
        return $query->where('status', 'confirmed');
    }

    public function scopeCancelled(Builder $query): Builder
    {
        return $query->where('status', 'cancelled');
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', 'completed');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isConfirmed(): bool
    {
        return $this->status === 'confirmed';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /** The buyer may review the agent once the viewing is confirmed/completed and not yet reviewed. */
    public function canBeReviewedBy(?User $user): bool
    {
        if (! $user || $user->id !== $this->buyer_id) {
            return false;
        }
        if (! in_array($this->status, ['confirmed', 'completed'], true)) {
            return false;
        }

        $hasReview = $this->relationLoaded('review') ? $this->review !== null : $this->review()->exists();

        return ! $hasReview;
    }
}
