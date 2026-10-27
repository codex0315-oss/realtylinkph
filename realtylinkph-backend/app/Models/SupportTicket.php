<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A Help & support conversation; see the support tables migration. */
class SupportTicket extends Model
{
    public const CATEGORIES = ['bug', 'account', 'verification', 'listing', 'viewing', 'payment', 'other'];

    protected $fillable = [
        'user_id', 'status', 'category', 'summary', 'suggested_reply',
        'escalated_at', 'resolved_at', 'resolved_by', 'rating', 'last_message_at',
    ];

    protected function casts(): array
    {
        return [
            'escalated_at'    => 'datetime',
            'resolved_at'     => 'datetime',
            'last_message_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportMessage::class)->orderBy('id');
    }

    public function lastMessage(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(SupportMessage::class)->latestOfMany('id');
    }

    public function isActive(): bool
    {
        return $this->status !== 'resolved';
    }

    /** Waiting on a person (as opposed to just the AI). */
    public function isEscalated(): bool
    {
        return in_array($this->status, ['open', 'in_progress'], true);
    }
}
