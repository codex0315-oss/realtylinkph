<?php

declare(strict_types=1);

namespace Tests\Feature\Workflows;

use App\Models\AgentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsActors;
use Tests\TestCase;

/**
 * The agent-only gate tells each kind of person the truth. A buyer used to be
 * told "Your agent account is not verified", which made no sense to someone who
 * never applied.
 */
class AgentGateMessageTest extends TestCase
{
    use RefreshDatabase;
    use BuildsActors;

    private function pendingAgent(string $status): User
    {
        $user = User::factory()->create(['role_type' => 'agent', 'email_verified_at' => now()]);
        AgentProfile::create(['user_id' => $user->id, 'applicant_type' => 'broker', 'prc_number' => 'PRC-' . $user->id, 'status' => $status]);

        return $user->fresh();
    }

    public function test_each_kind_of_person_gets_an_honest_message(): void
    {
        $url = '/api/ai/agent-chat';

        $this->actingAsUser($this->buyer())->postJson($url, ['message' => 'hi'])
            ->assertForbidden()->assertJsonPath('message', 'This is only for verified agents.');

        $this->actingAsUser($this->pendingAgent('pending'))->postJson($url, ['message' => 'hi'])
            ->assertForbidden()->assertJsonPath('message', 'Your agent application is still being reviewed. This unlocks once it is approved.');

        $this->actingAsUser($this->pendingAgent('rejected'))->postJson($url, ['message' => 'hi'])
            ->assertForbidden()->assertJsonPath('message', 'Your agent application was not approved, so this is not available.');
    }
}
