<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Services\GoogleCalendarService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class GoogleCalendarController extends Controller
{
    public function __construct(private readonly GoogleCalendarService $service) {}

    public function redirect(): JsonResponse
    {
        $params = http_build_query([
            'client_id'     => config('services.google.client_id'),
            'redirect_uri'  => config('services.google.redirect'),
            'response_type' => 'code',
            // Non-sensitive scope: only calendars this app creates, never the
            // user's own. `calendar.events` (write to primary) is classed as
            // sensitive and shows an "unverified app" wall until Google's
            // verification review — weeks, and needs a hosted privacy policy.
            'scope'         => 'https://www.googleapis.com/auth/calendar.app.created',
            'access_type'   => 'offline',
            'prompt'        => 'consent',
        ]);

        return ApiResponse::success(
            ['url' => 'https://accounts.google.com/o/oauth2/v2/auth?' . $params],
            'Google OAuth URL generated.',
            200
        );
    }

    public function callback(Request $request): JsonResponse
    {
        $request->validate(['code' => ['required', 'string']]);

        $response = Http::post('https://oauth2.googleapis.com/token', [
            'code'          => $request->input('code'),
            'client_id'     => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'redirect_uri'  => config('services.google.redirect'),
            'grant_type'    => 'authorization_code',
        ]);

        if (! $response->successful()) {
            return ApiResponse::error('Failed to exchange Google authorization code.', [], 400);
        }

        $user = $request->user();
        $this->service->storeTokens($user, $response->json());

        // Create the app's calendar now rather than on the first viewing, so
        // `has_gcal` is only ever true when sync will actually work. A token
        // that can't produce a calendar is a broken connection, not a
        // connection — unwind it and let the user retry.
        if ($this->service->ensureCalendar($user) === null) {
            $this->service->disconnect($user);

            return ApiResponse::error('Google signed in, but the RealtyLink calendar could not be created. Please try again.', [], 502);
        }

        return ApiResponse::success(null, 'Google Calendar connected.', 200);
    }

    public function disconnect(Request $request): JsonResponse
    {
        $this->service->disconnect($request->user());

        return ApiResponse::success(null, 'Google Calendar disconnected.', 200);
    }
}
