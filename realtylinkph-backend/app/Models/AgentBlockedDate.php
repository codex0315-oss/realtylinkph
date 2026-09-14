<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentBlockedDate extends Model
{
    protected $fillable = [
        'agent_id',
        'blocked_date',
        'start_time',
        'end_time',
        'reason',
        'is_recurring',
        'recurring_day_of_week',
    ];

    protected function casts(): array
    {
        return [
            // Serialize as plain Y-m-d so the date isn't shifted to UTC (app TZ is Asia/Manila).
            'blocked_date'          => 'date:Y-m-d',
            'is_recurring'          => 'boolean',
            'recurring_day_of_week' => 'integer',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function scopeForAgent(Builder $query, int $agentId): Builder
    {
        return $query->where('agent_id', $agentId);
    }

    public function scopeRecurring(Builder $query): Builder
    {
        return $query->where('is_recurring', true);
    }

    public function scopeNonRecurring(Builder $query): Builder
    {
        return $query->where('is_recurring', false);
    }
}
