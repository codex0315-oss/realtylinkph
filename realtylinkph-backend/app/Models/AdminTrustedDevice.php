<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A browser an admin trusts for a while, so the emailed code is skipped there. */
class AdminTrustedDevice extends Model
{
    protected $fillable = ['user_id', 'token_hash', 'label', 'expires_at', 'last_used_at'];

    protected function casts(): array
    {
        return [
            'expires_at'   => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
