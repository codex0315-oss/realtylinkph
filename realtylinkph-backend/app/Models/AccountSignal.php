<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A scrambled network / device code recorded at sign-up and sign-in (see App\Support\AccountSignals). */
class AccountSignal extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'network_hash', 'device_hash', 'event', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
