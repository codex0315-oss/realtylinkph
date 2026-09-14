<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Property extends Model
{
    protected $fillable = [
        'agent_id',
        'title',
        'description',
        'price',
        'type',
        'offer_type',
        'bedrooms',
        'bathrooms',
        'floor_area',
        'lot_area',
        'address',
        'lat',
        'lng',
        'status',
        'sold_at',
        'featured_score',
        'unpublish_reason',
        'unpublished_at',
    ];

    protected function casts(): array
    {
        return [
            'price'      => 'decimal:2',
            'floor_area' => 'decimal:2',
            'lot_area'   => 'decimal:2',
            'lat'        => 'decimal:7',
            'lng'        => 'decimal:7',
            'sold_at'    => 'datetime',
            'featured_score' => 'float',
            'unpublished_at' => 'datetime',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(PropertyPhoto::class)->orderBy('sort_order');
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function inquiries(): HasMany
    {
        return $this->hasMany(Inquiry::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published');
    }

    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', 'draft');
    }

    public function scopeSold(Builder $query): Builder
    {
        return $query->where('status', 'sold');
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    public function scopeForAgent(Builder $query, int $agentId): Builder
    {
        return $query->where('agent_id', $agentId);
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->agent_id === $user->id;
    }

    // Sanity thresholds — a price that almost certainly doesn't fit the offer type.
    public const RENT_MAX_SANE = 1_000_000;   // ₱1M+/month rent is almost surely a mis-entry
    public const SALE_MIN_SANE = 100_000;     // a ₱<100k "sale" is almost surely a mis-entry

    /**
     * Heuristic: does the price look wrong for the offer type? (e.g. a rental
     * priced like a sale.) Used by the listing-form nudge and the price audit.
     */
    public function priceLooksOff(): bool
    {
        $price = (float) $this->price;
        if ($price <= 0) {
            return false;
        }

        return $this->offer_type === 'rent'
            ? $price >= self::RENT_MAX_SANE
            : $price < self::SALE_MIN_SANE;
    }
}
