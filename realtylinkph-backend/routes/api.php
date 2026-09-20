<?php

declare(strict_types=1);

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AgentController;
use App\Http\Controllers\AgentBlockedDateController;
use App\Http\Controllers\AiController;
use App\Http\Controllers\AgentVerificationController;
use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\GoogleAuthController;
use App\Http\Controllers\AvailabilityController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\FavoriteController;
use App\Http\Controllers\GeminiController;
use App\Http\Controllers\GoogleCalendarController;
use App\Http\Controllers\InquiryController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PresenceController;
use App\Http\Controllers\PropertyController;
use App\Http\Controllers\PropertyPhotoController;
use App\Http\Controllers\ReviewController;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// ─── Public routes ───────────────────────────────────────────────────────────

Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

// Google sign-in / sign-up (find-or-create, buyer accounts)
Route::get('/auth/google/redirect', [GoogleAuthController::class, 'redirect']);
Route::post('/auth/google/callback', [GoogleAuthController::class, 'callback'])->middleware('throttle:20,1');
Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:5,1');
Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:10,1');

// Email verification link (clicked from the verification email). Public but
// protected by Laravel's signed-URL signature. Defining this named route is
// what allows the VerifyEmail notification to build its link on registration.
Route::get('/email/verify/{id}/{hash}', function (Request $request, string $id, string $hash) {
    $user = User::findOrFail((int) $id);

    if (! hash_equals($hash, sha1($user->getEmailForVerification()))) {
        abort(403, 'Invalid verification link.');
    }

    if (! $user->hasVerifiedEmail()) {
        $user->markEmailAsVerified();
        event(new Verified($user));
    }

    $frontend = config('app.frontend_url');

    return redirect($frontend.'/?verified=1');
})->middleware('signed')->name('verification.verify');

// Public property browsing
Route::get('/properties', [PropertyController::class, 'index']);
Route::get('/properties/featured', [PropertyController::class, 'featured']); // before {property}
Route::get('/properties/home-rows', [PropertyController::class, 'homeRows']); // before {property}
Route::get('/properties/{property}', [PropertyController::class, 'show']);
Route::post('/properties/{property}/inquiries', [InquiryController::class, 'submit'])->middleware('throttle:5,1');

// Public agent directory (homepage top agents + agent profile page)
Route::get('/agents', [AgentController::class, 'index']);
Route::get('/agents/{agent}', [AgentController::class, 'show']);

// Public agent reviews
Route::get('/agents/{agent}/reviews', [ReviewController::class, 'forAgent']);

// Public availability (needed for booking form)
Route::get('/agents/{agent}/availability', [AvailabilityController::class, 'slots']);
Route::get('/agents/{agent}/unavailable-dates', [AvailabilityController::class, 'unavailableDates']);

// Gemini Q&A (public — no auth needed to ask a question about a listing)
Route::post('/properties/{property}/ask', [GeminiController::class, 'askQuestion'])->middleware('throttle:10,1');

// ─── Authenticated routes ────────────────────────────────────────────────────

Route::middleware('auth:sanctum')->group(function (): void {

    // Auth
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::put('/me', [AuthController::class, 'updateProfile']);
    Route::post('/me/password', [AuthController::class, 'changePassword']);
    Route::post('/email/verify', [AuthController::class, 'verifyEmail'])->middleware('throttle:6,1');
    Route::post('/email/resend', [AuthController::class, 'resendVerification'])->middleware('throttle:6,1');

    // Agent verification application
    Route::post('/agent/verify', [AgentVerificationController::class, 'submit']);
    Route::get('/agent/verify/status', [AgentVerificationController::class, 'status']);

    // Favorites / saved properties (any authenticated user)
    Route::get('/favorites', [FavoriteController::class, 'index']);
    Route::post('/properties/{property}/favorite', [FavoriteController::class, 'toggle']);

    // Presence (online / last-seen)
    Route::post('/heartbeat', [PresenceController::class, 'heartbeat']);
    Route::get('/users/{user}/presence', [PresenceController::class, 'show']);

    // Notifications
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead']);

    // RealtyLink AI — buyer assistant (rate-limited to protect the Gemini quota)
    Route::post('/ai/chat', [AiController::class, 'chat'])->middleware('throttle:20,1');

    // RealtyLink AI — agent companion (verified agents only)
    Route::post('/ai/agent-chat', [AiController::class, 'agentChat'])->middleware(['agent.verified', 'throttle:20,1']);

    // Google Calendar (any authenticated user — buyers AND agents can connect)
    Route::get('/google/redirect', [GoogleCalendarController::class, 'redirect']);
    Route::post('/google/callback', [GoogleCalendarController::class, 'callback']);
    Route::delete('/google/disconnect', [GoogleCalendarController::class, 'disconnect']);

    // ─── Verified agent routes ───────────────────────────────────────────────

    Route::middleware('agent.verified')->group(function (): void {

        // My listings
        Route::get('/my-listings', [PropertyController::class, 'myListings']);
        Route::get('/my-inventory', [PropertyController::class, 'inventory']);
        Route::post('/properties', [PropertyController::class, 'store']);
        Route::put('/properties/{property}', [PropertyController::class, 'update']);
        Route::delete('/properties/{property}', [PropertyController::class, 'destroy']);
        Route::post('/properties/{property}/publish', [PropertyController::class, 'publish']);
        Route::post('/properties/{property}/unpublish', [PropertyController::class, 'unpublish']);
        Route::post('/properties/{property}/mark-sold', [PropertyController::class, 'markSold']);
        Route::post('/properties/{property}/relist', [PropertyController::class, 'relist']);

        // Property photos
        Route::post('/properties/{property}/photos', [PropertyPhotoController::class, 'upload']);
        Route::put('/properties/{property}/photos/reorder', [PropertyPhotoController::class, 'reorder']);
        Route::delete('/properties/{property}/photos/{photo}', [PropertyPhotoController::class, 'destroy']);

        // Inquiries (agent reads own inquiries)
        Route::get('/inquiries', [InquiryController::class, 'index']);
        Route::post('/inquiries/{inquiry}/read', [InquiryController::class, 'markRead']);

        // Blocked dates
        Route::get('/blocked-dates', [AgentBlockedDateController::class, 'index']);
        Route::post('/blocked-dates', [AgentBlockedDateController::class, 'store']);
        Route::put('/blocked-dates/{agentBlockedDate}', [AgentBlockedDateController::class, 'update']);
        Route::delete('/blocked-dates/{agentBlockedDate}', [AgentBlockedDateController::class, 'destroy']);
    });

    // ─── Buyer / ghost_buyer routes ──────────────────────────────────────────

    // Appointments (buyers book, both parties view/manage)
    Route::get('/appointments', [AppointmentController::class, 'index']);
    Route::get('/appointments/{appointment}', [AppointmentController::class, 'show']);
    Route::post('/properties/{property}/appointments', [AppointmentController::class, 'book']);
    Route::post('/appointments/{appointment}/confirm', [AppointmentController::class, 'confirm']);
    Route::post('/appointments/{appointment}/complete', [AppointmentController::class, 'complete']);
    Route::post('/appointments/{appointment}/cancel', [AppointmentController::class, 'cancel']);

    // Conversations
    Route::get('/conversations', [ConversationController::class, 'index']);
    Route::get('/conversations/{conversation}', [ConversationController::class, 'show']);
    Route::post('/properties/{property}/conversations', [ConversationController::class, 'startOrGet']);
    Route::get('/conversations/{conversation}/messages', [ConversationController::class, 'messages']);
    Route::post('/conversations/{conversation}/messages', [ConversationController::class, 'sendMessage']);
    Route::post('/conversations/{conversation}/read', [ConversationController::class, 'markRead']);
    Route::post('/conversations/{conversation}/delivered', [ConversationController::class, 'markDelivered']);
    Route::delete('/conversations/{conversation}', [ConversationController::class, 'destroy']);

    // Reviews
    Route::post('/reviews', [ReviewController::class, 'submit']);
    Route::put('/reviews/{review}', [ReviewController::class, 'update']);
    Route::get('/agents/{agent}/reviewable', [ReviewController::class, 'reviewable']);

    // AI description generator (authenticated agents only)
    Route::post('/ai/generate-description', [GeminiController::class, 'generateDescription'])
        ->middleware('agent.verified');

    // ─── Admin routes ────────────────────────────────────────────────────────

    Route::middleware('admin')->prefix('admin')->group(function (): void {
        Route::get('/stats', [AdminController::class, 'stats']);
        Route::get('/actions', [AdminController::class, 'actions']);
        Route::get('/users', [AdminController::class, 'users']);
        Route::post('/admins', [AdminController::class, 'createAdmin']);
        Route::delete('/users/{user}', [AdminController::class, 'deleteUser']);
        Route::get('/properties', [AdminController::class, 'properties']);
        Route::get('/properties/{property}/featured-explain', [AdminController::class, 'featuredExplain']);
        Route::post('/properties/{property}/unpublish', [AdminController::class, 'unpublishProperty']);
        Route::delete('/properties/{property}', [AdminController::class, 'deleteProperty']);
        Route::get('/pending-agents', [AdminController::class, 'pendingAgents']);
        Route::get('/reviews', [AdminController::class, 'reviews']);
        Route::post('/reviews/{review}/toggle-visibility', [ReviewController::class, 'toggleVisibility']);
        Route::get('/agent-applications', [AgentVerificationController::class, 'pending']);
        Route::post('/agent-applications/{profile}/review', [AgentVerificationController::class, 'review']);
    });
});
