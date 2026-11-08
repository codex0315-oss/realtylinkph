<?php

declare(strict_types=1);

namespace Tests\Feature\Workflows;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\BuildsActors;
use Tests\TestCase;

/**
 * Two things people notice in Messages:
 *  - the chat list shows the NEWEST message of each chat, newest chat first
 *    (it used to show the oldest message after every refresh);
 *  - "Active now" follows the person: on while they are around, off the moment
 *    they sign out, and back on if they are still active somewhere else.
 */
class ChatListAndPresenceWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use BuildsActors;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function startChat($buyer, $listing): int
    {
        return $this->actingAsUser($buyer)->postJson("/api/properties/{$listing->id}/conversations")->assertOk()->json('data.id');
    }

    public function test_the_list_shows_the_newest_message_of_each_chat_and_orders_chats_by_activity(): void
    {
        Event::fake();
        $agent = $this->agent(['last_seen_at' => now()]);   // online, so no AI auto-reply muddies the thread
        $buyer = $this->buyer();
        $one   = $this->startChat($buyer, $this->listing($agent));
        $two   = $this->startChat($buyer, $this->listing($agent, ['title' => 'Second place']));

        Carbon::setTestNow(now()->addMinute());
        foreach (['first hello', 'second thought', 'LATEST WORD'] as $i => $body) {
            Carbon::setTestNow(now()->addSeconds(10));
            $this->actingAsUser($buyer)->postJson("/api/conversations/{$one}/messages", ['body' => $body])->assertCreated();
        }
        Carbon::setTestNow(now()->addMinutes(5));
        $this->actingAsUser($agent)->postJson('/api/heartbeat')->assertOk();   // still around, so no AI auto-reply
        $this->actingAsUser($buyer)->postJson("/api/conversations/{$two}/messages", ['body' => 'a note elsewhere'])->assertCreated();

        $list = $this->actingAsUser($buyer)->getJson('/api/conversations')->assertOk()->json('data');
        $byId = collect($list)->keyBy('id');

        $this->assertSame('LATEST WORD', $byId[$one]['latest_message']['body'], 'not the first message');
        $this->assertSame('a note elsewhere', $byId[$two]['latest_message']['body']);
        $this->assertSame($two, $list[0]['id'], 'the chat with the newest activity comes first');
    }

    public function test_signing_out_ends_active_now_at_once_and_another_tab_brings_it_back(): void
    {
        $user = $this->buyer();
        $peer = $this->buyer();

        $this->actingAsUser($user)->postJson('/api/heartbeat')->assertOk();
        $this->actingAsUser($peer)->getJson("/api/users/{$user->id}/presence")->assertJsonPath('data.is_online', true);

        $this->actingAsUser($user)->postJson('/api/logout')->assertOk();
        $this->actingAsUser($peer)->getJson("/api/users/{$user->id}/presence")
            ->assertJsonPath('data.is_online', false)
            ->assertJsonPath('data.last_seen_at', fn ($v) => $v !== null);

        // Still open in another tab or device: the next heartbeat makes them active again.
        Carbon::setTestNow(now()->addSeconds(5));
        $this->actingAsUser($user)->postJson('/api/heartbeat')->assertOk();
        $this->actingAsUser($peer)->getJson("/api/users/{$user->id}/presence")->assertJsonPath('data.is_online', true);
    }

    public function test_someone_who_stopped_pinging_goes_offline_after_two_minutes(): void
    {
        $user = $this->buyer();
        $peer = $this->buyer();
        $this->actingAsUser($user)->postJson('/api/heartbeat')->assertOk();

        Carbon::setTestNow(now()->addMinutes(3));
        $this->actingAsUser($peer)->getJson("/api/users/{$user->id}/presence")->assertJsonPath('data.is_online', false);
    }
}
