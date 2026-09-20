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
     * May the current buyer review this agent, and on what basis? Returns the
     * basis, the viewings it rests on (for the "which viewing?" picker), and
     * their existing review if they already left one (so the UI offers Edit).
     */
    public function reviewable(Request $request, User $agent): JsonResponse
    {
        $user = $request->user();

        if (! $user || $user->role_type !== 'buyer' || $user->id === $agent->id) {
            return ApiResponse::success([
                'eligible' => false, 'basis' => null, 'verified' => false,
                'appointments' => [], 'conversation_id' => null, 'review' => null,
            ], 'Not eligible to review.');
        }

        $e = $this->service->eligibility($user, $agent);

        return ApiResponse::success([
            'eligible'        => $e['eligible'],
            'basis'           => $e['basis'],
            'verified'        => $e['verified'],
            'appointments'    => AppointmentResource::collection($e['appointments']),
            'conversation_id' => $e['conversation_id'],
            'review'          => $e['review'] ? ReviewResource::make($e['review']->load(['appointment.property'])) : null,
        ], 'Review eligibility retrieved.');
    }

    public function submit(SubmitReviewRequest $request): JsonResponse
    {
        $this->authorize('create', AgentReview::class);

        $data = $request->validated();

        // A viewing id pins the review to that viewing; the agent follows from it.
        $agent = isset($data['appointment_id'])
            ? User::findOrFail(Appointment::findOrFail($data['appointment_id'])->agent_id)
            : User::findOrFail($data['agent_id']);

        try {
            $review = $this->service->submit($request->user(), $agent, $data);
        } catch (\RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), [], 422);
        }

        return ApiResponse::success(ReviewResource::make($review), 'Review submitted.', 201);
    }

    /** Edit your own review — rating and text only; the basis stays as recorded. */
    public function update(SubmitReviewRequest $request, AgentReview $review): JsonResponse
    {
        $this->authorize('update', $review);

        $review = $this->service->update($review, $request->validated());

        return ApiResponse::success(ReviewResource::make($review), 'Review updated.', 200);
    }

    public function toggleVisibility(AgentReview $review): JsonResponse
    {
        $this->authorize('toggleVisibility', $review);

        $review = $this->service->toggleVisibility($review);

        $review->loadMissing(['buyer', 'agent', 'appointment.property']);
        \App\Support\AdminAudit::log(
            $review->is_visible ? 'review.shown' : 'review.hidden',
            $review,
            "{$review->buyer?->name} → {$review->agent?->name} ({$review->rating}/5)",
        );

        return ApiResponse::success(ReviewResource::make($review), 'Review visibility toggled.', 200);
    }
}
