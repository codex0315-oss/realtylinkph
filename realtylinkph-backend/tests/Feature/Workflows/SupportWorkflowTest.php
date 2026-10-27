<?php

declare(strict_types=1);

namespace Tests\Feature\Workflows;

use App\Models\SupportTicket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsActors;
use Tests\TestCase;

/**
 * Help & support: RealtyLink AI answers first, hands over to the team when it
 * can't help, admins reply and resolve, and the user rates it. Gemini is
 * faked: `$answer` is what the support reply returns; the admin brief always
 * returns a fixed summary.
 */
class SupportWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use BuildsActors;

    private ?array $answer = ['reply' => 'Open Profile, then Become an agent.', 'needs_human' => false, 'category' => 'verification'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->useScratchDocumentsDisk();

        Http::fake(['generativelanguage.googleapis.com/*' => function ($request) {
            $system = $request['systemInstruction']['parts'][0]['text'] ?? '';
            if (str_contains($system, 'help an admin')) {
                return Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode([
                    'category' => 'bug', 'summary' => 'Booking button does nothing.', 'suggested_reply' => 'Hi! We are looking into it.',
                ])]]]]]]);
            }
            if ($this->answer === null) {
                return Http::response([], 503);
            }

            return Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode($this->answer)]]]]]]);
        }]);
    }

    public function test_the_ai_answers_how_to_questions_without_a_ticket_for_the_admins(): void
    {
        $buyer = $this->buyer();
        $this->actingAsUser($buyer);

        $this->postJson('/api/support/messages', ['body' => 'How do I become an agent?'])->assertCreated()
            ->assertJsonPath('data.status', 'ai')
            ->assertJsonPath('data.messages.1.sender_type', 'ai')
            ->assertJsonPath('data.messages.1.body', 'Open Profile, then Become an agent.');

        $this->actingAsUser($this->admin());
        $this->getJson('/api/admin/support')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_handover_reply_resolve_and_rate(): void
    {
        $buyer = $this->buyer(['name' => 'Bea Buyer']);
        $admin = $this->admin(['name' => 'Ada Admin']);

        // The AI can't fix a bug: it hands over, with a screenshot attached.
        $this->answer = ['reply' => 'I am passing this to the team.', 'needs_human' => true, 'category' => 'bug'];
        $this->actingAsUser($buyer);
        $res = $this->post('/api/support/messages', [
            'body' => 'The Book viewing button does nothing',
            'images' => [UploadedFile::fake()->image('shot.png')],
        ], ['Accept' => 'application/json'])->assertCreated();
        $res->assertJsonPath('data.status', 'open')
            ->assertJsonCount(1, 'data.messages.0.attachments')
            ->assertJsonPath('data.messages.2.sender_type', 'system');
        $ticketId = $res->json('data.id');
        $this->assertTrue($admin->notifications()->where('type', 'support_ticket')->exists());

        // The admin sees it with the AI's summary and draft, and replies.
        $this->actingAsUser($admin);
        $this->getJson('/api/admin/support')->assertOk()
            ->assertJsonPath('data.0.id', $ticketId)
            ->assertJsonPath('data.0.summary', 'Booking button does nothing.')
            ->assertJsonPath('data.0.unread', 1);
        $this->getJson("/api/admin/support/{$ticketId}")->assertOk()
            ->assertJsonPath('data.suggested_reply', 'Hi! We are looking into it.')
            ->assertJsonPath('data.user.name', 'Bea Buyer');
        $this->postJson("/api/admin/support/{$ticketId}/reply", ['body' => 'Fixed it, please try again.'])->assertCreated()
            ->assertJsonPath('data.status', 'in_progress');
        $this->assertTrue($buyer->notifications()->where('type', 'support_reply')->exists());

        // Once an admin has replied and is online, the AI stays out of it.
        $admin->forceFill(['last_seen_at' => now()])->save();
        $this->actingAsUser($buyer);
        $this->getJson('/api/support/unread')->assertJsonPath('data.unread', 1);
        $this->postJson('/api/support/messages', ['body' => 'Works now, thanks!'])->assertCreated();
        $this->assertSame(1, SupportTicket::find($ticketId)->messages()->where('sender_type', 'ai')->count());   // only the hand-off

        // Resolve, then the buyer rates it.
        $this->actingAsUser($admin)->postJson("/api/admin/support/{$ticketId}/resolve")->assertOk()->assertJsonPath('data.status', 'resolved');
        $this->actingAsUser($buyer)->postJson("/api/support/tickets/{$ticketId}/rate", ['rating' => 'up'])->assertOk();

        $this->actingAsUser($admin)->getJson('/api/admin/support/stats')->assertOk()
            ->assertJsonPath('data.counts.resolved', 1)
            ->assertJsonPath('data.ratings.up', 1);

        // A new message after resolving starts a new conversation.
        $this->answer = ['reply' => 'Sure!', 'needs_human' => false, 'category' => 'other'];
        $this->actingAsUser($buyer)->postJson('/api/support/messages', ['body' => 'One more question'])->assertCreated();
        $this->assertSame(2, SupportTicket::where('user_id', $buyer->id)->count());
    }

    public function test_talk_to_a_person_and_ai_outage_both_reach_the_team(): void
    {
        $buyer = $this->buyer();
        $this->actingAsUser($buyer);

        $this->postJson('/api/support/escalate')->assertStatus(422);   // nothing to hand over yet
        $this->postJson('/api/support/messages', ['body' => 'Hello'])->assertCreated()->assertJsonPath('data.status', 'ai');
        $this->postJson('/api/support/escalate')->assertOk()->assertJsonPath('data.status', 'open');

        // With every AI down, it says so (and stays with the AI) instead of silently handing over.
        $this->answer = null;
        $this->actingAsUser($this->agent());
        $this->postJson('/api/support/messages', ['body' => 'My listing vanished'])->assertCreated()
            ->assertJsonPath('data.status', 'ai')
            ->assertJsonPath('data.messages.1.sender_type', 'ai');
        $this->postJson('/api/support/escalate')->assertOk()->assertJsonPath('data.status', 'open');
    }

    public function test_the_ai_stands_in_only_while_no_admin_is_online(): void
    {
        $buyer = $this->buyer();
        $admin = $this->admin();

        $this->answer = ['reply' => 'Passing this to the team.', 'needs_human' => true, 'category' => 'bug'];
        $this->actingAsUser($buyer);
        $id = $this->postJson('/api/support/messages', ['body' => 'Booking is broken'])->json('data.id');
        $this->actingAsUser($admin)->postJson("/api/admin/support/{$id}/reply", ['body' => 'Looking into it now.'])->assertCreated();

        // The admin is online (just active): the AI stays quiet.
        $admin->forceFill(['last_seen_at' => now()])->save();
        $this->answer = ['reply' => 'The team is away; meanwhile, cancelling is under Appointments.', 'needs_human' => false, 'category' => 'viewing'];
        $this->actingAsUser($buyer);
        $this->postJson('/api/support/messages', ['body' => 'Also how do I cancel?'])->assertCreated()
            ->assertJsonPath('data.team_online', true);
        $this->assertSame(1, SupportTicket::find($id)->messages()->where('sender_type', 'ai')->count());

        // The admin goes offline: the AI stands in, marked as such.
        $admin->forceFill(['last_seen_at' => now()->subMinutes(10)])->save();
        $res = $this->postJson('/api/support/messages', ['body' => 'Hello?'])->assertCreated()
            ->assertJsonPath('data.team_online', false)
            ->assertJsonPath('data.status', 'in_progress');
        $last = collect($res->json('data.messages'))->last();
        $this->assertSame('ai', $last['sender_type']);
        $this->assertTrue($last['stand_in']);
    }

    public function test_people_only_see_their_own_tickets(): void
    {
        $owner = $this->buyer();
        $this->actingAsUser($owner);
        $id = $this->postJson('/api/support/messages', ['body' => 'Hi'])->json('data.id');

        $this->actingAsUser($this->buyer());
        $this->getJson("/api/support/tickets/{$id}")->assertNotFound();
        $this->getJson('/api/admin/support')->assertForbidden();
    }
}
