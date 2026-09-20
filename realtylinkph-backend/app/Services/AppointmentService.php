<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Appointment;
use App\Models\Property;
use App\Models\User;
use App\Notifications\ViewingCancelled;
use App\Notifications\ViewingConfirmed;
use App\Notifications\ViewingReminder;
use App\Notifications\ViewingRequested;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class AppointmentService
{
    public function __construct(
        private readonly AvailabilityService $availabilityService,
        private readonly GoogleCalendarService $googleCalendarService,
        private readonly NotificationService $notificationService,
        private readonly ConversationService $conversationService,
    ) {}

    public function book(User $buyer, Property $property, array $data): Appointment
    {
        $appointment = DB::transaction(function () use ($buyer, $property, $data): Appointment {
            $property->loadMissing('agent');
            $datetime = Carbon::parse($data['preferred_datetime']);
            $agent    = $property->agent;

            if (! $this->availabilityService->isSlotAvailable($agent, $datetime)) {
                throw new \RuntimeException('The selected time slot is not available.');
            }

            // One active viewing per buyer per property (cancelled ones can re-book).
            $alreadyBooked = Appointment::where('property_id', $property->id)
                ->where('buyer_id', $buyer->id)
                ->whereIn('status', ['pending', 'confirmed'])
                ->exists();
            if ($alreadyBooked) {
                throw new \RuntimeException('You already have a scheduled viewing for this property.');
            }

            /*
             * The availability check above can be passed by two requests at
             * once — both read "free" before either writes. A partial unique
             * index on (agent_id, preferred_datetime) for live bookings is the
             * real guard; catch its violation and report it the same way as a
             * slot that was already taken, rather than surfacing a 500.
             */
            try {
                $appointment = Appointment::create([
                    'property_id'        => $property->id,
                    'buyer_id'           => $buyer->id,
                    'agent_id'           => $agent->id,
                    'preferred_datetime' => $datetime,
                    'status'             => 'pending',
                    'notes'              => $data['notes'] ?? null,
                ]);
            } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                throw new \RuntimeException('Someone just booked that time slot. Please pick another.');
            }

            $this->notificationService->send($agent, 'new_appointment', [
                'appointment_id' => $appointment->id,
                'buyer_name'     => $buyer->name,
                'property_title' => $property->title,
                'datetime'       => $datetime->toDateTimeString(),
            ]);

            return $appointment->load(['property', 'buyer', 'agent']);
        });

        // Email both parties (queued) — outside the transaction so a slow mailer
        // never blocks or rolls back the booking.
        $appointment->agent->notify(new ViewingRequested($appointment));
        $appointment->buyer->notify(new ViewingRequested($appointment));

        return $appointment;
    }

    public function confirm(Appointment $appointment): Appointment
    {
        $appointment = DB::transaction(function () use ($appointment): Appointment {
            $appointment->loadMissing(['buyer', 'property', 'agent']);
            $appointment->update(['status' => 'confirmed']);

            $this->notificationService->send($appointment->buyer, 'appointment_confirmed', [
                'appointment_id' => $appointment->id,
                'property_title' => $appointment->property->title,
                'datetime'       => $appointment->preferred_datetime->toDateTimeString(),
            ]);

            return $appointment;
        });

        // Sync to BOTH calendars (external calls) outside the transaction — each is
        // a no-op if that user hasn't connected Google Calendar.
        $appointment->update([
            'gcal_event_id'       => $this->googleCalendarService->createEvent($appointment, $appointment->agent),
            'gcal_event_id_buyer' => $this->googleCalendarService->createEvent($appointment, $appointment->buyer),
        ]);

        // The confirmation is the one email a buyer actually keeps (queued).
        $appointment->buyer->notify(new ViewingConfirmed($appointment));

        return $appointment->fresh(['property', 'buyer', 'agent']);
    }

    /**
     * Mark a confirmed viewing as completed — moves it to History for both
     * parties and unlocks the buyer's review. Called by the agent from the
     * dashboard, or by `appointments:complete-past` once the slot is a day
     * old and nobody touched it (so a forgotten viewing still closes).
     */
    public function complete(Appointment $appointment, bool $auto = false): Appointment
    {
        $appointment->loadMissing(['buyer', 'property', 'agent']);
        $appointment->update(['status' => 'completed']);

        $title = $appointment->property->title;
        $this->notificationService->send($appointment->buyer, 'appointment_completed', [
            'appointment_id' => $appointment->id,
            'property_title' => $title,
            'message'        => $auto
                ? "Your viewing for {$title} is now in your history. How did it go? You can leave {$appointment->agent->name} a review."
                : "Your viewing for {$title} was marked completed. You can now leave {$appointment->agent->name} a review.",
        ]);

        if ($auto) {
            $this->notificationService->send($appointment->agent, 'appointment_completed', [
                'appointment_id' => $appointment->id,
                'property_title' => $title,
                'message'        => "The viewing for {$title} with {$appointment->buyer->name} was moved to history automatically.",
            ]);
        }

        return $appointment->fresh(['property', 'buyer', 'agent']);
    }

    /**
     * Day-before reminder to both parties: in-app plus email (queued). Stamps
     * reminded_at so the hourly command never repeats it.
     */
    public function remind(Appointment $appointment): void
    {
        $appointment->loadMissing(['buyer', 'agent', 'property']);
        $when  = $appointment->preferred_datetime->setTimezone('Asia/Manila')->format('D, M j \a\t g:i A');
        $title = $appointment->property->title;

        $this->notificationService->send($appointment->buyer, 'appointment_reminder', [
            'appointment_id' => $appointment->id,
            'property_title' => $title,
            'message'        => "Reminder: your viewing for {$title} is on {$when}.",
        ]);
        $this->notificationService->send($appointment->agent, 'appointment_reminder', [
            'appointment_id' => $appointment->id,
            'property_title' => $title,
            'message'        => "Reminder: viewing for {$title} with {$appointment->buyer->name} on {$when}.",
        ]);

        $appointment->buyer->notify(new ViewingReminder($appointment));
        $appointment->agent->notify(new ViewingReminder($appointment));

        $appointment->forceFill(['reminded_at' => now()])->save();
    }

    /**
     * Cancel a viewing, on the record.
     *
     * The reason is stored, not discarded, and a CONFIRMED viewing dropped
     * inside Appointment::LATE_WINDOW_HOURS is flagged as a late cancellation —
     * that flag is what feeds the canceller's reliability figure and, past
     * User::STRIKE_LIMIT in a rolling month, a temporary booking lockout.
     */
    public function cancel(
        Appointment $appointment,
        ?string $reasonCode = null,
        ?User $canceller = null,
        ?string $reasonNote = null,
    ): Appointment {
        $byBuyer = $canceller !== null && $canceller->id === $appointment->buyer_id;
        $isLate  = $canceller !== null && $appointment->wouldBeLateCancellation();

        $appointment = DB::transaction(function () use ($appointment, $byBuyer, $canceller, $reasonCode, $reasonNote, $isLate): Appointment {
            $appointment->loadMissing(['agent', 'buyer', 'property']);
            $appointment->update([
                'status'             => 'cancelled',
                'cancel_reason_code' => $reasonCode,
                'cancel_reason_note' => $reasonNote,
                'cancelled_by_id'    => $canceller?->id,
                'cancelled_at'       => now(),
                'late_cancellation'  => $isLate,
            ]);

            $title  = $appointment->property->title;
            $why    = Appointment::reasonLabel($reasonCode, ! $byBuyer);
            $suffix = $why ? " Reason: {$why}." : '';

            if ($byBuyer) {
                // Buyer cancelled — tell the buyer + the agent.
                $this->notificationService->send($appointment->buyer, 'appointment_cancelled', [
                    'appointment_id' => $appointment->id,
                    'property_title' => $title,
                    'message'        => "You cancelled your viewing for {$title}.",
                ]);
                $this->notificationService->send($appointment->agent, 'appointment_cancelled', [
                    'appointment_id' => $appointment->id,
                    'property_title' => $title,
                    'message'        => "{$appointment->buyer->name} cancelled their viewing for {$title}.{$suffix}",
                ]);
            } else {
                // Agent (or system) cancelled — make sure the buyer is informed.
                $this->notificationService->send($appointment->buyer, 'appointment_cancelled', [
                    'appointment_id' => $appointment->id,
                    'property_title' => $title,
                    'message'        => "Your viewing for {$title} was cancelled.{$suffix}",
                ]);

                // System cancellation (expired request): the agent didn't act,
                // so tell them too — silently dropping it would hide the miss.
                if ($canceller === null) {
                    $this->notificationService->send($appointment->agent, 'appointment_cancelled', [
                        'appointment_id' => $appointment->id,
                        'property_title' => $title,
                        'message'        => "The viewing request from {$appointment->buyer->name} for {$title} expired — it wasn't confirmed before the requested time.",
                    ]);
                }
            }

            return $appointment;
        });

        // Tell the canceller plainly that this one counted, and where they stand.
        if ($isLate && $canceller !== null) {
            $strikes = $canceller->recentStrikes();
            $left    = max(0, User::STRIKE_LIMIT - $strikes);

            $this->notificationService->send($canceller, 'late_cancellation_recorded', [
                'appointment_id' => $appointment->id,
                'strikes'        => $strikes,
                'message'        => $left > 0
                    ? "That viewing was cancelled within " . Appointment::LATE_WINDOW_HOURS . " hours of the slot, so it counts toward your reliability ({$strikes} in the last " . User::STRIKE_WINDOW_DAYS . " days). {$left} more and booking is paused for " . User::LOCKOUT_DAYS . " days."
                    : "That was your {$strikes}th late cancellation in " . User::STRIKE_WINDOW_DAYS . " days, so booking viewings is paused for " . User::LOCKOUT_DAYS . " days.",
            ]);
        }

        // Email both parties — only when the buyer cancels (queued, outside the transaction).
        if ($byBuyer && $canceller !== null) {
            $appointment->buyer->notify(new ViewingCancelled($appointment, $canceller));
            $appointment->agent->notify(new ViewingCancelled($appointment, $canceller));

            // Drop the cancellation into the buyer ↔ agent chat so both see it in
            // their conversation (also notifies + broadcasts to the agent live).
            $when         = $appointment->preferred_datetime->setTimezone('Asia/Manila')->format('M j, Y \a\t g:i A');
            $conversation = $this->conversationService->findOrCreate($appointment->buyer, $appointment->property);
            $this->conversationService->sendMessage(
                $appointment->buyer,
                $conversation,
                "❌ I've cancelled my viewing for \"{$appointment->property->title}\" scheduled on {$when}.",
            );
        }

        // Remove the event from both calendars.
        if ($appointment->gcal_event_id) {
            $this->googleCalendarService->deleteEvent($appointment->agent, $appointment->gcal_event_id);
        }
        if ($appointment->gcal_event_id_buyer) {
            $this->googleCalendarService->deleteEvent($appointment->buyer, $appointment->gcal_event_id_buyer);
        }

        return $appointment->fresh(['property', 'buyer', 'agent']);
    }

    public function listForUser(User $user, int $perPage = 15): LengthAwarePaginator
    {
        return Appointment::with(['property.photos', 'buyer', 'agent', 'review'])
            ->where(function ($q) use ($user): void {
                $q->where('buyer_id', $user->id)
                    ->orWhere('agent_id', $user->id);
            })
            ->latest('preferred_datetime')
            ->paginate($perPage);
    }
}
