<?php

declare(strict_types=1);

namespace Tests\Feature\Workflows;

use App\Models\Appointment;
use App\Notifications\ViewingConfirmed;
use App\Notifications\ViewingReminder;
use App\Notifications\ViewingRequested;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsActors;
use Tests\TestCase;

/**
 * Features 5, 6 & 9 — a buyer books a viewing, the agent confirms (buyer
 * gets the email), the day-before reminder goes out, the viewing closes
 * automatically once it is in the past, and only then can the buyer review.
 */
class ViewingWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use BuildsActors;

    /** Next weekday at 10:00 Manila — inside the agent's 08:00–18:00 slot window. */
    private function slot(): Carbon
    {
        return Carbon::now('Asia/Manila')->addDays(3)->setTime(10, 0);
    }

    public function test_book_confirm_remind_autocomplete_review(): void
    {
        Notification::fake();
        Carbon::setTestNow(Carbon::parse('2026-10-05 09:00', 'Asia/Manila'));

        $agent   = $this->agent(['name' => 'Agent Dela Cruz']);
        $buyer   = $this->buyer(['name' => 'Buyer Santos']);
        $listing = $this->listing($agent);
        $when    = $this->slot();

        // 1. Book.
        $book = $this->actingAsUser($buyer)->postJson("/api/properties/{$listing->id}/appointments", [
            'preferred_datetime' => $when->toIso8601String(),
            'notes'              => 'Can I bring my parents?',
        ])->assertCreated()->assertJsonPath('data.status', 'pending');
        $id = $book->json('data.id');

        Notification::assertSentTo($agent, ViewingRequested::class);
        Notification::assertSentTo($buyer, ViewingRequested::class);

        // The same slot cannot be booked twice, and the buyer cannot double-book the property.
        $this->actingAsUser($this->buyer())->postJson("/api/properties/{$listing->id}/appointments", [
            'preferred_datetime' => $when->toIso8601String(),
        ])->assertStatus(422);
        $this->actingAsUser($buyer)->postJson("/api/properties/{$listing->id}/appointments", [
            'preferred_datetime' => $when->copy()->addHour()->toIso8601String(),
        ])->assertStatus(422);

        // 2. Only the agent can confirm; the buyer gets the confirmation email.
        $this->actingAsUser($buyer)->postJson("/api/appointments/{$id}/confirm")->assertForbidden();
        $this->actingAsUser($agent)->postJson("/api/appointments/{$id}/confirm")
            ->assertOk()->assertJsonPath('data.status', 'confirmed');
        Notification::assertSentTo($buyer, ViewingConfirmed::class);
        Notification::assertNotSentTo($agent, ViewingConfirmed::class);

        // Too early to review — nothing has happened yet.
        $this->actingAsUser($buyer)->postJson('/api/reviews', ['appointment_id' => $id, 'rating' => 5])
            ->assertStatus(422);

        // 3. Reminder: not yet (3 days out) ...
        $this->artisan('appointments:send-reminders')->assertSuccessful();
        Notification::assertNotSentTo($buyer, ViewingReminder::class);

        // ... then within 24 h it goes out once, to both, and never again.
        Carbon::setTestNow($when->copy()->subHours(20));
        $this->artisan('appointments:send-reminders')->assertSuccessful();
        $this->artisan('appointments:send-reminders')->assertSuccessful();
        Notification::assertSentToTimes($buyer, ViewingReminder::class, 1);
        Notification::assertSentToTimes($agent, ViewingReminder::class, 1);
        $this->assertNotNull(Appointment::find($id)->reminded_at);

        $this->assertDatabaseHas('notifications', ['notifiable_id' => $buyer->id, 'type' => 'appointment_reminder']);

        // 4. The viewing happens; the agent forgets to close it. A day later the system does.
        Carbon::setTestNow($when->copy()->addHours(2));
        $this->artisan('appointments:complete-past')->assertSuccessful();
        $this->assertSame('confirmed', Appointment::find($id)->status, 'still inside the grace period');

        Carbon::setTestNow($when->copy()->addHours(26));
        $this->artisan('appointments:complete-past')->assertSuccessful();
        $this->assertSame('completed', Appointment::find($id)->status);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $buyer->id, 'type' => 'appointment_completed']);

        // 5. Now the buyer can review the agent — once.
        $this->actingAsUser($buyer)->postJson('/api/reviews', [
            'appointment_id' => $id,
            'rating'         => 5,
            'review_text'    => 'Punctual and knew the building well.',
        ])->assertCreated()->assertJsonPath('data.rating', 5);

        $this->actingAsUser($buyer)->postJson('/api/reviews', ['appointment_id' => $id, 'rating' => 1])
            ->assertStatus(422);

        // The agent cannot review themselves, and a stranger cannot review this viewing.
        $this->actingAsUser($agent)->postJson('/api/reviews', ['appointment_id' => $id, 'rating' => 5])->assertForbidden();
        $this->actingAsUser($this->buyer())->postJson('/api/reviews', ['appointment_id' => $id, 'rating' => 5])->assertStatus(422);

        // Public agent profile now carries the review.
        $reviews = $this->asGuest()->getJson("/api/agents/{$agent->id}/reviews")->assertOk();
        $this->assertCount(1, $reviews->json('data'));

        Carbon::setTestNow();
    }

    public function test_unanswered_requests_expire_and_the_buyer_can_rebook(): void
    {
        Notification::fake();
        Carbon::setTestNow(Carbon::parse('2026-10-05 09:00', 'Asia/Manila'));

        $agent   = $this->agent();
        $buyer   = $this->buyer();
        $listing = $this->listing($agent);
        $when    = $this->slot();

        $id = $this->actingAsUser($buyer)->postJson("/api/properties/{$listing->id}/appointments", [
            'preferred_datetime' => $when->toIso8601String(),
        ])->assertCreated()->json('data.id');

        Carbon::setTestNow($when->copy()->addHours(26));
        $this->artisan('appointments:complete-past')->assertSuccessful();

        $a = Appointment::find($id);
        $this->assertSame('cancelled', $a->status);
        $this->assertSame('expired', $a->cancel_reason_code);
        $this->assertNull($a->cancelled_by_id);
        $this->assertFalse($a->late_cancellation, 'a system expiry never counts as a strike');

        // Both parties were told, in plain words.
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $buyer->id, 'type' => 'appointment_cancelled']);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $agent->id, 'type' => 'appointment_cancelled']);

        $shown = $this->actingAsUser($buyer)->getJson("/api/appointments/{$id}")->assertOk();
        $this->assertSame('No response from the agent before the viewing time', $shown->json('data.cancel_reason'));

        // The slot is free again for this buyer.
        $this->actingAsUser($buyer)->postJson("/api/properties/{$listing->id}/appointments", [
            'preferred_datetime' => Carbon::now('Asia/Manila')->addDays(2)->setTime(14, 0)->toIso8601String(),
        ])->assertCreated();

        Carbon::setTestNow();
    }
}
