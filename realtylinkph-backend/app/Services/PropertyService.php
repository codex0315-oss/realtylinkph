<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\GeocodeProperty;
use App\Models\Property;
use App\Models\User;
use App\Notifications\NewPropertyAlert;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class PropertyService
{
    /** A city earns its own homepage row once it has this many published listings. */
    private const CITY_ROW_MIN = 3;

    /** Most listings shown per homepage row. */
    private const ROW_LIMIT = 12;

    /**
     * Rows for the browse-first homepage, Airbnb-style.
     *
     * Grouping is automatic: any city with CITY_ROW_MIN+ listings gets its own
     * row ("Homes in Cebu City"), then always-full theme rows (newest, for
     * sale, for rent, by type). Rows with nothing in them are dropped, so the
     * page never shows an empty shelf, and it grows more city-specific as
     * agents add listings. Each row links to Browse with the matching filter.
     *
     * @return array<int, array{key:string, title:string, href:string, properties:\Illuminate\Support\Collection}>
     */
    public function homeRows(): array
    {
        $all = Property::with(['agent.agentProfile', 'photos'])
            ->published()
            ->latest()
            ->limit(120)
            ->get();

        $rows = [];

        // ── City rows ──
        $byCity = $all->groupBy(fn (Property $p) => self::cityOf($p->address))
            ->filter(fn ($group, $city) => $city !== '' && $group->count() >= self::CITY_ROW_MIN)
            ->sortByDesc(fn ($group) => $group->count())
            ->take(4);

        foreach ($byCity as $city => $group) {
            $rows[] = [
                'key'        => 'city-' . \Illuminate\Support\Str::slug($city),
                'title'      => "Homes in {$city}",
                'href'       => '/properties?search=' . rawurlencode($city),
                'properties' => $group->take(self::ROW_LIMIT)->values(),
            ];
        }

        // ── Theme rows ──
        $themes = [
            ['key' => 'newest',  'title' => 'Newest listings',    'href' => '/properties',                 'filter' => fn (Property $p) => true],
            ['key' => 'sale',    'title' => 'For sale',           'href' => '/properties?offer_type=sale', 'filter' => fn (Property $p) => $p->offer_type !== 'rent'],
            ['key' => 'rent',    'title' => 'For rent',           'href' => '/properties?offer_type=rent', 'filter' => fn (Property $p) => $p->offer_type === 'rent'],
            ['key' => 'condo',   'title' => 'Condos',             'href' => '/properties?type=condo',      'filter' => fn (Property $p) => $p->type === 'condo'],
            ['key' => 'house',   'title' => 'Houses',             'href' => '/properties?type=house',      'filter' => fn (Property $p) => $p->type === 'house'],
            ['key' => 'lot',     'title' => 'Lots',               'href' => '/properties?type=lot',        'filter' => fn (Property $p) => $p->type === 'lot'],
        ];

        foreach ($themes as $t) {
            $items = $all->filter($t['filter'])->take(self::ROW_LIMIT)->values();
            if ($items->isEmpty()) {
                continue;
            }
            $rows[] = ['key' => $t['key'], 'title' => $t['title'], 'href' => $t['href'], 'properties' => $items];
        }

        return $rows;
    }

    /**
     * Best-effort city from a free-text address: the comma segment that says
     * "City" ("Solinea Tower 3, Cebu Business Park, Cebu City" → "Cebu City"),
     * otherwise the last segment.
     */
    public static function cityOf(?string $address): string
    {
        if (! $address) {
            return '';
        }
        $parts = array_values(array_filter(array_map('trim', explode(',', $address))));
        if (! $parts) {
            return '';
        }
        foreach ($parts as $part) {
            if (preg_match('/\bcity\b/i', $part)) {
                return ucwords(strtolower($part));
            }
        }

        return ucwords(strtolower(end($parts)));
    }
    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        // agentProfile is needed for the "Verified" badge on property cards —
        // without it the relation is never serialised and the badge never shows.
        $query = Property::with(['agent.agentProfile', 'photos'])
            ->published();

        if (! empty($filters['type'])) {
            $query->ofType($filters['type']);
        }

        if (! empty($filters['offer_type'])) {
            $query->where('offer_type', $filters['offer_type']);
        }

        if (! empty($filters['min_price'])) {
            $query->where('price', '>=', $filters['min_price']);
        }

        if (! empty($filters['max_price'])) {
            $query->where('price', '<=', $filters['max_price']);
        }

        if (! empty($filters['bedrooms'])) {
            $query->where('bedrooms', $filters['bedrooms']);
        }

        if (! empty($filters['bathrooms'])) {
            $query->where('bathrooms', $filters['bathrooms']);
        }

        if (! empty($filters['search'])) {
            $query->where(function ($q) use ($filters): void {
                $q->where('title', 'ilike', "%{$filters['search']}%")
                    ->orWhere('address', 'ilike', "%{$filters['search']}%");
            });
        }

        return $query->latest()->paginate($perPage);
    }

    public function listForAgent(User $agent, int $perPage = 15): LengthAwarePaginator
    {
        // Active listings only — sold ones move to the Inventory view.
        return Property::with(['photos'])
            ->forAgent($agent->id)
            ->where('status', '!=', 'sold')
            ->latest()
            ->paginate($perPage);
    }

    /**
     * Homepage "Featured" set — top listings by merit score, with a fairness cap
     * of one per agent. Falls back to most-recent published if nothing is scored yet.
     *
     * @return \Illuminate\Support\Collection<int, Property>
     */
    public function featured(int $limit = 4): \Illuminate\Support\Collection
    {
        $pool = Property::with(['agent.agentProfile', 'photos'])
            ->published()
            ->where('featured_score', '>', 0)
            ->orderByDesc('featured_score')
            ->limit($limit * 5)
            ->get();

        $picked = collect();
        $agents = [];
        foreach ($pool as $p) {
            if (in_array($p->agent_id, $agents, true)) {
                continue;   // one featured listing per agent
            }
            $picked->push($p);
            $agents[] = $p->agent_id;
            if ($picked->count() >= $limit) {
                break;
            }
        }

        // Too few distinct agents — backfill from the same scored pool.
        if ($picked->count() < $limit) {
            foreach ($pool as $p) {
                if ($picked->contains('id', $p->id)) {
                    continue;
                }
                $picked->push($p);
                if ($picked->count() >= $limit) {
                    break;
                }
            }
        }

        // Nothing scored yet (e.g. before the first run) → graceful fallback.
        $picked = $picked->isEmpty()
            ? Property::with(['agent.agentProfile', 'photos'])->published()->latest()->limit($limit)->get()
            : $picked->take($limit)->values();

        // Flag them so the card can show a "Featured" badge (transient, not stored).
        $picked->each(fn (Property $p) => $p->setAttribute('is_featured', true));

        return $picked;
    }

    /** Sold/rented listings — the agent's Inventory. */
    public function listSoldForAgent(User $agent, int $perPage = 15): LengthAwarePaginator
    {
        return Property::with(['photos'])
            ->forAgent($agent->id)
            ->sold()
            ->latest('sold_at')
            ->paginate($perPage);
    }

    public function create(User $agent, array $data): Property
    {
        $property = DB::transaction(function () use ($agent, $data): Property {
            return Property::create([...$data, 'agent_id' => $agent->id]);
        });

        if ($this->needsGeocode($data, null)) {
            GeocodeProperty::dispatch($property->id);   // background — save stays instant
        }

        return $property;
    }

    public function update(Property $property, array $data): Property
    {
        $needsGeocode = $this->needsGeocode($data, $property);

        $property = DB::transaction(function () use ($property, $data): Property {
            $property->update($data);

            return $property->fresh(['agent', 'photos']);
        });

        if ($needsGeocode) {
            GeocodeProperty::dispatch($property->id);
        }

        return $property;
    }

    /**
     * Should we geocode the address in the background? No when explicit coordinates
     * were supplied; on update, only when the address changed (or coords are missing).
     */
    private function needsGeocode(array $data, ?Property $existing): bool
    {
        if (! empty($data['lat']) && ! empty($data['lng'])) {
            return false;   // respects an explicit pin
        }

        $address = $data['address'] ?? $existing?->address;
        if (! $address) {
            return false;
        }

        $addressChanged = $existing !== null && array_key_exists('address', $data) && $data['address'] !== $existing->address;

        return $existing === null || $addressChanged || empty($existing->lat) || empty($existing->lng);
    }

    public function delete(Property $property): void
    {
        DB::transaction(function () use ($property): void {
            $property->photos()->each(function ($photo): void {
                app(PropertyPhotoService::class)->delete($photo);
            });

            $property->delete();
        });
    }

    public function publish(Property $property): Property
    {
        $wasPublished = $property->status === 'published';

        // Re-publishing resolves an admin take-down, so clear the reason too.
        $property->update([
            'status'           => 'published',
            'unpublish_reason' => null,
            'unpublished_at'   => null,
        ]);

        // Only alert buyers on the first publish (draft → published).
        if (! $wasPublished) {
            $this->notifyBuyersOfNewListing($property->fresh());
        }

        return $property->fresh();
    }

    /**
     * Email opted-in buyers about a newly published listing (queued).
     */
    protected function notifyBuyersOfNewListing(Property $property): void
    {
        User::query()
            ->whereIn('role_type', ['buyer', 'ghost_buyer'])
            ->where('property_alerts', true)
            ->chunkById(100, function ($buyers) use ($property): void {
                Notification::send($buyers, new NewPropertyAlert($property));
            });
    }

    /** Mark a listing sold/rented — moves it out of active listings into Inventory. */
    public function markSold(Property $property): Property
    {
        $property->update(['status' => 'sold', 'sold_at' => now()]);

        return $property->fresh();
    }

    /** Re-list a sold property — returns it to a draft so the agent can edit before publishing. */
    public function relist(Property $property): Property
    {
        $property->update(['status' => 'draft', 'sold_at' => null]);

        return $property->fresh();
    }

    public function unpublish(Property $property): Property
    {
        $property->update(['status' => 'draft']);

        return $property->fresh();
    }
}
