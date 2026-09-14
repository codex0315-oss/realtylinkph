<?php

declare(strict_types=1);

namespace App\Models;

use App\Notifications\QueuedVerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'avatar',
        'role_type',
        'favorites',
        'theme',
        'property_alerts',
        'google_access_token',
        'google_refresh_token',
        'google_token_expires_at',
        'is_system',
    ];

    /** Email of the account RealtyLink AI posts chat replies from. */
    public const AI_EMAIL = 'ai@realtylinkph.system';

    /**
     * The RealtyLink AI system account, created on first use. Messages need a
     * real sender row; this one has an unusable password and is filtered out
     * of login, user lists and counts by `is_system`.
     */
    public static function realtyAi(): self
    {
        // forceCreate: email_verified_at is deliberately not mass-assignable
        // (Model::shouldBeStrict), and this is the one place it's legitimate.
        return static::where('email', self::AI_EMAIL)->first()
            ?? static::forceCreate([
                'name'              => 'RealtyLink AI',
                'email'             => self::AI_EMAIL,
                'password'          => \Illuminate\Support\Str::random(64),
                'role_type'         => 'agent',
                'is_system'         => true,
                'email_verified_at' => now(),
            ]);
    }

    protected $hidden = [
        'password',
        'remember_token',
        'google_access_token',
        'google_refresh_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at'       => 'datetime',
            'password'                => 'hashed',
            'favorites'               => 'array',
            'property_alerts'         => 'boolean',
            'google_token_expires_at' => 'datetime',
            'last_seen_at'            => 'datetime',
            'is_system'               => 'boolean',
        ];
    }

    /** Considered "online" if active within the last 2 minutes. */
    public function isOnline(): bool
    {
        return $this->last_seen_at !== null && $this->last_seen_at->gt(now()->subMinutes(2));
    }

    public function hasGoogleCalendar(): bool
    {
        return $this->google_access_token !== null;
    }

    /**
     * Send the email-verification notification on the queue so a slow or failing
     * mail server never blocks registration.
     */
    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new QueuedVerifyEmail());
    }

    /**
     * Send the password-reset link.
     *
     * Overrides Laravel's default, which builds its URL from a named
     * `password.reset` route. This backend is API-only and has no such route, so
     * the default threw RouteNotFoundException and /forgot-password returned 500.
     * Ours links to the Nuxt reset page instead.
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new \App\Notifications\ResetPasswordLink($token));
    }

    // Favorites (saved properties)

    public function hasFavorited(int $propertyId): bool
    {
        return in_array($propertyId, $this->favorites ?? [], true);
    }

    /**
     * Add or remove a property from the user's favorites.
     * Returns true if the property is now favorited, false if it was removed.
     */
    public function toggleFavorite(int $propertyId): bool
    {
        $favorites = $this->favorites ?? [];

        if (in_array($propertyId, $favorites, true)) {
            $this->favorites = array_values(array_diff($favorites, [$propertyId]));
            $this->save();

            return false;
        }

        $favorites[]     = $propertyId;
        $this->favorites = $favorites;
        $this->save();

        return true;
    }

    // Relations

    public function agentProfile(): HasOne
    {
        return $this->hasOne(AgentProfile::class);
    }

    public function properties(): HasMany
    {
        return $this->hasMany(Property::class, 'agent_id');
    }

    public function appointmentsAsBuyer(): HasMany
    {
        return $this->hasMany(Appointment::class, 'buyer_id');
    }

    public function appointmentsAsAgent(): HasMany
    {
        return $this->hasMany(Appointment::class, 'agent_id');
    }

    public function conversationsAsBuyer(): HasMany
    {
        return $this->hasMany(Conversation::class, 'buyer_id');
    }

    public function conversationsAsAgent(): HasMany
    {
        return $this->hasMany(Conversation::class, 'agent_id');
    }

    public function inquiries(): HasMany
    {
        return $this->hasMany(Inquiry::class);
    }

    public function reviewsGiven(): HasMany
    {
        return $this->hasMany(AgentReview::class, 'buyer_id');
    }

    public function reviewsReceived(): HasMany
    {
        return $this->hasMany(AgentReview::class, 'agent_id');
    }

    public function blockedDates(): HasMany
    {
        return $this->hasMany(AgentBlockedDate::class, 'agent_id');
    }

    // Helpers

    public function isAgent(): bool
    {
        return $this->role_type === 'agent';
    }

    public function isAdmin(): bool
    {
        return in_array($this->role_type, ['admin', 'super_admin'], true);
    }

    public function isVerifiedAgent(): bool
    {
        return $this->isAgent()
            && $this->agentProfile?->status === 'approved';
    }
}
