<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AgentReview;
use App\Models\Property;
use Illuminate\Support\Facades\DB;

/**
 * Computes the merit-based "featured_score" (0–100) for properties.
 *
 * Gates (must pass all, else score = 0): published, PRC-verified agent, has a
 * photo, has a description, and a sane price (passes the price audit).
 *
 * Score weights: completeness 30, agent trust 25, engagement 25, freshness 20.
 */
class FeaturedScoreService
{
    public function recomputeAll(): int
    {
        $ratings     = $this->ratingMap();
        $properties  = Property::with(['photos', 'agent.agentProfile'])->get();
        $maxVelocity = $this->maxVelocity($properties);

        $updated = 0;
        foreach ($properties as $p) {
            $score = $this->gateFailures($p) === []
                ? $this->totalScore($this->factors($p, $ratings, $maxVelocity))
                : 0.0;

            DB::table('properties')->where('id', $p->id)->update(['featured_score' => round($score, 2)]);
            $updated++;
        }

        return $updated;
    }

    /**
     * Self-contained breakdown for one property — used by the admin "why featured"
     * view. Loads what it needs (agent rating + platform max velocity).
     *
     * @return array{eligible:bool, total:float, gate_failures:array<int,string>, factors:array}
     */
    public function breakdownFor(Property $property): array
    {
        $property->loadMissing(['photos', 'agent.agentProfile']);

        $ratings     = $this->ratingMap();
        $maxVelocity = $this->maxVelocity(Property::with(['photos', 'agent.agentProfile'])->published()->get());

        $gateFailures = $this->gateFailures($property);
        $factors      = $this->factors($property, $ratings, $maxVelocity);

        return [
            'eligible'      => $gateFailures === [],
            'total'         => round($gateFailures === [] ? $this->totalScore($factors) : 0.0, 1),
            'gate_failures' => $gateFailures,
            'factors'       => $factors,
        ];
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function ratingMap()
    {
        return AgentReview::query()->visible()
            ->selectRaw('agent_id, AVG(rating) as avg_rating, COUNT(*) as review_count')
            ->groupBy('agent_id')
            ->get()
            ->keyBy('agent_id');
    }

    private function maxVelocity($properties): float
    {
        $vels = [];
        foreach ($properties as $p) {
            if ($this->gateFailures($p) === []) {
                $vels[] = $this->velocity($p);
            }
        }

        return $vels !== [] ? max($vels) : 0.0;
    }

    /** @return array<int, string> Empty when the listing is eligible to be featured. */
    private function gateFailures(Property $p): array
    {
        $f = [];
        if ($p->status !== 'published') {
            $f[] = 'Not published';
        }
        if ($p->agent?->agentProfile?->status !== 'approved') {
            $f[] = 'Agent is not PRC-verified';
        }
        if ($p->photos->isEmpty()) {
            $f[] = 'No photos';
        }
        if (! filled($p->description)) {
            $f[] = 'No description';
        }
        if ($p->priceLooksOff()) {
            $f[] = 'Price looks wrong for its offer type';
        }

        return $f;
    }

    private function velocity(Property $p): float
    {
        $days = max(1, (int) ($p->created_at?->diffInDays(now()) ?? 1));

        return (int) $p->views / $days;
    }

    private function totalScore(array $factors): float
    {
        return array_sum(array_column($factors, 'score'));
    }

    /**
     * The four scored factors, each with its points and human-readable reasons.
     *
     * @return array<int, array{key:string,label:string,score:float,max:int,notes:array<int,string>}>
     */
    private function factors(Property $p, $ratings, float $maxVelocity): array
    {
        // ── Completeness (30) ──
        $photoCount = $p->photos->count();
        $has360     = $p->photos->contains(fn ($ph): bool => (bool) $ph->is_360);
        $specs      = collect([$p->bedrooms > 0, $p->bathrooms > 0, filled($p->floor_area), $p->lat && $p->lng])->filter()->count();

        $completeness = min($photoCount, 3) / 3 * 12 + ($has360 ? 10 : 0) + $specs / 4 * 8;
        $cNotes = [$photoCount . ' photo' . ($photoCount === 1 ? '' : 's')];
        if ($has360) {
            $cNotes[] = '360° tour';
        }
        $cNotes[] = $specs . '/4 key specs filled';

        // ── Agent trust (25) ──
        $r     = $ratings->get($p->agent_id);
        $avg   = $r ? (float) $r->avg_rating : 0.0;
        $count = $r ? (int) $r->review_count : 0;
        $trust = ($avg / 5) * 15 + min(log(1 + $count) / log(1 + 20), 1) * 10;
        $tNotes = ['PRC-verified agent'];
        $tNotes[] = $count > 0 ? round($avg, 1) . '★ from ' . $count . ' review' . ($count === 1 ? '' : 's') : 'No reviews yet';

        // ── Engagement (25) ──
        $vel        = $this->velocity($p);
        $engagement = $maxVelocity > 0 ? ($vel / $maxVelocity) * 25 : 0.0;
        $eNotes     = [(int) $p->views . ' views (~' . round($vel, 1) . '/day)'];

        // ── Freshness (20) ──
        $days      = (int) ($p->created_at?->diffInDays(now()) ?? 0);
        $freshness = 20 * exp(-$days / 30);
        $fNotes    = ['Listed ' . ($days === 0 ? 'today' : $days . ' day' . ($days === 1 ? '' : 's') . ' ago')];

        return [
            ['key' => 'completeness', 'label' => 'Listing completeness', 'score' => round($completeness, 1), 'max' => 30, 'notes' => $cNotes],
            ['key' => 'trust',        'label' => 'Agent trust',          'score' => round($trust, 1),        'max' => 25, 'notes' => $tNotes],
            ['key' => 'engagement',   'label' => 'Engagement',           'score' => round($engagement, 1),   'max' => 25, 'notes' => $eNotes],
            ['key' => 'freshness',    'label' => 'Freshness',            'score' => round($freshness, 1),    'max' => 20, 'notes' => $fNotes],
        ];
    }
}
