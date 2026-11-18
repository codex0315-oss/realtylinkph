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
        'edited_at',
        'original_body',
        'pinned_at',
        'pinned_by_id',
    ];

    /** Never sent to users: the first version of an edited message, kept for admins. */
    protected $hidden = ['original_body'];

    protected function casts(): array
    {
        return [
            'is_ai'   => 'boolean',
            'is_read' => 'boolean',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'unsent_at' => 'datetime',
            'hidden_for' => 'array',
            'edited_at' => 'datetime',
            'pinned_at' => 'datetime',
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

    /** Photos, videos and files sent with this message. */
    public function attachments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(MessageAttachment::class)->orderBy('id');
    }

    /** "📷 2 photos" style summary for previews and notifications. */
    public function attachmentSummary(): ?string
    {
        $kinds = $this->attachments->countBy('kind');
        if ($kinds->isEmpty()) {
            return null;
        }
        $parts = [];
        foreach ([MessageAttachment::IMAGE => ['📷', 'photo'], MessageAttachment::VIDEO => ['🎥', 'video'], MessageAttachment::FILE => ['📄', 'file']] as $kind => [$icon, $word]) {
            if ($n = $kinds->get($kind)) {
                $parts[] = "{$icon} " . ($n === 1 ? ucfirst($word) : "{$n} {$word}s");
            }
        }

        return implode(', ', $parts);
    }
}
