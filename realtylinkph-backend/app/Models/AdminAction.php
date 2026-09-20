<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminAction extends Model
{
    public const UPDATED_AT = null;

    /** Every action the log knows, with the label the admin UI shows. */
    public const LABELS = [
        'agent.approved'      => 'Approved agent application',
        'agent.rejected'      => 'Rejected agent application',
        'listing.unpublished' => 'Unpublished listing',
        'listing.deleted'     => 'Deleted listing',
        'review.hidden'       => 'Hid review',
        'review.shown'        => 'Restored review',
        'user.deleted'        => 'Deleted user',
        'admin.created'       => 'Created admin account',
    ];

    protected $fillable = [
        'admin_id',
        'admin_name',
        'action',
        'subject_type',
        'subject_id',
        'subject_label',
        'details',
        'ip',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'details'    => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }
}
