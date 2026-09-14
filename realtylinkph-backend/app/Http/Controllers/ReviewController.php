<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Http\Requests\Review\SubmitReviewRequest;
use App\Http\Resources\AppointmentResource;
use App\Http\Resources\ReviewResource;
use App\Models\AgentReview;
use App\Models\Appointment;
use App\Models\User;
use App\Services\ReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    public function __construct(private readonly ReviewService $service) {}

    public function forAgent(User $agent): JsonResponse
    {
        $reviews = $this->service->listForAgent($agent);

        return ApiResponse::paginated(ReviewResource::collection($reviews), 'Reviews retrieved.');
    }

    /**
     * The current buyer's confirmed-but-unreviewed viewings with this agent,
     * i.e. the viewings they're allowed to rate. Empty for guests/non-buyers.
     */
    public function reviewable(Request $request, User $agent): JsonResponse
    {
        $user = $request->user();

        if (! $user || $user->role_type !== 'buyer') {
            return ApiResponse::success([], 'No reviewable viewings.');
        }

        $appointments = $this->service->reviewableAppointments($user, $agent);

        return ApiResponse::success(AppointmentResource::collection($appointments), 'Reviewable viewings retrieved.');
    }

    public function submit(SubmitReviewRequest $request): JsonResponse
    {
        $this->authorize('create', AgentReview::class);

        $appointment = Appointment::findOrFail($request->validated('appointment_id'));

        try {
            $review = $this->service->submit($request->user(), $appointment, $request->validated());
        } catch (\RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), [], 422);
        }

        return ApiResponse::success(ReviewResource::make($review), 'Review submitted.', 201);
    }

    public function toggleVisibility(AgentReview $review): JsonResponse
    {
        $this->authorize('toggleVisibility', $review);

        $review = $this->service->toggleVisibility($review);

        return ApiResponse::success(ReviewResource::make($review), 'Review visibility toggled.', 200);
    }
}
