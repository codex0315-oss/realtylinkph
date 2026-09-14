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
            'scope'         => 'https://www.googleapis.com/auth/calendar.events',
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

        $this->service->storeTokens($request->user(), $response->json());

        return ApiResponse::success(null, 'Google Calendar connected.', 200);
    }

    public function disconnect(Request $request): JsonResponse
    {
        $this->service->disconnect($request->user());

        return ApiResponse::success(null, 'Google Calendar disconnected.', 200);
    }
}
