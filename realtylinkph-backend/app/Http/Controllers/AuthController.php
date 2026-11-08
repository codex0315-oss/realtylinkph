<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Support\Uploads;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Notifications\EmailVerificationCode;
use App\Services\AdminTwoFactorService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;

class AuthController extends Controller
{
    public function __construct(private readonly AdminTwoFactorService $twoFactor)
    {
    }

    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create([
            'name'      => $request->name,
            'email'     => $request->email,
            'password'  => Hash::make($request->password),
            'phone'     => $request->phone,
            'role_type' => $request->role_type,
        ]);

        event(new Registered($user));

        $token = $user->createToken('api')->plainTextToken;

        return ApiResponse::success([
            'user'  => UserResource::make($user),
            'token' => $token,
        ], 'Registration successful. Please verify your email.', 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        if (! Auth::attempt($request->only('email', 'password'))) {
            return ApiResponse::error('Invalid credentials.', [], 401);
        }

        /** @var User $user */
        $user = Auth::user();

        // The RealtyLink AI system account exists only so its chat replies have
        // a sender row. It has no business signing in.
        if ($user->is_system) {
            Auth::logout();

            return ApiResponse::error('Invalid credentials.', [], 401);
        }

        // Admins get a second step: a code by email. The token comes after it.
        if ($this->twoFactor->required($user) && ! $this->twoFactor->deviceTrusted($user, $request->input('device_token'))) {
            Auth::logout();

            return $this->twoFactorChallenge($user);
        }

        $token = $user->createToken('api')->plainTextToken;

        return ApiResponse::success([
            'user'  => UserResource::make($user->load('agentProfile')),
            'token' => $token,
        ], 'Login successful.', 200);
    }

    /** Start the admin code step and answer with the challenge (or say the code could not be sent). */
    private function twoFactorChallenge(User $user): JsonResponse
    {
        $challenge = $this->twoFactor->begin($user);

        if ($challenge === null) {
            return ApiResponse::error('We could not email your sign-in code. Please try again in a moment.', [], 503);
        }

        return ApiResponse::success(['two_factor' => $challenge], 'Enter the code we emailed you.', 200);
    }

    /** Finish an admin sign-in with the emailed code. */
    public function verifyTwoFactor(Request $request): JsonResponse
    {
        $data = $request->validate([
            'challenge' => ['required', 'string', 'max:100'],
            'code'      => ['required', 'string', 'regex:/^[0-9]{6}$/'],
            'remember'  => ['sometimes', 'boolean'],
        ]);

        $result = $this->twoFactor->verify($data['challenge'], $data['code']);

        if (is_string($result)) {
            return match ($result) {
                'expired' => ApiResponse::error('That code has expired. Sign in again to get a new one.', ['code' => 'expired'], 422),
                'locked'  => ApiResponse::error('Too many wrong codes. Sign in again to get a new one.', ['code' => 'locked'], 422),
                default   => ApiResponse::error('That code is not right. Check the email and try again.', ['code' => 'wrong'], 422),
            };
        }

        return ApiResponse::success([
            'user'  => UserResource::make($result->load('agentProfile')),
            'token' => $result->createToken('api')->plainTextToken,
            // "Trust this device": the browser keeps this key and skips the code for a while.
            'device_token' => ! empty($data['remember']) ? $this->twoFactor->trustDevice($result, $request->userAgent()) : null,
            'device_days'  => ! empty($data['remember']) ? AdminTwoFactorService::TRUST_DAYS : null,
        ], 'Login successful.', 200);
    }

    /** Email a fresh code for an open challenge. */
    public function resendTwoFactor(Request $request): JsonResponse
    {
        $data = $request->validate(['challenge' => ['required', 'string', 'max:100']]);

        return match ($this->twoFactor->resend($data['challenge'])) {
            'sent'     => ApiResponse::success(null, 'A new code is on its way.', 200),
            'too_soon' => ApiResponse::error('Please wait a few seconds before asking for another code.', ['code' => 'too_soon'], 429),
            'failed'   => ApiResponse::error('We could not email the code. Please try again in a moment.', [], 503),
            default    => ApiResponse::error('That sign-in has expired. Sign in again to get a new code.', ['code' => 'expired'], 422),
        };
    }

    public function logout(Request $request): JsonResponse
    {
        // A bearer token is revoked; a session-authenticated caller (Sanctum's
        // TransientToken) has nothing to delete.
        $token = $request->user()->currentAccessToken();
        if ($token instanceof \Laravel\Sanctum\PersonalAccessToken) {
            $token->delete();
        }

        // Stop showing as "Active now" at once; "last active" becomes this moment.
        \Illuminate\Support\Facades\DB::table('users')->where('id', $request->user()->id)
            ->update(['signed_out_at' => now(), 'last_seen_at' => now()]);

        return ApiResponse::success(null, 'Logged out successfully.', 200);
    }

    public function me(Request $request): JsonResponse
    {
        return ApiResponse::success(
            UserResource::make($request->user()->load('agentProfile')),
            'User profile retrieved.',
            200
        );
    }

    public function updateProfile(UpdateProfileRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->only(['name', 'phone', 'theme', 'property_alerts']);

        if ($request->hasFile('avatar')) {
            if ($user->avatar) {
                Uploads::disk()->delete($user->avatar);
            }

            // Store the uploaded image as-is (no GD/Imagick driver available in this env).
            $data['avatar'] = $request->file('avatar')->store('avatars', Uploads::name());
        }

        $user->update($data);

        return ApiResponse::success(
            UserResource::make($user->fresh('agentProfile')),
            'Profile updated successfully.',
            200
        );
    }

    public function changePassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password'         => ['required', 'confirmed', \Illuminate\Validation\Rules\Password::min(8)->letters()->numbers()],
        ]);

        $request->user()->update(['password' => Hash::make($validated['password'])]);
        $this->twoFactor->forgetDevices($request->user());   // a new password means every trusted browser starts over

        return ApiResponse::success(null, 'Password changed successfully.', 200);
    }

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $status = Password::sendResetLink($request->only('email'));

        return match ($status) {
            Password::RESET_LINK_SENT => ApiResponse::success(
                null,
                'Check your inbox — we sent you a link to reset your password.',
                200,
            ),

            // The broker allows one request per minute per email.
            Password::RESET_THROTTLED => ApiResponse::error(
                'You already requested a reset link a moment ago. Please wait a minute before trying again.',
                [],
                429,
            ),

            default => ApiResponse::error(
                'We could not send a reset link to that address. Please try again.',
                [],
                400,
            ),
        };
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password): void {
                // Also how a Google sign-up gets a password of their own.
                $user->update(['password' => Hash::make($password), 'password_set' => true]);
                $user->tokens()->delete();
                $this->twoFactor->forgetDevices($user);
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return ApiResponse::error('Invalid or expired reset token.', [], 400);
        }

        return ApiResponse::success(null, 'Password reset successfully.', 200);
    }

    /**
     * Confirm the email using the 6-digit code that was emailed to the user.
     */
    public function verifyEmail(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return ApiResponse::success(UserResource::make($user), 'Email already verified.', 200);
        }

        $data = $request->validate([
            'code' => ['required', 'string', 'digits:6'],
        ]);

        $cacheKey = "email-verify-code:{$user->id}";
        $cached   = Cache::get($cacheKey);

        if (! $cached) {
            return ApiResponse::error('Your code has expired. Please request a new one.', [], 422);
        }

        if (! hash_equals((string) $cached, $data['code'])) {
            return ApiResponse::error('Invalid verification code.', [], 422);
        }

        $user->markEmailAsVerified();
        Cache::forget($cacheKey);

        return ApiResponse::success(UserResource::make($user->fresh()), 'Email verified successfully.', 200);
    }

    /**
     * Generate a 6-digit verification code, cache it for 15 minutes, and email it.
     */
    public function resendVerification(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return ApiResponse::success(null, 'Email already verified.', 200);
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        Cache::put("email-verify-code:{$user->id}", $code, now()->addMinutes(15));

        $user->notify(new EmailVerificationCode($code));

        return ApiResponse::success(null, 'We sent a 6-digit verification code to your email.', 200);
    }
}
