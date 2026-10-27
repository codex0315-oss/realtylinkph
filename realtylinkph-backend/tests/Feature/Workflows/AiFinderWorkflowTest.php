<?php

declare(strict_types=1);

namespace Tests\Feature\Workflows;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsActors;
use Tests\TestCase;

/**
 * RealtyLink AI for buyers. Gemini is faked: the JSON call returns whatever
 * criteria a test sets, the reply call returns markdown-flavoured text that
 * echoes a prompt line, or
 * both fail. Geoapify returns one school, next to the "Lahug" listing.
 */
class AiFinderWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use BuildsActors;

    /** Criteria the fake model "extracts"; null makes Gemini fail outright. */
    private ?array $criteria = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.geoapify.api_key' => 'test-key']);

        Http::fake([
            'generativelanguage.googleapis.com/*' => function ($request) {
                if ($this->criteria === null) {
                    return Http::response(['error' => 'unavailable'], 503);
                }
                $json = isset($request['generationConfig']['responseMimeType']);

                return Http::response(['candidates' => [['content' => ['parts' => [[
                    'text' => $json ? json_encode($this->criteria) : "**Good news!** Here are a few:\n* Lahug condo\n1. Lahug condo — condo for sale, ₱4,500,000",
                ]]]]]]);
            },
            'api.geoapify.com/v2/places*' => fn ($request) => Http::response(['features' => str_contains($request->url(), 'education.school')
                ? [['properties' => ['name' => 'Lahug Elementary School', 'lat' => 10.3330, 'lon' => 123.8990]]]
                : []]),
        ]);
    }

    private function chat(string $text, array $shownIds = [])
    {
        return $this->postJson('/api/ai/chat', [
            'messages'  => [['role' => 'user', 'content' => $text]],
            'shown_ids' => $shownIds,
        ]);
    }

    public function test_near_a_school_ranks_the_closest_listing_first_and_strips_markdown(): void
    {
        $agent = $this->agent();
        $far   = $this->listing($agent, ['title' => 'Talisay house', 'lat' => 10.2400, 'lng' => 123.8400]);
        $near  = $this->listing($agent, ['title' => 'Lahug condo', 'lat' => 10.3335, 'lng' => 123.8995]);
        $this->actingAsUser($this->buyer());

        $this->criteria = ['near' => ['school']];
        $res = $this->chat('Homes near a school please')->assertOk();

        $res->assertJsonPath('data.properties.0.id', $near->id)
            ->assertJsonPath('data.properties.1.id', $far->id)
            ->assertJsonPath("data.nearby.{$near->id}.0.kind", 'school')
            ->assertJsonPath("data.nearby.{$near->id}.0.name", 'Lahug Elementary School')
            ->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.reply', "Good news! Here are a few:\n• Lahug condo");

        // Far listing is ~11 km out: beyond a school's 4 km radius, so no fact.
        $this->assertNull($res->json("data.nearby.{$far->id}"));
        $this->assertLessThan(150, $res->json("data.nearby.{$near->id}.0.distance_m"));
    }

    public function test_show_more_skips_listings_already_shown_and_reports_whats_left(): void
    {
        $agent = $this->agent();
        $ids = collect(range(1, 6))->map(fn ($i) => $this->listing($agent, ['title' => "Condo {$i}"])->id);
        $this->actingAsUser($this->buyer());

        $this->criteria = ['type' => 'condo'];
        $first = $this->chat('Condos please')->assertOk()
            ->assertJsonPath('data.total', 6)
            ->assertJsonPath('data.remaining', 2);
        $shown = collect($first->json('data.properties'))->pluck('id')->all();
        $this->assertCount(4, $shown);

        $this->criteria = ['type' => 'condo', 'more' => true];
        $next = $this->chat('Show me more', $shown)->assertOk()
            ->assertJsonPath('data.remaining', 0);
        $more = collect($next->json('data.properties'))->pluck('id')->all();

        $this->assertEqualsCanonicalizing($ids->diff($shown)->values()->all(), $more);
    }

    public function test_saved_homes_mode_answers_from_the_buyers_favorites(): void
    {
        $agent = $this->agent();
        $saved = $this->listing($agent, ['title' => 'Saved condo']);
        $this->listing($agent, ['title' => 'Other condo']);
        $this->actingAsUser($this->buyer(['favorites' => [$saved->id]]));

        $this->criteria = ['saved' => true];
        $this->chat('Compare my saved homes')->assertOk()
            ->assertJsonPath('data.mode', 'saved')
            ->assertJsonCount(1, 'data.properties')
            ->assertJsonPath('data.properties.0.id', $saved->id)
            ->assertJsonPath('data.properties.0.is_favorited', true);
    }

    public function test_without_gemini_the_keyword_backstop_still_searches(): void
    {
        $agent = $this->agent();
        $this->listing($agent, ['title' => 'Too few baths', 'bathrooms' => 1, 'bedrooms' => 3]);
        $match = $this->listing($agent, ['title' => 'Family condo', 'bathrooms' => 3, 'bedrooms' => 3, 'price' => 4_000_000]);
        $this->listing($agent, ['title' => 'Over budget', 'bathrooms' => 2, 'bedrooms' => 3, 'price' => 9_000_000]);
        $this->actingAsUser($this->buyer());

        $this->criteria = null;
        $res = $this->chat('3 bedroom condo with 2 bathrooms under 5M')->assertOk();

        $res->assertJsonCount(1, 'data.properties')
            ->assertJsonPath('data.properties.0.id', $match->id)
            ->assertJsonPath('data.criteria.bathrooms', 2)
            ->assertJsonPath('data.criteria.max_price', 5_000_000);
        $this->assertStringContainsString('Family condo', $res->json('data.reply'));
    }

    public function test_a_type_the_buyer_never_named_is_dropped(): void
    {
        $this->listing($this->agent());   // a condo
        $this->actingAsUser($this->buyer());

        $this->criteria = ['type' => 'house'];   // the model reading "homes" as house
        $this->chat('Show me homes for sale with a lot of space')->assertOk()
            ->assertJsonPath('data.criteria.type', null)
            ->assertJsonCount(1, 'data.properties');
    }

    private function geminiCalls(): int
    {
        return collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), 'generativelanguage'))->count();
    }

    public function test_switching_theme_is_an_action_answered_without_a_second_model_call(): void
    {
        $this->listing($this->agent());
        $this->actingAsUser($this->buyer());

        $this->criteria = ['action' => 'theme_dark', 'type' => 'condo'];
        $this->chat('Switch to dark mode')->assertOk()
            ->assertJsonPath('data.mode', 'action')
            ->assertJsonPath('data.action.type', 'theme')
            ->assertJsonPath('data.action.mode', 'dark')
            ->assertJsonCount(0, 'data.properties');

        $this->assertSame(1, $this->geminiCalls());   // extraction only; the reply is written in code
    }

    public function test_password_help_depends_on_whether_the_buyer_has_one(): void
    {
        $this->criteria = ['action' => 'password'];

        $this->actingAsUser($this->buyer());
        $this->chat('I want to change my password')->assertOk()
            ->assertJsonPath('data.action.type', 'password')
            ->assertJsonPath('data.action.can_change', true);

        $google = $this->buyer(['password_set' => false]);
        $this->actingAsUser($google);
        $res = $this->chat('Change my password')->assertOk()
            ->assertJsonPath('data.action.can_change', false);
        $this->assertStringContainsString('Continue with Google', $res->json('data.reply'));

        $this->getJson('/api/me')->assertJsonPath('data.password_set', false);
    }

    public function test_a_password_typed_into_the_chat_never_reaches_gemini(): void
    {
        $this->actingAsUser($this->buyer());

        $this->criteria = ['action' => 'password'];
        $this->chat('my password is Secret123 and pw: Hunter2!x, change it')->assertOk();

        foreach (Http::recorded() as [$request]) {
            $this->assertStringNotContainsString('Secret123', $request->body());
            $this->assertStringNotContainsString('Hunter2!x', $request->body());
        }
        $this->assertSame(
            'change my password please',
            \App\Services\BuyerAssistantService::redact('change my password please'),
        );
    }

    public function test_calendar_and_page_actions_with_gemini_down_use_the_keyword_backstop(): void
    {
        $this->actingAsUser($this->buyer(['google_access_token' => 'token']));
        $this->criteria = null;

        $res = $this->chat('Connect my Google Calendar')->assertOk()
            ->assertJsonPath('data.action.type', 'gcal_connect');
        $this->assertStringContainsString('already connected', $res->json('data.reply'));

        $this->chat('Take me to my appointments')->assertOk()
            ->assertJsonPath('data.action.type', 'open_page')
            ->assertJsonPath('data.action.href', '/dashboard/appointments');

        $this->chat('Turn on dark mode')->assertOk()
            ->assertJsonPath('data.action.mode', 'dark');
        $this->chat('make it light again')->assertOk()
            ->assertJsonPath('data.action.mode', 'light');
    }

    public function test_a_model_that_sees_no_action_beats_the_keywords(): void
    {
        $this->listing($this->agent());
        $this->actingAsUser($this->buyer());

        // "password" appears, but the model reads it as a search.
        $this->criteria = ['type' => 'condo', 'action' => null];
        $this->chat('Condo with a password-protected gate')->assertOk()
            ->assertJsonPath('data.mode', 'search')
            ->assertJsonCount(1, 'data.properties');
    }

    public function test_a_city_name_that_misses_the_address_widens_to_the_place_word(): void
    {
        $this->listing($this->agent(), ['title' => 'Mandaue townhouse', 'address' => 'Mandaue City, Cebu']);
        $this->actingAsUser($this->buyer());

        $this->criteria = ['location' => 'Mandaue, Cebu'];
        $this->chat('Anything in Mandaue, Cebu?')->assertOk()
            ->assertJsonCount(1, 'data.properties');
    }
}
