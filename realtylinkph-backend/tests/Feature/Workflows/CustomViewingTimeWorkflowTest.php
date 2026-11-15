<?php

declare(strict_types=1);

namespace Tests\Feature\Workflows;

use App\Models\Appointment;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsActors;
use Tests\TestCase;

/**
 * Buyers can pick a custom viewing time besides the default hourly slots:
 * 7:00 AM – 7:00 PM start, 15-minute steps, at least 2 hours' notice, up to
 * 60 days ahead, never inside the agent's blocked hours, and never closer than
 * an hour plus a 30-minute travel gap to another of the agent's viewings.
 */
class CustomViewingTimeWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use BuildsActors;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Carbon::setTestNow(Carbon::parse('2026-11-16 08:00', 'Asia/Manila'));   // a Monday morning
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function book($buyer, $listing, string $when)
    {
        return $this->actingAsUser($buyer)->postJson("/api/properties/{$listing->id}/appointments", ['preferred_datetime' => $when]);
    }

    public function test_a_buyer_can_book_a_custom_time_in_15_minute_steps(): void
    {
        $agent = $this->agent(); $listing = $this->listing($agent);

        $this->book($this->buyer(), $listing, '2026-11-17 10:45')->assertCreated();
        $this->book($this->buyer(), $this->listing($agent), '2026-11-17 18:30')->assertCreated();   // evening, custom only
    }

    public function test_custom_times_outside_the_rules_are_refused_with_a_reason(): void
    {
        $agent = $this->agent(); $buyer = $this->buyer(); $listing = $this->listing($agent);

        $this->book($buyer, $listing, '2026-11-17 06:45')->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, '7:00 AM and 7:00 PM'));
        $this->book($buyer, $listing, '2026-11-17 19:15')->assertStatus(422);   // would end after 8 PM
        $this->book($buyer, $listing, '2026-11-17 10:10')->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, '15-minute'));
        $this->book($buyer, $listing, '2026-11-16 09:15')->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, '2 hours'));
        $this->book($buyer, $listing, '2027-02-01 10:15')->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, '60 days'));
        $this->assertSame(0, Appointment::count());
    }

    public function test_viewings_need_an_hour_plus_a_30_minute_travel_gap(): void
    {
        $agent = $this->agent();
        $this->book($this->buyer(), $this->listing($agent), '2026-11-17 10:00')->assertCreated();

        // 10:30 and 11:15 are too close; the message says when the agent is free.
        $this->book($this->buyer(), $this->listing($agent), '2026-11-17 10:30')->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, '10:00 AM to 11:00 AM') && str_contains($m, '11:30 AM or later'));
        $this->book($this->buyer(), $this->listing($agent), '2026-11-17 11:15')->assertStatus(422);
        $this->book($this->buyer(), $this->listing($agent), '2026-11-17 08:45')->assertStatus(422);   // would end 9:45, too close before 10:00

        // 11:30 and 8:30 leave the gap.
        $this->book($this->buyer(), $this->listing($agent), '2026-11-17 11:30')->assertCreated();
        $this->book($this->buyer(), $this->listing($agent), '2026-11-17 08:30')->assertCreated();
    }

    public function test_default_slots_hide_times_that_clash_or_have_passed(): void
    {
        $agent = $this->agent();
        $this->book($this->buyer(), $this->listing($agent), '2026-11-17 10:15')->assertCreated();

        $slots = $this->actingAsUser($this->buyer())->getJson("/api/agents/{$agent->id}/availability?date=2026-11-17")->json('data');
        $slots = $slots['slots'] ?? $slots;
        $this->assertNotContains('10:00', $slots);
        $this->assertNotContains('11:00', $slots);
        $this->assertContains('12:00', $slots);
        $this->assertContains('08:00', $slots);

        // Today at 8:00 AM: 8:00 has already passed, 9:00 is still open.
        $today = $this->actingAsUser($this->buyer())->getJson("/api/agents/{$agent->id}/availability?date=2026-11-16")->json('data');
        $today = $today['slots'] ?? $today;
        $this->assertNotContains('08:00', $today);
        $this->assertContains('09:00', $today);
    }

    public function test_a_custom_time_inside_the_agents_blocked_hours_is_refused(): void
    {
        $agent = $this->agent();
        $this->actingAsUser($agent)->postJson('/api/blocked-dates', [
            'blocked_date' => '2026-11-17', 'start_time' => '13:00', 'end_time' => '15:00', 'is_recurring' => false,
        ]);

        $this->book($this->buyer(), $this->listing($agent), '2026-11-17 12:30')->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'isn’t available'));
        $this->book($this->buyer(), $this->listing($agent), '2026-11-17 15:00')->assertCreated();
    }
}
