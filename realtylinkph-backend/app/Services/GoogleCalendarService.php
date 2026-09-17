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
     * Name of the secondary calendar this app creates in each connected
     * user's Google account. Viewings go there rather than to `primary`.
     *
     * That is what allows the `calendar.app.created` OAuth scope, which
     * Google classes as non-sensitive: the app can only touch calendars it
     * made itself, so users never see the "Google hasn't verified this app"
     * interstitial that every sensitive scope triggers until verification.
     */
    public const CALENDAR_NAME = 'RealtyLink PH Viewings';

    private const TIMEZONE = 'Asia/Manila';

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
            $calendarId = $this->ensureCalendar($owner);
            if ($calendarId === null) {
                return null;
            }

            $token = $this->getAccessToken($owner);
            $start = $appointment->preferred_datetime;
            // Same length as a bookable slot — a hard-coded hour against
            // 30-minute slots produced overlapping events in the real calendar.
            $end   = $start->copy()->addMinutes(AvailabilityService::SLOT_MINUTES);

            $payload = [
                'summary'     => "Property Viewing: {$appointment->property->title}",
                'description' => "RealtyLinkPH viewing appointment.\n{$other}\nLocation: {$appointment->property->address}",
                'location'    => $appointment->property->address,
                'start'       => ['dateTime' => $start->toRfc3339String(), 'timeZone' => self::TIMEZONE],
                'end'         => ['dateTime' => $end->toRfc3339String(),   'timeZone' => self::TIMEZONE],
            ];

            $response = $this->insertEvent($token, $calendarId, $payload);

            // The user can delete the app's calendar from Google Calendar's own
            // UI at any time. Rather than silently dropping every viewing from
            // then on, recreate it once and retry.
            if ($response->status() === 404) {
                $owner->update(['google_calendar_id' => null]);
                $calendarId = $this->createCalendar($owner);
                if ($calendarId !== null) {
                    $response = $this->insertEvent($token, $calendarId, $payload);
                }
            }

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
        if (! $this->hasValidToken($owner) || $owner->google_calendar_id === null) {
            return;
        }

        try {
            $token = $this->getAccessToken($owner);
            Http::withToken($token)->delete(
                self::CALENDAR_API . "/calendars/{$owner->google_calendar_id}/events/{$eventId}"
            );
        } catch (\Throwable $e) {
            Log::error('GoogleCalendar deleteEvent exception', ['error' => $e->getMessage()]);
        }
    }

    /**
     * The id of this app's calendar in the user's account, creating it on
     * first use. Null means it could not be created — treat as "not connected".
     */
    public function ensureCalendar(User $user): ?string
    {
        return $user->google_calendar_id ?? $this->createCalendar($user);
    }

    private function createCalendar(User $user): ?string
    {
        try {
            $response = Http::withToken($this->getAccessToken($user))->post(
                self::CALENDAR_API . '/calendars',
                ['summary' => self::CALENDAR_NAME, 'timeZone' => self::TIMEZONE],
            );

            $id = $response->successful() ? $response->json('id') : null;

            if (is_string($id) && $id !== '') {
                $user->update(['google_calendar_id' => $id]);

                return $id;
            }

            Log::warning('GoogleCalendar createCalendar failed', ['status' => $response->status(), 'body' => $response->body()]);
        } catch (\Throwable $e) {
            Log::error('GoogleCalendar createCalendar exception', ['error' => $e->getMessage()]);
        }

        return null;
    }

    private function insertEvent(string $token, string $calendarId, array $payload): \Illuminate\Http\Client\Response
    {
        return Http::withToken($token)->post(
            self::CALENDAR_API . "/calendars/{$calendarId}/events",
            $payload,
        );
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
        // google_calendar_id is deliberately kept. The calendar itself stays in
        // the user's Google account (with their viewing history), and the scope
        // re-grants access to app-created calendars on reconnect — so keeping
        // the id means reconnecting reuses it instead of creating a duplicate.
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
