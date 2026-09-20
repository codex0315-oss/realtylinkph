<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentReview extends Model
{
    protected $fillable = [
        'agent_id',
        'buyer_id',
        'appointment_id',
        'conversation_id',
        'rating',
        'review_text',
        'is_visible',
    ];

    protected function casts(): array
    {
        return [
            'rating'     => 'integer',
            'is_visible' => 'boolean',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * "Verified viewing": the review rests on a viewing that actually took
     * place. A review based on a chat, or on a viewing the agent cancelled,
     * still counts — it just doesn't carry the badge.
     */
    public function isVerifiedViewing(): bool
    {
        $a = $this->appointment;

        return $a !== null && in_array($a->status, ['completed', 'confirmed'], true);
    }

    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('is_visible', true);
    }
}
