<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Property;
use Illuminate\Console\Command;

class AuditPropertyPrices extends Command
{
    protected $signature = 'properties:audit';

    protected $description = 'Flag listings whose price looks wrong for their offer type (e.g. a rental priced like a sale).';

    public function handle(): int
    {
        // Pull the candidates with a cheap query, then apply the shared heuristic.
        $flagged = Property::query()
            ->where(function ($q): void {
                $q->where(fn ($w) => $w->where('offer_type', 'rent')->where('price', '>=', Property::RENT_MAX_SANE))
                    ->orWhere(fn ($w) => $w->where('offer_type', 'sale')->where('price', '>', 0)->where('price', '<', Property::SALE_MIN_SANE));
            })
            ->with('agent:id,name')
            ->get(['id', 'agent_id', 'title', 'offer_type', 'price', 'status']);

        if ($flagged->isEmpty()) {
            $this->info('No price anomalies found. ✔');

            return self::SUCCESS;
        }

        $this->warn("Found {$flagged->count()} listing(s) with a suspicious price:");
        $this->newLine();

        $this->table(
            ['ID', 'Title', 'Offer', 'Price', 'Status', 'Agent', 'Why'],
            $flagged->map(fn (Property $p): array => [
                $p->id,
                \Illuminate\Support\Str::limit($p->title, 40),
                $p->offer_type,
                '₱' . number_format((float) $p->price),
                $p->status,
                $p->agent?->name ?? '—',
                $p->offer_type === 'rent' ? 'rent priced like a sale' : 'sale price too low',
            ])->all(),
        );

        $this->newLine();
        $this->line('Ask each agent to correct the price or offer type (or fix via the admin Listings page).');

        return self::SUCCESS;
    }
}
