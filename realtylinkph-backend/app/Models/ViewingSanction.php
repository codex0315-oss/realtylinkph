<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An admin's decision about someone's viewing behaviour. See the migration for
 * the four types; `pause` rows carry `until`, and lifting one stamps `lifted_at`.
 */
class ViewingSanction extends Model
{
    public const WARNING = 'warning';
    public const PAUSE   = 'pause';
    public const LIFT    = 'lift';
    public const WAIVE   = 'waive';

    /** Pause lengths an admin can choose, in days. */
    public const PAUSE_DAYS = [3, 7, 14, 30];

    protected $fillable = [
        'user_id',
        'admin_id',
        'appointment_id',
        'type',
        'reason',
        'days',
        'until',
        'lifted_at',
        'lifted_by_id',
    ];

    protected function casts(): array
    {
        return [
            'until'     => 'datetime',
            'lifted_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }
}
