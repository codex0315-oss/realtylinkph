<?php

declare(strict_types=1);

namespace Tests\Feature\Workflows;

use App\Jobs\AssessAgentApplication;
use App\Models\AgentProfile;
use App\Services\GeminiService;
use App\Support\AgentApplicationVerdict;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsActors;
use Tests\TestCase;

/**
 * RealtyLink AI's advice on an agent application is a fixed checklist whose
 * verdict comes from rules in code. The same documents must give the same
 * advice every time; the admin can ask for a fresh check.
 */
class AgentApplicationCheckTest extends TestCase
{
    use RefreshDatabase;
    use BuildsActors;

    private function today(): Carbon
    {
        return Carbon::parse('2026-11-09', 'Asia/Manila');
    }

    /** What the model read from a good broker card. */
    private function goodRead(array $over = []): array
    {
        return [
            'name_on_document'        => 'JUAN MIGUEL DELA CRUZ',
            'number_on_document'      => '0000687',
            'valid_until'             => '2028-05-14',
            'profession'              => 'broker',
            'supervising_broker'      => null,
            'looks_like_prc_document' => 'yes',
            'document_unreadable'     => false,
            'signs_of_editing'        => false,
            'selfie_clear'            => true,
            'face_match'              => 'similar',
            'summary'                 => 'Card and selfie are clear.',
            ...$over,
        ];
    }

    private function declared(array $over = []): array
    {
        return ['name' => 'Juan Dela Cruz', 'number' => '000687', 'supervising_broker' => null, 'type' => 'broker', ...$over];
    }

    private function check(array $read, array $declared = []): array
    {
        return AgentApplicationVerdict::evaluate($read, $this->declared($declared), $this->today());
    }

    private function state(array $result, string $key): string
    {
        return collect($result['checks'])->firstWhere('key', $key)['state'];
    }

    public function test_matching_documents_are_consistent(): void
    {
        $r = $this->check($this->goodRead());

        $this->assertSame('consistent', $r['verdict']);
        $this->assertSame(['ok', 'ok', 'ok', 'ok', 'ok'], array_column($r['checks'], 'state'));
    }

    public function test_the_same_reading_always_gives_the_same_verdict(): void
    {
        $first = $this->check($this->goodRead(['number_on_document' => '999999']));
        foreach (range(1, 5) as $i) {
            $this->assertSame($first, $this->check($this->goodRead(['number_on_document' => '999999'])));
        }
        $this->assertSame('needs_attention', $first['verdict']);
    }

    public function test_names_ignore_order_case_middle_names_and_titles_but_not_other_people(): void
    {
        $this->assertTrue(AgentApplicationVerdict::sameName('DELA CRUZ, JUAN MIGUEL', 'Juan Dela Cruz'));
        $this->assertTrue(AgentApplicationVerdict::sameName('Atty. José Rizal Jr.', 'jose rizal'));
        $this->assertFalse(AgentApplicationVerdict::sameName('MARIA SANTOS', 'Juan Dela Cruz'));
        $this->assertFalse(AgentApplicationVerdict::sameName('Juan Reyes', 'Juan Dela Cruz'));
    }

    public function test_a_wrong_number_or_name_needs_attention_and_quotes_both_values(): void
    {
        $r = $this->check($this->goodRead(['number_on_document' => '1234567', 'name_on_document' => 'MARIA SANTOS']));

        $this->assertSame('needs_attention', $r['verdict']);
        $this->assertSame('bad', $this->state($r, 'number'));
        $this->assertSame('bad', $this->state($r, 'name'));
        $this->assertStringContainsString('MARIA SANTOS', collect($r['checks'])->firstWhere('key', 'name')['detail']);
    }

    public function test_leading_zeros_in_the_number_do_not_matter(): void
    {
        $this->assertSame('ok', $this->state($this->check($this->goodRead(['number_on_document' => '687'])), 'number'));
    }

    public function test_an_expired_card_needs_attention(): void
    {
        $r = $this->check($this->goodRead(['valid_until' => '2026-11-08']));
        $this->assertSame('bad', $this->state($r, 'validity'));
        $this->assertSame('needs_attention', $r['verdict']);

        // Valid today still counts as valid.
        $this->assertSame('ok', $this->state($this->check($this->goodRead(['valid_until' => '2026-11-09'])), 'validity'));
    }

    public function test_things_that_could_not_be_read_make_it_cannot_verify_not_a_concern(): void
    {
        $r = $this->check($this->goodRead(['valid_until' => null, 'face_match' => 'unclear', 'name_on_document' => null]));

        $this->assertSame('cannot_verify', $r['verdict']);
        $this->assertSame('unknown', $this->state($r, 'validity'));
        $this->assertSame('unknown', $this->state($r, 'photo'));
        $this->assertSame('unknown', $this->state($r, 'name'));
    }

    public function test_a_dissimilar_selfie_wrong_profession_or_editing_needs_attention(): void
    {
        $this->assertSame('bad', $this->state($this->check($this->goodRead(['face_match' => 'dissimilar'])), 'photo'));
        $this->assertSame('bad', $this->state($this->check($this->goodRead(['profession' => 'salesperson'])), 'document'));
        $this->assertSame('bad', $this->state($this->check($this->goodRead(['signs_of_editing' => true])), 'document'));
        $this->assertSame('bad', $this->state($this->check($this->goodRead(['looks_like_prc_document' => 'no'])), 'document'));
    }

    public function test_a_salesperson_is_also_checked_against_the_supervising_broker(): void
    {
        $declared = ['type' => 'salesperson', 'supervising_broker' => 'Ana Reyes'];

        $ok = $this->check($this->goodRead(['profession' => 'salesperson', 'supervising_broker' => 'REYES, ANA']), $declared);
        $this->assertSame('ok', $this->state($ok, 'supervisor'));

        $bad = $this->check($this->goodRead(['profession' => 'salesperson', 'supervising_broker' => 'Pedro Cruz']), $declared);
        $this->assertSame('bad', $this->state($bad, 'supervisor'));

        $this->assertNull(collect($this->check($this->goodRead())['checks'])->firstWhere('key', 'supervisor'), 'brokers have no supervisor row');
    }

    private function pendingProfile(): AgentProfile
    {
        $user = $this->buyer(['name' => 'Juan Dela Cruz']);

        return AgentProfile::create([
            'user_id' => $user->id, 'applicant_type' => 'broker', 'prc_number' => '000687', 'status' => 'pending',
        ]);
    }

    public function test_the_job_saves_the_checklist_and_verdict_for_the_admin_only(): void
    {
        $profile = $this->pendingProfile();
        $this->mock(GeminiService::class, fn ($m) => $m->shouldReceive('readAgentApplication')->once()->andReturn($this->goodRead()));

        (new AssessAgentApplication($profile->id))->handle(app(GeminiService::class));

        $profile->refresh();
        $this->assertSame('consistent', $profile->ai_verdict);
        $this->assertCount(5, $profile->ai_checks);
        $this->assertSame('Card and selfie are clear.', $profile->ai_comment);

        $this->actingAsUser($this->admin())->getJson('/api/admin/agent-applications')->assertOk()
            ->assertJsonPath('data.0.ai_verdict', 'consistent');
    }

    public function test_when_the_ai_cannot_read_anything_it_says_unavailable(): void
    {
        $profile = $this->pendingProfile();
        $this->mock(GeminiService::class, fn ($m) => $m->shouldReceive('readAgentApplication')->andReturn(null));

        (new AssessAgentApplication($profile->id))->handle(app(GeminiService::class));

        $profile->refresh();
        $this->assertNull($profile->ai_verdict);
        $this->assertNotNull($profile->ai_assessed_at);
        $this->assertStringContainsString('unavailable', $profile->ai_comment);
    }

    public function test_the_admin_can_ask_for_a_fresh_check(): void
    {
        Queue::fake();
        $profile = $this->pendingProfile();
        $profile->update(['ai_comment' => 'old', 'ai_verdict' => 'consistent', 'ai_checks' => [['key' => 'name']], 'ai_assessed_at' => now()]);

        $this->actingAsUser($this->buyer())->postJson("/api/admin/agent-applications/{$profile->id}/recheck")->assertForbidden();

        $this->actingAsUser($this->admin())->postJson("/api/admin/agent-applications/{$profile->id}/recheck")->assertStatus(202);
        Queue::assertPushed(AssessAgentApplication::class, fn ($j) => $j->profileId === $profile->id);

        $profile->refresh();
        $this->assertNull($profile->ai_assessed_at);
        $this->assertNull($profile->ai_verdict);

        // A decided application is not re-checked.
        $profile->update(['status' => 'approved']);
        $this->postJson("/api/admin/agent-applications/{$profile->id}/recheck")->assertStatus(422);
    }
}
