<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentProfile extends Model
{
    /** Hours a rejected applicant must wait before re-applying. */
    public const REAPPLY_COOLDOWN_HOURS = 12;

    protected $fillable = [
        'user_id',
        'applicant_type',
        'prc_number',
        'license_doc',
        'accreditation_doc',
        'valid_id',
        'supervising_broker',
        'face_image',
        'status',
        'admin_note',
        'ai_comment',
        'ai_assessed_at',
        'reviewed_at',
        'google_access_token',
        'google_refresh_token',
        'google_token_expires_at',
    ];

    protected $hidden = [
        'google_access_token',
        'google_refresh_token',
    ];

    protected function casts(): array
    {
        return [
            'google_token_expires_at' => 'datetime',
            'ai_assessed_at'          => 'datetime',
            'reviewed_at'             => 'datetime',
        ];
    }

    /** When a rejected applicant is allowed to re-apply (null if not applicable). */
    public function reapplyAt(): ?CarbonImmutable
    {
        if ($this->status !== 'rejected' || ! $this->reviewed_at) {
            return null;
        }

        return $this->reviewed_at->toImmutable()->addHours(self::REAPPLY_COOLDOWN_HOURS);
    }

    /** True while a rejected applicant is still inside the re-apply cooldown. */
    public function inReapplyCooldown(): bool
    {
        $at = $this->reapplyAt();

        return $at !== null && $at->isFuture();
    }

    /**
     * A rejected application whose cooldown has run out. It stays in the
     * database as the admin's record of the review, but it must no longer
     * follow the applicant around: the API hides it from `/me`, so their
     * settings and sidebar go back to "Become an Agent" and they can apply
     * fresh. `PurgeExpiredRejections` deletes its uploaded documents.
     */
    public function isSpentRejection(): bool
    {
        return $this->status === 'rejected'
            && $this->reviewed_at !== null
            && ! $this->inReapplyCooldown();
    }

    /** Storage paths of the uploaded documents, ignoring any already cleared. */
    public function documentPaths(): array
    {
        return array_values(array_filter([
            $this->license_doc,
            $this->accreditation_doc,
            $this->valid_id,
            $this->face_image,
        ]));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', 'approved');
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
