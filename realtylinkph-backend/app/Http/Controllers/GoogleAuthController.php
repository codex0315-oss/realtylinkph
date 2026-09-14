<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class GoogleAuthController extends Controller
{
    /**
     * Outbound calls to Google fail intermittently on this network — roughly one
     * fresh connection in eight times out, and the ones that succeed have been
     * measured anywhere from 0.5s to 7.5s, uncomfortably close to Laravel's
     * default 10s connect timeout.
     *
     * So: a longer connect budget, and retries — but only for connection
     * failures. An HTTP error response means Google answered and the
     * authorization code may already be spent; replaying it would turn one clear
     * error into a confusing "invalid_grant".
     */
    private function google(): PendingRequest
    {
        return Http::connectTimeout(20)
            ->timeout(30)
            ->retry(3, 400, fn ($e) => $e instanceof ConnectionException, throw: false);
    }

    /**
     * Build the Google sign-in consent URL (openid email profile).
     * Separate from the Calendar OAuth flow — this is for authentication.
     *
     * `intent` says which button the user pressed: "login" may only sign in an
     * account that already exists, "register" may create one. It rides along in
     * the OAuth `state` parameter, which Google hands back untouched, so the
     * callback still knows which it was after the round trip.
     */
    public function redirect(Request $request): JsonResponse
    {
        $intent = $request->query('intent') === 'register' ? 'register' : 'login';

        $params = http_build_query([
            'client_id'     => config('services.google.client_id'),
            'redirect_uri'  => config('services.google.login_redirect'),
            'response_type' => 'code',
            'scope'         => 'openid email profile',
            'access_type'   => 'online',
            'prompt'        => 'select_account',
            'state'         => $intent,
        ]);

        return ApiResponse::success(
            ['url' => 'https://accounts.google.com/o/oauth2/v2/auth?' . $params],
            'Google sign-in URL generated.',
            200
        );
    }

    /**
     * Exchange the authorization code, read the verified Google profile, then
     * issue a Sanctum token.
     *
     * Signing in never creates an account. Google verifying an address only
     * proves the person owns that mailbox — it says nothing about whether they
     * have registered with RealtyLink PH. Only the register flow may create.
     */
    public function callback(Request $request): JsonResponse
    {
        $request->validate([
            'code'   => ['required', 'string'],
            'intent' => ['nullable', 'in:login,register'],
        ]);

        // Defaults to the strict path: a request with no intent cannot register.
        $intent = $request->input('intent') === 'register' ? 'register' : 'login';

        // 1) Exchange the code for an access token.
        //    A network failure here is not the caller's fault and must not 500 —
        //    it gets its own status so the frontend can say something useful.
        try {
            $tokenRes = $this->google()->asForm()->post('https://oauth2.googleapis.com/token', [
                'code'          => $request->input('code'),
                'client_id'     => config('services.google.client_id'),
                'client_secret' => config('services.google.client_secret'),
                'redirect_uri'  => config('services.google.login_redirect'),
                'grant_type'    => 'authorization_code',
            ]);
        } catch (ConnectionException $e) {
            report($e);

            return ApiResponse::error(
                'Could not reach Google to complete your sign-in. Please check your internet connection and try again.',
                ['code' => 'google_unreachable'],
                503
            );
        }

        if (! $tokenRes->successful()) {
            return ApiResponse::error('Failed to verify Google sign-in. Please try again.', [], 400);
        }

        // 2) Read the user's profile.
        try {
            $profileRes = $this->google()->withToken($tokenRes->json('access_token'))
                ->get('https://www.googleapis.com/oauth2/v3/userinfo');
        } catch (ConnectionException $e) {
            report($e);

            return ApiResponse::error(
                'Could not reach Google to read your profile. Please check your internet connection and try again.',
                ['code' => 'google_unreachable'],
                503
            );
        }

        if (! $profileRes->successful()) {
            return ApiResponse::error('Could not read your Google profile.', [], 400);
        }

        $profile = $profileRes->json();
        $email   = $profile['email'] ?? null;

        if (! $email || ($profile['email_verified'] ?? false) !== true) {
            return ApiResponse::error('Your Google account does not have a verified email.', [], 422);
        }

        // 3) Link by verified email if the account already exists.
        $user = User::where('email', $email)->first();

        if (! $user) {
            // Pressed "Continue with Google" on the sign-in form with an address
            // that has never registered here — refuse, and tell the frontend why
            // so it can send them to the create-account form instead.
            if ($intent !== 'register') {
                return ApiResponse::error(
                    "No RealtyLink PH account is registered for {$email}. Please create an account first.",
                    ['code' => 'not_registered', 'email' => $email],
                    404
                );
            }

            $user = User::create([
                'name'      => $profile['name'] ?? Str::before($email, '@'),
                'email'     => $email,
                'password'  => Hash::make(Str::random(40)), // random — Google users sign in via Google
                'role_type' => 'buyer',                     // registration is buyer-only
                'avatar'    => $profile['picture'] ?? null, // external Google photo URL
            ]);
            $user->markEmailAsVerified();
        } elseif (! $user->hasVerifiedEmail()) {
            // Google vouches for the email — trust it.
            $user->markEmailAsVerified();
        }

        $token = $user->createToken('api')->plainTextToken;

        return ApiResponse::success([
            'user'  => UserResource::make($user->load('agentProfile')),
            'token' => $token,
        ], 'Signed in with Google.', 200);
    }
}
