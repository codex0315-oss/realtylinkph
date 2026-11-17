<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AccountSignal;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Remembers *where* an account signs in from, without storing where that is.
 *
 * The network address and the browser are turned into keyed hashes (HMAC with
 * the app key), so the database never holds a raw IP or user agent, yet two
 * accounts used from the same network + browser end up with the same code.
 * Used only to flag reviews for an administrator to look at, never to block
 * anyone automatically: many people share a network (mobile data, offices).
 */
final class AccountSignals
{
    public static function record(User $user, Request $request, string $event): void
    {
        $ip = (string) $request->ip();
        $ua = (string) $request->userAgent();
        if ($ip === '') {
            return;
        }

        $network = self::hash('net|' . $ip);
        $device  = self::hash('dev|' . $ip . '|' . $ua);

        // One row per account, device and day is enough.
        $seen = AccountSignal::where('user_id', $user->id)
            ->where('device_hash', $device)
            ->where('created_at', '>=', now()->subHours(12))
            ->exists();
        if ($seen) {
            return;
        }

        try {
            AccountSignal::create(['user_id' => $user->id, 'network_hash' => $network, 'device_hash' => $device, 'event' => $event, 'created_at' => now()]);
        } catch (\Throwable $e) {
            report($e); // never let this get in the way of signing in
        }
    }

    public static function hash(string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('app.key'));
    }
}
