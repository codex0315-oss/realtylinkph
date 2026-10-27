<?php

declare(strict_types=1);

namespace Tests\Feature\Workflows;

use App\Models\AgentReview;
use App\Models\Appointment;
use App\Models\Inquiry;
use App\Models\PropertyPhoto;
use App\Services\GeminiService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsActors;
use Tests\TestCase;

/**
 * RealtyLink AI for agents. Gemini is down unless a test sets `$draft`, so
 * everything but drafting runs on the keyword reader and code-written
 * replies, the way it must when the free quota runs out. Nothing in the
 * account changes from the chat: tasks come back as cards to approve.
 */
class AgentAssistantWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use BuildsActors;

    /** What the fake Gemini writes for a draft; null = Gemini unavailable. */
    private ?string $draft = null;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake([
            'generativelanguage.googleapis.com/*' => fn () => $this->draft === null
                ? Http::response(['error' => 'unavailable'], 503)
                : Http::response(['candidates' => [['content' => ['parts' => [['text' => $this->draft]]]]]]),
        ]);
    }

    private function ask($agent, string $text)
    {
        return $this->actingAsUser($agent)->postJson('/api/ai/agent-chat', [
            'messages' => json_encode([['role' => 'user', 'content' => $text]]),
        ])->assertOk();
    }

    private function photos($listing, int $n, bool $with360 = false): void
    {
        for ($i = 0; $i < $n; $i++) {
            PropertyPhoto::create(['property_id' => $listing->id, 'url' => "listings/p{$listing->id}-{$i}.jpg", 'sort_order' => $i, 'is_360' => false]);
        }
        if ($with360) {
            PropertyPhoto::create(['property_id' => $listing->id, 'url' => "listings/p{$listing->id}-360.jpg", 'sort_order' => 99, 'is_360' => true]);
        }
    }

    public function test_theme_commands_including_turn_off_are_read_without_the_model(): void
    {
        $agent = $this->agent();

        $this->ask($agent, 'turn of light mode')->assertJsonPath('data.action.type', 'theme')->assertJsonPath('data.action.mode', 'dark');
        $this->ask($agent, 'turn off dark mode')->assertJsonPath('data.action.mode', 'light');
        $this->ask($agent, 'turn on dark mode')->assertJsonPath('data.action.mode', 'dark');

        // The buyer assistant reads it the same way.
        $this->assertSame('theme_dark', GeminiService::themeAction('Turn off light mode please'));
        $this->assertSame('theme_light', GeminiService::themeAction('switch to light mode'));
    }

    public function test_listings_come_back_as_cards_most_viewed_first_and_only_their_own(): void
    {
        $agent = $this->agent();
        $this->listing($agent, ['title' => 'Quiet Lahug house'])->forceFill(['views' => 3])->save();
        $this->listing($agent, ['title' => 'Busy BGC condo'])->forceFill(['views' => 40])->save();
        $this->listing($this->agent(), ['title' => 'Someone else’s'])->forceFill(['views' => 900])->save();

        $res = $this->ask($agent, 'Which of my listings has the most views?')
            ->assertJsonPath('data.intent', 'listings')
            ->assertJsonPath('data.card.type', 'listings')
            ->assertJsonPath('data.card.total', 2)
            ->assertJsonPath('data.card.items.0.title', 'Busy BGC condo');
        $this->assertStringContainsString('Busy BGC condo', $res->json('data.reply'));
    }

    public function test_confirming_a_named_viewing_is_a_card_to_approve_and_changes_nothing(): void
    {
        $agent = $this->agent();
        $home  = $this->listing($agent);
        $maria = $this->buyer(['name' => 'Maria Santos']);
        $jose  = $this->buyer(['name' => 'Jose Cruz']);
        $a = Appointment::create(['property_id' => $home->id, 'buyer_id' => $maria->id, 'agent_id' => $agent->id, 'preferred_datetime' => now()->addDays(2)->setTime(14, 0), 'status' => 'pending']);
        Appointment::create(['property_id' => $home->id, 'buyer_id' => $jose->id, 'agent_id' => $agent->id, 'preferred_datetime' => now()->addDays(3)->setTime(10, 0), 'status' => 'pending']);

        $this->ask($agent, "Confirm Maria's viewing")
            ->assertJsonPath('data.card.type', 'task')
            ->assertJsonPath('data.card.task', 'confirm_viewing')
            ->assertJsonPath('data.card.args.appointment_id', $a->id);
        $this->assertSame('pending', $a->fresh()->status);

        // Two waiting and no name: it asks, listing both with Confirm buttons.
        $this->ask($agent, 'confirm the viewing request')
            ->assertJsonPath('data.card.type', 'viewings')
            ->assertJsonPath('data.card.total', 2);
    }

    public function test_blocking_reads_days_periods_and_hour_ranges(): void
    {
        $agent    = $this->agent();
        $tomorrow = CarbonImmutable::today()->addDay()->toDateString();

        $this->ask($agent, 'block tomorrow afternoon')
            ->assertJsonPath('data.card.task', 'block_hours')
            ->assertJsonPath('data.card.args', ['date' => $tomorrow, 'start' => '13:00', 'end' => '17:00']);

        $this->ask($agent, 'block tomorrow 2-5pm')->assertJsonPath('data.card.args.start', '14:00')->assertJsonPath('data.card.args.end', '17:00');

        $this->ask($agent, "I'm not available tomorrow")
            ->assertJsonPath('data.card.task', 'block_day')
            ->assertJsonPath('data.card.args.date', $tomorrow);

        $this->ask($agent, 'block a day for me')->assertJsonPath('data.card', null);
        $this->assertDatabaseCount('agent_blocked_dates', 0);
    }

    public function test_publishing_checks_the_listing_first_and_names_the_wrong_state(): void
    {
        $agent = $this->agent();
        $ready = $this->listing($agent, ['title' => 'Mabolo townhouse', 'status' => 'draft']);
        $this->photos($ready, 1);
        $this->listing($agent, ['title' => 'Talamban lot', 'status' => 'draft', 'type' => 'lot']);   // no photo
        $this->listing($agent, ['title' => 'Banilad condo', 'status' => 'published']);

        $this->ask($agent, 'publish the Mabolo townhouse')
            ->assertJsonPath('data.card.task', 'publish')
            ->assertJsonPath('data.card.args.property_id', $ready->id);
        $this->assertSame('draft', $ready->fresh()->status);

        $this->ask($agent, 'publish Talamban lot')
            ->assertJsonPath('data.card.type', 'action_link')
            ->assertJsonPath('data.card.href', fn ($href) => str_ends_with($href, '/edit'));

        $res = $this->ask($agent, 'publish the Banilad condo');
        $this->assertStringContainsString('already live', $res->json('data.reply'));

        // Two drafts, none named: asks which, with a one-tap pick.
        $this->ask($agent, 'publish my draft')->assertJsonPath('data.card.pick.task', 'publish');
    }

    public function test_a_number_in_the_title_tells_similar_listings_apart(): void
    {
        $agent = $this->agent();
        $three = $this->listing($agent, ['title' => 'Solinea Tower 3']);
        $this->listing($agent, ['title' => '2BR CORNER UNIT FOR SALE AT SOLINEA TOWER 1']);

        $this->ask($agent, 'mark the Solinea Tower 3 as sold')
            ->assertJsonPath('data.card.task', 'mark_sold')
            ->assertJsonPath('data.card.args.property_id', $three->id);
    }

    public function test_price_check_compares_with_similar_listings_nearby(): void
    {
        $agent = $this->agent();
        $mine  = $this->listing($agent, ['title' => 'Pricey IT Park condo', 'price' => 9_000_000, 'floor_area' => 50]);
        $other = $this->agent();
        foreach ([4_000_000, 4_500_000, 5_000_000] as $price) {
            $this->listing($other, ['price' => $price, 'floor_area' => 50]);
        }

        $res = $this->ask($agent, 'Is my Pricey IT Park condo priced right?')
            ->assertJsonPath('data.card.type', 'price_check')
            ->assertJsonPath('data.card.verdict', 'above')
            ->assertJsonPath('data.card.count', 3)
            ->assertJsonPath('data.card.area_name', 'Cebu IT Park');
        $this->assertGreaterThan(50, $res->json('data.card.diff_pct'));

        // A quoted title that happens to read like a description is still theirs.
        $studio = $this->listing($agent, ['title' => 'STUDIO UNIT FOR SALE at Cebu IT Park', 'price' => 4_400_000, 'floor_area' => 50]);
        $this->ask($agent, 'Price check “STUDIO UNIT FOR SALE at Cebu IT Park”')
            ->assertJsonPath('data.card.listing.id', $studio->id)
            ->assertJsonPath('data.card.verdict', 'in_line');

        // A property that isn't listed yet.
        $this->ask($agent, 'Suggest a price for a 50sqm condo in Cebu City')
            ->assertJsonPath('data.card.type', 'price_check')
            ->assertJsonPath('data.card.listing', null)
            ->assertJsonPath('data.card.low', fn ($low) => $low >= 4_000_000 && $low <= 5_000_000);
    }

    public function test_listing_check_lists_fixes(): void
    {
        $agent = $this->agent();
        $thin  = $this->listing($agent, ['title' => 'Thin listing', 'floor_area' => null]);
        $this->photos($thin, 2);
        $full = $this->listing($agent, ['title' => 'Full listing', 'description' => str_repeat('A lovely home with a garden. ', 10)]);
        $this->photos($full, 6, true);

        $res = $this->ask($agent, 'check my listings')->assertJsonPath('data.card.type', 'listing_check');
        $items = $res->json('data.card.items');
        $this->assertCount(1, $items);
        $this->assertSame('Thin listing', $items[0]['listing']['title']);
        $this->assertEqualsCanonicalizing(['few_photos', 'no_360', 'short_description', 'no_area'], array_column($items[0]['issues'], 'code'));
    }

    public function test_reply_drafts_are_written_for_the_agent_to_send_with_a_fallback(): void
    {
        $agent = $this->agent(['name' => 'Ana Reyes']);
        $home  = $this->listing($agent);
        $inq   = Inquiry::create(['property_id' => $home->id, 'name' => 'Leo Tan', 'email' => 'leo@example.com', 'message' => 'Is parking included?', 'is_read' => false]);

        $this->draft = 'Hi Leo, yes, one parking slot is included. Would you like to book a viewing? Ana';
        $this->ask($agent, 'draft a reply to the inquiry')
            ->assertJsonPath('data.card.type', 'draft')
            ->assertJsonPath('data.card.kind', 'inquiry')
            ->assertJsonPath('data.card.target_id', $inq->id)
            ->assertJsonPath('data.card.email', 'leo@example.com')
            ->assertJsonPath('data.card.text', $this->draft);

        // Gemini down: still a usable draft.
        $this->draft = null;
        $buyer = $this->buyer(['name' => 'Rita Lim']);
        $appt  = Appointment::create(['property_id' => $home->id, 'buyer_id' => $buyer->id, 'agent_id' => $agent->id, 'preferred_datetime' => now()->subDay(), 'status' => 'completed']);
        AgentReview::create(['appointment_id' => $appt->id, 'buyer_id' => $buyer->id, 'agent_id' => $agent->id, 'rating' => 2, 'review_text' => 'Came late.', 'is_visible' => true]);

        $res = $this->ask($agent, "write a reply to Rita's review")->assertJsonPath('data.card.kind', 'review');
        $this->assertStringContainsString('Rita', $res->json('data.card.text'));
        $this->assertSame(0, AgentReview::whereNotNull('agent_reply')->count());
    }

    public function test_capabilities_and_pages(): void
    {
        $agent = $this->agent();

        $this->ask($agent, 'what can you do?')->assertJsonPath('data.action.type', 'capabilities');
        $this->ask($agent, 'open my calendar')
            ->assertJsonPath('data.action.type', 'open_page')
            ->assertJsonPath('data.action.href', '/dashboard/calendar');
    }
}
