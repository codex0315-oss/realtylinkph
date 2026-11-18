<?php

declare(strict_types=1);

namespace Tests\Feature\Workflows;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsActors;
use Tests\TestCase;

/** "Write it with AI": Plain (default) or With emoji, kept tidy and professional. */
class DescriptionStyleWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use BuildsActors;

    private function aiReturns(string $text): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => $text]]]]]])]);
    }

    public function test_the_emoji_style_keeps_only_property_emoji_and_at_most_five(): void
    {
        $this->aiReturns("**Bright condo** near IT Park 🔥😍\n\n- 🛏️ 2 bedrooms\n- 🛁 1 bath\n- 📍 5 mins to Ayala\n- ✨ Furnished 💯\n- 🌳 Garden view\n- 🚗 Parking");

        $desc = $this->actingAsUser($this->agent())
            ->postJson('/api/ai/generate-description', ['type' => 'condo', 'address' => 'IT Park, Cebu City', 'style' => 'emoji'])
            ->assertOk()->json('data.description');

        $this->assertStringNotContainsString('🔥', $desc);
        $this->assertStringNotContainsString('**', $desc);
        $this->assertStringStartsWith('Bright condo', $desc);
        $this->assertStringContainsString("\n🛏️ 2 bedrooms", $desc);
        $this->assertSame(5, preg_match_all('/\p{Extended_Pictographic}/u', $desc));
        Http::assertSent(fn ($r) => str_contains(json_encode($r->data(), JSON_UNESCAPED_UNICODE), 'highlight lines'));
    }

    /** Gemini busy on photos → written from the details instead, and the agent is told. */
    public function test_when_photos_cannot_be_read_it_writes_from_the_details(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'api.groq.com')) {
                return Http::response([], 500);
            }

            return str_contains($request->body(), 'inline_data')
                ? Http::response(['error' => 'overloaded'], 503)
                : Http::response(['candidates' => [['content' => ['parts' => [['text' => 'A bright two-bedroom condo near IT Park.']]]]]]);
        });

        $photo = \Illuminate\Http\UploadedFile::fake()->image('living.jpg', 1200, 800);
        $this->actingAsUser($this->agent())
            ->post('/api/ai/generate-description', ['type' => 'condo', 'address' => 'IT Park', 'photos' => [$photo]], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.description', 'A bright two-bedroom condo near IT Park.')
            ->assertJsonPath('data.used_photos', false);

        // Photos tried on both models (busy → retry on the lighter one), then the details.
        Http::assertSentCount(3);
    }

    public function test_a_clear_busy_answer_when_every_route_fails(): void
    {
        Http::fake(['*' => Http::response(['error' => 'quota'], 429)]);
        $this->actingAsUser($this->agent())->postJson('/api/ai/generate-description', ['type' => 'condo', 'address' => 'IT Park'])
            ->assertStatus(503)->assertJsonPath('message', 'RealtyLink AI is busy right now. Please try again in 30 seconds.');
    }

    public function test_repeat_clicks_with_the_same_details_use_the_cache(): void
    {
        $this->aiReturns('A bright two-bedroom condo near IT Park.');
        $this->actingAsUser($this->agent())->postJson('/api/ai/generate-description', ['type' => 'condo', 'address' => 'Mabolo'])->assertOk();
        $this->postJson('/api/ai/generate-description', ['type' => 'condo', 'address' => 'Mabolo'])->assertOk()
            ->assertJsonPath('data.description', 'A bright two-bedroom condo near IT Park.');
        Http::assertSentCount(1);   // the second click came from the 10-minute cache
    }

    public function test_plain_is_the_default_and_unknown_styles_are_refused(): void
    {
        $this->aiReturns('A bright two-bedroom condo near IT Park.');
        $agent = $this->agent();

        $this->actingAsUser($agent)->postJson('/api/ai/generate-description', ['type' => 'condo', 'address' => 'IT Park'])
            ->assertOk()->assertJsonPath('data.description', 'A bright two-bedroom condo near IT Park.');
        Http::assertSent(fn ($r) => str_contains(json_encode($r->data()), '2-3 short sentences'));

        $this->postJson('/api/ai/generate-description', ['type' => 'condo', 'address' => 'IT Park', 'style' => 'fancy'])->assertStatus(422);
    }
}
