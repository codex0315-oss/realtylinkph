<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Appointment extends Model
{
    /**
     * A confirmed viewing dropped inside this window counts against whoever
     * cancelled it. Cancelling earlier than this — or cancelling a request that
     * was never confirmed — is normal behaviour and costs nothing.
     */
    public const LATE_WINDOW_HOURS = 24;

    /** Why a viewing was cancelled. Categories, so patterns are measurable. */
    public const BUYER_REASONS = [
        'schedule_conflict'      => 'Schedule conflict',
        'found_another'          => 'Found another property',
        'no_longer_interested'   => 'No longer interested',
        'agent_unresponsive'     => 'Agent was unresponsive',
        'emergency'              => 'Emergency',
        'other'                  => 'Other',
    ];

    public const AGENT_REASONS = [
        'property_unavailable'   => 'Property is no longer available',
        'schedule_conflict'      => 'Schedule conflict',
        'double_booked'          => 'Double booked',
        'buyer_unresponsive'     => 'Buyer was unresponsive',
        'emergency'              => 'Emergency',
        'other'                  => 'Other',
    ];

    public static function reasonsFor(bool $isAgent): array
    {
        return $isAgent ? self::AGENT_REASONS : self::BUYER_REASONS;
    }

    protected $fillable = [
        'property_id',
        'buyer_id',
        'agent_id',
        'preferred_datetime',
        'status',
        'gcal_event_id',
        'gcal_event_id_buyer',
        'notes',
        'cancel_reason_code',
        'cancel_reason_note',
        'cancelled_by_id',
        'cancelled_at',
        'late_cancellation',
        'strike_waived_at',
    ];

    protected function casts(): array
    {
        return [
            'preferred_datetime' => 'datetime',
            'cancelled_at'       => 'datetime',
            'strike_waived_at'   => 'datetime',
            'late_cancellation'  => 'boolean',
        ];
    }

    /**
     * Would cancelling right now count against the canceller? True only for a
     * confirmed viewing whose slot is less than LATE_WINDOW_HOURS away (and
     * hasn't already passed — a no-show is a separate problem).
     */
    public function wouldBeLateCancellation(): bool
    {
        if ($this->status !== 'confirmed' || $this->preferred_datetime === null) {
            return false;
        }

        // Signed, measured FROM now: positive means the slot is still ahead.
        // `$slot->diffInHours(now())` returns a negative number for a future
        // slot, which made every confirmed viewing look late.
        $hoursUntil = now()->diffInHours($this->preferred_datetime, false);

        return $hoursUntil >= 0 && $hoursUntil < self::LATE_WINDOW_HOURS;
    }

    /** A late cancellation that an admin hasn't forgiven. */
    public function countsAsStrike(): bool
    {
        return $this->late_cancellation && $this->strike_waived_at === null;
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_id');
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
