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

        $token = $user->createToken('api')->plainTextToken;

        return ApiResponse::success([
            'user'  => UserResource::make($user->load('agentProfile')),
            'token' => $token,
        ], 'Login successful.', 200);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

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
                $user->update(['password' => Hash::make($password)]);
                $user->tokens()->delete();
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
