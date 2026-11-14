<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    protected $fillable = [
        'conversation_id',
        'sender_id',
        'body',
        'is_ai',
        'is_read',
        'delivered_at',
        'read_at',
        'unsent_at',
        'hidden_for',
    ];

    protected function casts(): array
    {
        return [
            'is_ai'   => 'boolean',
            'is_read' => 'boolean',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'unsent_at' => 'datetime',
            'hidden_for' => 'array',
        ];
    }

    /** Unsent for everyone: the text is kept for admins but never shown to users. */
    public function isUnsent(): bool
    {
        return $this->unsent_at !== null;
    }

    /** Messages this user has not removed from their own view ("Remove for you"). */
    public function scopeVisibleTo($query, User|int $user)
    {
        $id = $user instanceof User ? $user->id : $user;

        return $query->where(fn ($q) => $q->whereNull('hidden_for')->orWhereJsonDoesntContain('hidden_for', $id));
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }
}
