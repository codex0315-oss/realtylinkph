<?php

declare(strict_types=1);

namespace Tests\Feature\Workflows;

use App\Models\Appointment;
use App\Models\User;
use App\Notifications\ViewingSanctionNotice;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsActors;
use Tests\TestCase;

/**
 * Viewing sanctions: a reported no-show counts as two strikes, an admin can
 * warn, pause, lift a pause and waive a strike, and every one of those tells
 * the person (in the app and by email) and lands in the audit trail.
 */
class ViewingSanctionsWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use BuildsActors;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A confirmed viewing at the given time. */
    private function viewing(User $buyer, User $agent, Carbon $slot, string $status = 'confirmed'): Appointment
    {
        $listing = $this->listing($agent);

        return Appointment::create([
            'property_id'        => $listing->id,
            'buyer_id'           => $buyer->id,
            'agent_id'           => $agent->id,
            'preferred_datetime' => $slot,
            'status'             => $status,
        ]);
    }

    /** Cancel a confirmed viewing inside the 24 h window, as the given user. */
    private function lateCancel(User $by, Appointment $a): void
    {
        $this->actingAsUser($by)->postJson("/api/appointments/{$a->id}/cancel", ['reason_code' => 'emergency'])->assertOk();
    }

    public function test_cancelling_a_day_or_more_ahead_costs_nothing_but_inside_24h_is_a_strike(): void
    {
        Notification::fake();
        $buyer = $this->buyer();
        $agent = $this->agent();

        $early = $this->viewing($buyer, $agent, now()->addHours(30));
        $this->lateCancel($buyer, $early);
        $this->assertFalse($early->fresh()->late_cancellation);
        $this->assertSame(0, $buyer->fresh()->recentStrikes());

        $late = $this->viewing($buyer, $agent, now()->addHours(5));
        $this->lateCancel($agent, $late);
        $this->assertTrue($late->fresh()->late_cancellation);
        $this->assertSame(1, $agent->fresh()->recentStrikes(), 'it counts against whoever cancelled — here the agent');
        $this->assertSame(0, $buyer->fresh()->recentStrikes());

        // A pending, never-confirmed request is not a strike at any time.
        $pending = $this->viewing($buyer, $agent, now()->addHours(2), 'pending');
        $this->lateCancel($buyer, $pending);
        $this->assertSame(0, $buyer->fresh()->recentStrikes());
    }

    public function test_a_reported_no_show_counts_double_tells_them_and_the_reporter(): void
    {
        Notification::fake();
        $buyer = $this->buyer(['name' => 'Buyer Santos']);
        $agent = $this->agent(['name' => 'Agent Reyes']);
        $a     = $this->viewing($buyer, $agent, now()->subHours(3));

        // The agent reports that the buyer didn't show.
        $this->actingAsUser($agent)->postJson("/api/appointments/{$a->id}/no-show", ['note' => 'Waited 30 minutes.'])
            ->assertOk()->assertJsonPath('data.no_show.user_id', $buyer->id);

        $this->assertSame(User::NO_SHOW_WEIGHT, $buyer->fresh()->recentStrikes());
        $this->assertSame(0, $agent->fresh()->recentStrikes());

        $this->assertDatabaseHas('notifications', ['notifiable_id' => $buyer->id, 'type' => 'no_show_recorded']);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $agent->id, 'type' => 'no_show_reported']);
        Notification::assertSentTo($buyer, ViewingSanctionNotice::class);

        // Once per viewing.
        $this->actingAsUser($agent)->postJson("/api/appointments/{$a->id}/no-show")->assertStatus(422);
        $this->actingAsUser($buyer)->postJson("/api/appointments/{$a->id}/no-show")->assertStatus(422);
    }

    public function test_no_show_can_only_be_reported_by_a_party_after_the_slot_and_within_the_window(): void
    {
        Notification::fake();
        $buyer    = $this->buyer();
        $agent    = $this->agent();
        $stranger = $this->buyer();

        $future = $this->viewing($buyer, $agent, now()->addHours(2));
        $this->actingAsUser($agent)->postJson("/api/appointments/{$future->id}/no-show")->assertStatus(422);

        $justNow = $this->viewing($buyer, $agent, now()->subMinutes(20));
        $this->actingAsUser($agent)->postJson("/api/appointments/{$justNow->id}/no-show")->assertStatus(422);

        $stale = $this->viewing($buyer, $agent, now()->subDays(9));
        $this->actingAsUser($buyer)->postJson("/api/appointments/{$stale->id}/no-show")->assertStatus(422);

        $ok = $this->viewing($buyer, $agent, now()->subHours(2));
        $this->actingAsUser($stranger)->postJson("/api/appointments/{$ok->id}/no-show")->assertForbidden();

        $cancelled = $this->viewing($buyer, $agent, now()->subHours(2), 'cancelled');
        $this->actingAsUser($buyer)->postJson("/api/appointments/{$cancelled->id}/no-show")->assertStatus(422);

        // The appointment shows the viewer whether they may report.
        $this->actingAsUser($buyer)->getJson("/api/appointments/{$ok->id}")->assertJsonPath('data.can_report_no_show', true);
        $this->actingAsUser($buyer)->getJson("/api/appointments/{$future->id}")->assertJsonPath('data.can_report_no_show', false);
    }

    public function test_five_strikes_pause_booking_and_a_no_show_gets_there_faster(): void
    {
        Notification::fake();
        $buyer = $this->buyer();
        $agent = $this->agent();

        // 2 no-shows (4 strikes) + 1 late cancellation (1 strike) = the limit.
        foreach ([1, 2] as $i) {
            $a = $this->viewing($buyer, $agent, now()->subHours(3 + $i));
            $this->actingAsUser($agent)->postJson("/api/appointments/{$a->id}/no-show")->assertOk();
        }
        $this->assertSame(4, $buyer->fresh()->recentStrikes());
        $this->assertNull($buyer->fresh()->bookingLockedUntil());

        $late = $this->viewing($buyer, $agent, now()->addHours(4));
        $this->lateCancel($buyer, $late);

        $buyer = $buyer->fresh();
        $this->assertSame(5, $buyer->recentStrikes());
        $this->assertNotNull($buyer->bookingLockedUntil());

        $listing = $this->listing($agent);
        $this->actingAsUser($buyer)->postJson("/api/properties/{$listing->id}/appointments", [
            'preferred_datetime' => now()->addDays(3)->setTime(10, 0)->toIso8601String(),
        ])->assertStatus(423);
    }

    public function test_a_paused_agent_cannot_confirm_new_requests(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $agent = $this->agent();
        $buyer = $this->buyer();
        $req   = $this->viewing($buyer, $agent, now()->addDays(2), 'pending');

        $this->actingAsUser($admin)->postJson("/api/admin/viewings/{$agent->id}/pause", ['days' => 7, 'reason' => 'Three no-shows this month.'])->assertOk();

        $this->actingAsUser($agent)->postJson("/api/appointments/{$req->id}/confirm")->assertStatus(423);
        $this->assertSame('pending', $req->fresh()->status);

        $this->actingAsUser($admin)->postJson("/api/admin/viewings/{$agent->id}/lift", ['reason' => 'Talked it through.'])->assertOk();
        $this->actingAsUser($agent)->postJson("/api/appointments/{$req->id}/confirm")->assertOk();
    }

    public function test_admin_can_warn_pause_lift_and_every_step_notifies_and_is_audited(): void
    {
        Notification::fake();
        $admin = $this->admin(['name' => 'Site Admin']);
        $buyer = $this->buyer();

        // Warn.
        $this->actingAsUser($admin)->postJson("/api/admin/viewings/{$buyer->id}/warn", ['message' => 'Please keep to the times you book.'])->assertOk();
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $buyer->id, 'type' => 'viewing_warning']);

        // Pause — only the preset lengths, and a real reason.
        $this->actingAsUser($admin)->postJson("/api/admin/viewings/{$buyer->id}/pause", ['days' => 5, 'reason' => 'Repeated no-shows.'])->assertStatus(422);
        $this->actingAsUser($admin)->postJson("/api/admin/viewings/{$buyer->id}/pause", ['days' => 7, 'reason' => 'short'])->assertStatus(422);
        $this->actingAsUser($admin)->postJson("/api/admin/viewings/{$buyer->id}/pause", ['days' => 7, 'reason' => 'Repeated no-shows.'])->assertOk()
            ->assertJsonPath('data.locked_by', 'admin');
        $this->assertNotNull($buyer->fresh()->bookingLockedUntil());
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $buyer->id, 'type' => 'viewing_paused']);

        // Lift.
        $this->actingAsUser($admin)->postJson("/api/admin/viewings/{$buyer->id}/lift", [])->assertOk();
        $this->assertNull($buyer->fresh()->bookingLockedUntil());
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $buyer->id, 'type' => 'viewing_pause_lifted']);
        // Lifting a pause that isn't there is a mistake worth a message.
        $this->actingAsUser($admin)->postJson("/api/admin/viewings/{$buyer->id}/lift", [])->assertStatus(422);

        Notification::assertSentToTimes($buyer, ViewingSanctionNotice::class, 3);

        foreach (['viewing.warned', 'viewing.paused', 'viewing.lifted'] as $action) {
            $this->assertDatabaseHas('admin_actions', ['action' => $action, 'subject_id' => $buyer->id, 'admin_id' => $admin->id]);
        }
    }

    public function test_lifting_an_automatic_lock_restarts_the_clock_rather_than_erasing_history(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $buyer = $this->buyer();
        $agent = $this->agent();

        foreach ([1, 2, 3] as $i) {   // 3 no-shows = 6 strikes → locked
            $a = $this->viewing($buyer, $agent, now()->subHours(3 + $i));
            $this->actingAsUser($agent)->postJson("/api/appointments/{$a->id}/no-show")->assertOk();
        }
        $this->assertNotNull($buyer->fresh()->bookingLockedUntil());

        $this->actingAsUser($admin)->postJson("/api/admin/viewings/{$buyer->id}/lift", ['reason' => 'Agreed to be more careful.'])->assertOk();

        $buyer = $buyer->fresh();
        $this->assertNull($buyer->bookingLockedUntil(), 'the lock is lifted');
        $this->assertSame(6, $buyer->recentStrikes(), 'the record is kept');

        // …but one new strike does not re-lock them straight away.
        $late = $this->viewing($buyer, $agent, now()->addHours(3));
        $this->lateCancel($buyer, $late);
        $this->assertNull($buyer->fresh()->bookingLockedUntil());
    }

    public function test_waiving_a_strike_removes_it_from_the_count_and_tells_the_person(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $buyer = $this->buyer();
        $agent = $this->agent();

        $a = $this->viewing($buyer, $agent, now()->subHours(3));
        $this->actingAsUser($agent)->postJson("/api/appointments/{$a->id}/no-show")->assertOk();
        $this->assertSame(2, $buyer->fresh()->recentStrikes());

        $this->actingAsUser($admin)->postJson("/api/admin/viewings/appointments/{$a->id}/waive", ['reason' => 'Buyer showed proof of a hospital visit.'])->assertOk();

        $this->assertSame(0, $buyer->fresh()->recentStrikes());
        $this->assertNotNull($a->fresh()->strike_waived_at);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $buyer->id, 'type' => 'strike_waived']);
        $this->assertDatabaseHas('admin_actions', ['action' => 'viewing.strike_waived', 'subject_id' => $buyer->id]);

        // Nothing left to waive.
        $this->actingAsUser($admin)->postJson("/api/admin/viewings/appointments/{$a->id}/waive", ['reason' => 'Trying again for no reason.'])->assertStatus(422);
    }

    public function test_the_admin_list_and_record_show_strikes_and_only_admins_can_use_them(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $buyer = $this->buyer(['name' => 'Flaky Buyer']);
        $agent = $this->agent(['name' => 'Steady Agent']);

        $a = $this->viewing($buyer, $agent, now()->subHours(3));
        $this->actingAsUser($agent)->postJson("/api/appointments/{$a->id}/no-show", ['note' => 'Never arrived.'])->assertOk();

        $list = $this->actingAsUser($admin)->getJson('/api/admin/viewings')->assertOk();
        $list->assertJsonPath('data.0.id', $buyer->id)->assertJsonPath('data.0.strikes', 2)->assertJsonPath('counts.flagged', 1);

        $this->actingAsUser($admin)->getJson("/api/admin/viewings/{$buyer->id}")->assertOk()
            ->assertJsonPath('data.incidents.0.kind', 'no_show')
            ->assertJsonPath('data.incidents.0.reported_by', 'Steady Agent')
            ->assertJsonPath('data.incidents.0.counts_now', true);

        // Not for buyers, agents, or guests.
        $this->actingAsUser($buyer)->getJson('/api/admin/viewings')->assertForbidden();
        $this->actingAsUser($agent)->postJson("/api/admin/viewings/{$buyer->id}/pause", ['days' => 7, 'reason' => 'Trying my luck here.'])->assertForbidden();
        $this->asGuest()->getJson('/api/admin/viewings')->assertUnauthorized();

        // Admins don't have a viewing record to sanction.
        $this->actingAsUser($admin)->postJson("/api/admin/viewings/{$admin->id}/warn", ['message' => 'Warning an admin makes no sense.'])->assertStatus(422);
    }
}
