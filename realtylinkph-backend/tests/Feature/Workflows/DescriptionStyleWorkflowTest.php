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
