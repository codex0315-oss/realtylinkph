<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Appointment;
use App\Models\Property;
use App\Models\User;
use App\Notifications\ViewingCancelled;
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

            $appointment = Appointment::create([
                'property_id'        => $property->id,
                'buyer_id'           => $buyer->id,
                'agent_id'           => $agent->id,
                'preferred_datetime' => $datetime,
                'status'             => 'pending',
                'notes'              => $data['notes'] ?? null,
            ]);

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

        return $appointment->fresh(['property', 'buyer', 'agent']);
    }

    /** Agent marks a confirmed viewing as completed — moves it to History for both parties. */
    public function complete(Appointment $appointment): Appointment
    {
        $appointment->loadMissing(['buyer', 'property']);
        $appointment->update(['status' => 'completed']);

        $title = $appointment->property->title;
        $this->notificationService->send($appointment->buyer, 'appointment_completed', [
            'appointment_id' => $appointment->id,
            'property_title' => $title,
            'message'        => "Your viewing for {$title} was marked completed.",
        ]);

        return $appointment->fresh(['property', 'buyer', 'agent']);
    }

    public function cancel(Appointment $appointment, ?string $reason = null, ?User $canceller = null): Appointment
    {
        $byBuyer = $canceller !== null && $canceller->id === $appointment->buyer_id;

        $appointment = DB::transaction(function () use ($appointment, $byBuyer): Appointment {
            $appointment->loadMissing(['agent', 'buyer', 'property']);
            $appointment->update(['status' => 'cancelled']);

            $title = $appointment->property->title;

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
                    'message'        => "{$appointment->buyer->name} cancelled their viewing for {$title}.",
                ]);
            } else {
                // Agent (or system) cancelled — make sure the buyer is informed.
                $this->notificationService->send($appointment->buyer, 'appointment_cancelled', [
                    'appointment_id' => $appointment->id,
                    'property_title' => $title,
                    'message'        => "Your viewing for {$title} was cancelled.",
                ]);
            }

            return $appointment;
        });

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
