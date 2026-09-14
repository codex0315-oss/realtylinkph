<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    protected $fillable = [
        'property_id',
        'buyer_id',
        'agent_id',
        'last_message_at',
        'buyer_deleted_at',
        'agent_deleted_at',
        'agent_offline_emailed_at',
    ];

    protected function casts(): array
    {
        return [
            'last_message_at'          => 'datetime',
            'buyer_deleted_at'         => 'datetime',
            'agent_deleted_at'         => 'datetime',
            'agent_offline_emailed_at' => 'datetime',
        ];
    }

    /** Which side's "deleted" flag belongs to this user. */
    public function deletedAtColumnFor(User $user): ?string
    {
        if ($user->id === $this->buyer_id) return 'buyer_deleted_at';
        if ($user->id === $this->agent_id) return 'agent_deleted_at';
        return null;
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

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->orderBy('created_at');
    }

    public function latestMessage(): HasMany
    {
        return $this->hasMany(Message::class)->latestOfMany();
    }

    public function unreadCountFor(User $user): int
    {
        return $this->messages()
            ->where('sender_id', '!=', $user->id)
            ->where('is_read', false)
            ->count();
    }
}
