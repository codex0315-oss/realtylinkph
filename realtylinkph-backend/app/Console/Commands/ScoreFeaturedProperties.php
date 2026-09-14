<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Property;
use App\Services\FeaturedScoreService;
use Illuminate\Console\Command;

class ScoreFeaturedProperties extends Command
{
    protected $signature = 'properties:score-featured';

    protected $description = 'Recompute the merit-based featured_score for every property.';

    public function handle(FeaturedScoreService $service): int
    {
        $n = $service->recomputeAll();
        $this->info("Scored {$n} propert(y/ies).");

        $top = Property::published()->where('featured_score', '>', 0)
            ->orderByDesc('featured_score')->limit(8)->get(['title', 'featured_score']);

        if ($top->isEmpty()) {
            $this->warn('No properties are currently featurable (check the eligibility gates).');
        } else {
            $this->line('Top featured:');
            $top->each(fn (Property $p) => $this->line('  ' . str_pad((string) round($p->featured_score, 1), 5, ' ', STR_PAD_LEFT) . '  ' . $p->title));
        }

        return self::SUCCESS;
    }
}
