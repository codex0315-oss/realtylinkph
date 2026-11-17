<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An administrator's action on an agent's integrity: warning, listing suspension, revocation, or lifting a suspension. */
class AgentSanction extends Model
{
    public const WARNING    = 'warning';
    public const SUSPENSION = 'suspension';
    public const REVOCATION = 'revocation';
    public const LIFT       = 'lift';

    /** Suspension lengths an administrator can pick, in days. */
    public const SUSPENSION_DAYS = [7, 14, 30];

    protected $fillable = ['user_id', 'admin_id', 'admin_name', 'type', 'reason', 'until', 'lifted_at'];

    protected function casts(): array
    {
        return ['until' => 'datetime', 'lifted_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Suspensions still running. */
    public function scopeActiveSuspension(Builder $q): Builder
    {
        return $q->where('type', self::SUSPENSION)->whereNull('lifted_at')->where('until', '>', now());
    }
}
