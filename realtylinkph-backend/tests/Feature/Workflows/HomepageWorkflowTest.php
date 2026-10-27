<?php

declare(strict_types=1);

namespace Tests\Feature\Workflows;

use App\Models\AgentReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsActors;
use Tests\TestCase;

/**
 * Landing page data — the stat tiles are live counts, and the testimonials are
 * real reviews that never expose the reviewer's contact details.
 */
class HomepageWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use BuildsActors;

    public function test_stats_count_published_listings_approved_agents_and_cities(): void
    {
        $agent = $this->agent();
        $this->listing($agent, ['address' => 'Cebu IT Park, Cebu City']);
        $this->listing($agent, ['address' => 'Banilad, Cebu City']);
        $this->listing($agent, ['address' => 'Rizal Blvd, Dumaguete City']);
        $this->listing($agent, ['address' => 'Lahug, Cebu City', 'status' => 'draft']);

        $this->getJson('/api/properties/stats')
            ->assertOk()
            ->assertJsonPath('data.listings', 3)
            ->assertJsonPath('data.agents', 1)
            ->assertJsonPath('data.cities', 2);
    }

    public function test_city_suggestions_list_published_cities_busiest_first(): void
    {
        $agent = $this->agent();
        $this->listing($agent, ['address' => 'Rizal Blvd, Dumaguete City']);
        $this->listing($agent, ['address' => 'Cebu IT Park, Cebu City']);
        $this->listing($agent, ['address' => 'Banilad, Cebu City']);
        $this->listing($agent, ['address' => 'Poblacion, Makati City', 'status' => 'draft']);

        $this->getJson('/api/properties/cities')
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'message' => 'Cities retrieved.',
                'data'    => [
                    ['name' => 'Cebu City', 'count' => 2],
                    ['name' => 'Dumaguete City', 'count' => 1],
                ],
            ]);
    }

    public function test_browse_sorts_by_price_and_treats_bedrooms_as_a_minimum(): void
    {
        $agent = $this->agent();
        $this->listing($agent, ['title' => 'Mid',   'price' => 5_000_000, 'bedrooms' => 2]);
        $this->listing($agent, ['title' => 'Cheap', 'price' => 2_000_000, 'bedrooms' => 1]);
        $this->listing($agent, ['title' => 'Big',   'price' => 9_000_000, 'bedrooms' => 3]);

        $titles = fn (string $qs) => array_column($this->getJson("/api/properties?{$qs}")->assertOk()->json('data'), 'title');

        $this->assertSame(['Cheap', 'Mid', 'Big'], $titles('sort=price_asc'));
        $this->assertSame(['Big', 'Mid', 'Cheap'], $titles('sort=price_desc'));
        $this->assertSame(['Big', 'Mid'], $titles('sort=price_desc&bedrooms=2'));   // 2+ includes the 3-bed
    }

    public function test_browse_can_show_only_listings_with_a_360_tour(): void
    {
        $agent  = $this->agent();
        $tour   = $this->listing($agent, ['title' => 'Tour']);
        $plain  = $this->listing($agent, ['title' => 'Plain']);
        $tour->photos()->create(['url' => 'https://example.test/tour.jpg', 'is_360' => true, 'sort_order' => 0]);
        $plain->photos()->create(['url' => 'https://example.test/plain.jpg', 'is_360' => false, 'sort_order' => 0]);

        $titles = fn (string $qs) => array_column($this->getJson("/api/properties?{$qs}")->assertOk()->json('data'), 'title');

        $this->assertSame(['Tour'], $titles('has_360=1'));
        $this->assertEqualsCanonicalizing(['Tour', 'Plain'], $titles('has_360=0'));
    }

    public function test_an_agents_public_reviews_never_expose_the_reviewers_contact_details(): void
    {
        $agent = $this->agent();
        $buyer = $this->buyer(['name' => 'Maria Santos', 'phone' => '09171234567']);
        AgentReview::create([
            'agent_id' => $agent->id, 'buyer_id' => $buyer->id, 'rating' => 5, 'is_visible' => true,
            'review_text' => 'Very responsive and honest about the unit.',
        ]);

        $res = $this->asGuest()->getJson("/api/agents/{$agent->id}/reviews")->assertOk()
            ->assertJsonPath('data.0.buyer.name', 'Maria Santos');
        $this->assertStringNotContainsString($buyer->email, $res->getContent());
        $this->assertStringNotContainsString('09171234567', $res->getContent());

        // The reviewer still gets their own full record back.
        $this->actingAsUser($buyer)->getJson("/api/agents/{$agent->id}/reviews")
            ->assertJsonPath('data.0.buyer.email', $buyer->email);
    }

    public function test_featured_reviews_are_real_good_and_carry_no_contact_details(): void
    {
        $agent  = $this->agent(['name' => 'Ana Agent']);
        $maria  = $this->buyer(['name' => 'Maria Clara Santos', 'phone' => '09171234567']);
        $carlo  = $this->buyer(['name' => 'Carlo Mendoza']);
        $hidden = $this->buyer();

        AgentReview::create([
            'agent_id' => $agent->id, 'buyer_id' => $maria->id, 'rating' => 5, 'is_visible' => true,
            'review_text' => 'Answered every question and the unit matched the photos exactly.',
        ]);
        // Too low, too short, and hidden — none of these belong on the homepage.
        AgentReview::create(['agent_id' => $agent->id, 'buyer_id' => $carlo->id, 'rating' => 2, 'is_visible' => true, 'review_text' => 'Slow to reply and the viewing was rescheduled twice.']);
        AgentReview::create(['agent_id' => $agent->id, 'buyer_id' => $hidden->id, 'rating' => 5, 'is_visible' => false, 'review_text' => 'Great agent, would recommend to anyone.']);

        $res = $this->asGuest()->getJson('/api/reviews/featured')->assertOk();

        $this->assertCount(1, $res->json('data'));
        $res->assertJsonPath('data.0.buyer_name', 'Maria S.')
            ->assertJsonPath('data.0.agent_name', 'Ana Agent')
            ->assertJsonPath('data.0.rating', 5);

        $body = $res->getContent();
        $this->assertStringNotContainsString($maria->email, $body);
        $this->assertStringNotContainsString('09171234567', $body);
    }
}
