<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Appointment;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoogleCalendarService
{
    private const CALENDAR_API = 'https://www.googleapis.com/calendar/v3';

    /**
     * Create the viewing event on a specific user's calendar (buyer or agent).
     * Returns the created event id, or null if that user isn't connected / it fails.
     */
    public function createEvent(Appointment $appointment, User $owner): ?string
    {
        if (! $this->hasValidToken($owner)) {
            return null;
        }

        $appointment->loadMissing(['property', 'buyer', 'agent']);

        // Tailor the description to whose calendar this is.
        $other = $owner->id === $appointment->agent_id
            ? "Buyer: {$appointment->buyer->name}"
            : "Agent: {$appointment->agent->name}";

        try {
            $token = $this->getAccessToken($owner);
            $start = $appointment->preferred_datetime;
            // Same length as a bookable slot — a hard-coded hour against
            // 30-minute slots produced overlapping events in the real calendar.
            $end   = $start->copy()->addMinutes(AvailabilityService::SLOT_MINUTES);

            $response = Http::withToken($token)->post(
                self::CALENDAR_API . '/calendars/primary/events',
                [
                    'summary'     => "Property Viewing: {$appointment->property->title}",
                    'description' => "RealtyLinkPH viewing appointment.\n{$other}\nLocation: {$appointment->property->address}",
                    'location'    => $appointment->property->address,
                    'start'       => ['dateTime' => $start->toRfc3339String(), 'timeZone' => 'Asia/Manila'],
                    'end'         => ['dateTime' => $end->toRfc3339String(),   'timeZone' => 'Asia/Manila'],
                ]
            );

            if ($response->successful()) {
                return $response->json('id');
            }

            Log::warning('GoogleCalendar createEvent failed', ['status' => $response->status(), 'body' => $response->body()]);
        } catch (\Throwable $e) {
            Log::error('GoogleCalendar createEvent exception', ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Remove an event from a specific user's calendar.
     */
    public function deleteEvent(User $owner, string $eventId): void
    {
        if (! $this->hasValidToken($owner)) {
            return;
        }

        try {
            $token = $this->getAccessToken($owner);
            Http::withToken($token)->delete(self::CALENDAR_API . "/calendars/primary/events/{$eventId}");
        } catch (\Throwable $e) {
            Log::error('GoogleCalendar deleteEvent exception', ['error' => $e->getMessage()]);
        }
    }

    public function storeTokens(User $user, array $tokens): void
    {
        $user->update([
            'google_access_token'     => $tokens['access_token'] ?? $user->google_access_token,
            // Google only returns a refresh_token on first consent — keep the old one otherwise.
            'google_refresh_token'    => $tokens['refresh_token'] ?? $user->google_refresh_token,
            'google_token_expires_at' => now()->addSeconds($tokens['expires_in'] ?? 3600),
        ]);
    }

    public function disconnect(User $user): void
    {
        $user->update([
            'google_access_token'     => null,
            'google_refresh_token'    => null,
            'google_token_expires_at' => null,
        ]);
    }

    private function hasValidToken(User $user): bool
    {
        return $user->google_access_token !== null
            && ($user->google_token_expires_at === null
                || $user->google_token_expires_at->isFuture()
                || $user->google_refresh_token !== null);
    }

    private function getAccessToken(User $user): string
    {
        if ($user->google_token_expires_at?->isPast() && $user->google_refresh_token) {
            $this->refreshToken($user);
            $user->refresh();
        }

        return $user->google_access_token;
    }

    private function refreshToken(User $user): void
    {
        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id'     => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'refresh_token' => $user->google_refresh_token,
            'grant_type'    => 'refresh_token',
        ]);

        if ($response->successful()) {
            $this->storeTokens($user, $response->json());
        }
    }
}
