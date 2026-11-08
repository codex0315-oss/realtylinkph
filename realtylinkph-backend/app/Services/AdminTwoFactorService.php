<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AdminTrustedDevice;
use App\Models\User;
use App\Notifications\AdminSignInCode;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Admin sign-in has a second step: a 6-digit code emailed to the admin.
 *
 * Signing in with the password (or with Google) only gets an admin a
 * *challenge*. The token is issued when they send back the right code. A
 * challenge lasts TTL_MINUTES, allows MAX_ATTEMPTS wrong guesses, and the code
 * works once. Codes are stored hashed, never in the clear.
 *
 * Set ADMIN_TWO_FACTOR=false to switch the step off (for example if the email
 * provider is down and nobody can sign in).
 */
class AdminTwoFactorService
{
    public const TTL_MINUTES  = 10;
    public const MAX_ATTEMPTS = 5;
    /** How soon a new code may be requested for the same challenge. */
    public const RESEND_AFTER_SECONDS = 30;

    public function required(User $user): bool
    {
        return (bool) config('auth.admin_two_factor', true) && $user->isAdmin();
    }

    /**
     * Start a challenge and email the code.
     *
     * @return array{challenge: string, email: string, expires_in: int}|null  null when the email could not be sent
     */
    public function begin(User $user): ?array
    {
        $challenge = Str::random(48);

        if (! $this->issue($challenge, $user)) {
            return null;
        }

        return $this->payload($challenge, $user);
    }

    /** Send a fresh code for an open challenge (not more often than every 30 seconds). */
    public function resend(string $challenge): string
    {
        $row = Cache::get($this->key($challenge));
        if (! $row) {
            return 'expired';
        }

        if (now()->timestamp - $row['sent_at'] < self::RESEND_AFTER_SECONDS) {
            return 'too_soon';
        }

        $user = User::find($row['user_id']);

        return $user && $this->issue($challenge, $user) ? 'sent' : 'failed';
    }

    /**
     * Check a code. Returns the user on success; on failure, why:
     * 'expired' (no such challenge), 'locked' (too many wrong guesses) or 'wrong'.
     *
     * @return User|string
     */
    public function verify(string $challenge, string $code): User|string
    {
        $key = $this->key($challenge);
        $row = Cache::get($key);

        if (! $row) {
            return 'expired';
        }

        $row['attempts']++;

        if (! hash_equals($row['hash'], $this->hash($code))) {
            if ($row['attempts'] >= self::MAX_ATTEMPTS) {
                Cache::forget($key);

                return 'locked';
            }
            Cache::put($key, $row, now()->addMinutes(self::TTL_MINUTES));

            return 'wrong';
        }

        Cache::forget($key);

        return User::find($row['user_id']) ?? 'expired';
    }

    // ─── Trusted devices ─────────────────────────────────────────────────────

    /** How long a trusted browser skips the code. */
    public const TRUST_DAYS = 30;
    /** A person can trust this many browsers at once; the oldest is dropped past that. */
    public const MAX_DEVICES = 10;

    /**
     * Has this browser been trusted by this admin, and is that still valid?
     * Refreshes "last used" so the list of devices stays meaningful.
     */
    public function deviceTrusted(User $user, ?string $deviceKey): bool
    {
        if (! $deviceKey) {
            return false;
        }

        $device = AdminTrustedDevice::where('user_id', $user->id)
            ->where('token_hash', hash('sha256', $deviceKey))
            ->where('expires_at', '>', now())
            ->first();

        if (! $device) {
            return false;
        }

        $device->update(['last_used_at' => now()]);

        return true;
    }

    /** Trust this browser for TRUST_DAYS. Returns the device key to hand to the browser. */
    public function trustDevice(User $user, ?string $userAgent = null): string
    {
        AdminTrustedDevice::where('user_id', $user->id)->where('expires_at', '<=', now())->delete();

        $stale = AdminTrustedDevice::where('user_id', $user->id)->orderByDesc('id')->skip(self::MAX_DEVICES - 1)->pluck('id');
        if ($stale->isNotEmpty()) {
            AdminTrustedDevice::whereIn('id', $stale)->delete();
        }

        $key = Str::random(48);
        AdminTrustedDevice::create([
            'user_id'      => $user->id,
            'token_hash'   => hash('sha256', $key),
            'label'        => $userAgent !== null ? mb_substr($userAgent, 0, 120) : null,
            'expires_at'   => now()->addDays(self::TRUST_DAYS),
            'last_used_at' => now(),
        ]);

        return $key;
    }

    /** Forget every trusted browser (a password change or reset means "start over"). */
    public function forgetDevices(User $user): void
    {
        AdminTrustedDevice::where('user_id', $user->id)->delete();
    }

    /** "ma***@example.com", so the form can say where the code went without exposing the address. */
    public function mask(string $email): string
    {
        [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($name, 0, min(2, mb_strlen($name))) . str_repeat('*', max(3, mb_strlen($name) - 2)) . '@' . $domain;
    }

    // ─── Internals ───────────────────────────────────────────────────────────

    private function issue(string $challenge, User $user): bool
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        try {
            $user->notifyNow(new AdminSignInCode($code, self::TTL_MINUTES));
        } catch (\Throwable $e) {
            Log::error('admin sign-in code email failed', ['user' => $user->id, 'error' => $e->getMessage()]);

            return false;
        }

        Cache::put($this->key($challenge), [
            'user_id'  => $user->id,
            'hash'     => $this->hash($code),
            'attempts' => 0,
            'sent_at'  => now()->timestamp,
        ], now()->addMinutes(self::TTL_MINUTES));

        return true;
    }

    /** @return array{challenge: string, email: string, expires_in: int} */
    private function payload(string $challenge, User $user): array
    {
        return [
            'challenge'  => $challenge,
            'email'      => $this->mask($user->email),
            'expires_in' => self::TTL_MINUTES * 60,
        ];
    }

    private function key(string $challenge): string
    {
        return 'admin-2fa:' . hash('sha256', $challenge);
    }

    private function hash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }
}
