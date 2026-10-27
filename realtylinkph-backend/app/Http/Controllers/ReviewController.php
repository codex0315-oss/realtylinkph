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
     * Homepage testimonials. Deliberately not ReviewResource: that embeds the
     * full UserResource (email, phone) for the buyer. A public page only needs
     * "Maria S." and who they reviewed.
     */
    public function featured(): JsonResponse
    {
        $items = $this->service->featured(6)->map(fn (AgentReview $r) => [
            'id'             => $r->id,
            'rating'         => $r->rating,
            'review_text'    => $r->review_text,
            'is_verified'    => $r->isVerifiedViewing(),
            'property_title' => $r->appointment?->property?->title,
            'buyer_name'     => self::shortName($r->buyer?->name),
            'buyer_avatar'   => \App\Support\Uploads::url($r->buyer?->avatar),
            'agent_id'       => $r->agent_id,
            'agent_name'     => $r->agent?->name,
            'created_at'     => $r->created_at?->toISOString(),
        ]);

        return ApiResponse::success($items, 'Featured reviews retrieved.');
    }

    /** "Maria Clara Santos" → "Maria S." */
    private static function shortName(?string $name): string
    {
        $parts = preg_split('/\s+/', trim((string) $name)) ?: [];
        if (! $parts || $parts[0] === '') {
            return 'A RealtyLink buyer';
        }
        $first = $parts[0];
        $last  = count($parts) > 1 ? mb_strtoupper(mb_substr(end($parts), 0, 1)) . '.' : '';

        return trim("{$first} {$last}");
    }

    /**
     * The signed-in buyer's own reviews, newest first, with the agent and the
     * viewing each one is about (for the buyer's Reviews page).
     */
    public function mine(Request $request): JsonResponse
    {
        $reviews = AgentReview::with(['agent.agentProfile', 'appointment.property.photos'])
            ->where('buyer_id', $request->user()->id)
            ->latest()
            ->get();

        return ApiResponse::success(ReviewResource::collection($reviews), 'Your reviews retrieved.');
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

    /**
     * The agent answers a review publicly (or edits their answer). The buyer is
     * told the first time. Saved without touching updated_at, so the review
     * doesn't show as "edited" by its author.
     */
    public function reply(Request $request, AgentReview $review): JsonResponse
    {
        $this->authorize('reply', $review);

        $text = $request->validate(
            ['reply' => ['required', 'string', 'min:2', 'max:1000']],
            ['reply.required' => 'Write a reply first.'],
        )['reply'];

        $first = $review->agent_reply === null;
        $review->timestamps = false;
        $review->forceFill(['agent_reply' => trim($text), 'agent_replied_at' => now()])->save();
        $review->timestamps = true;

        $review->loadMissing(['buyer', 'agent', 'appointment.property']);
        if ($first && $review->buyer) {
            app(\App\Services\NotificationService::class)->send($review->buyer, 'review_replied', [
                'agent_id'   => $review->agent_id,
                'agent_name' => $review->agent?->name,
                'review_id'  => $review->id,
                'message'    => "{$review->agent?->name} replied to your review.",
            ]);
        }

        return ApiResponse::success(ReviewResource::make($review), 'Reply saved.');
    }

    public function deleteReply(AgentReview $review): JsonResponse
    {
        $this->authorize('reply', $review);

        $review->timestamps = false;
        $review->forceFill(['agent_reply' => null, 'agent_replied_at' => null])->save();
        $review->timestamps = true;

        return ApiResponse::success(ReviewResource::make($review->loadMissing(['buyer', 'appointment.property'])), 'Reply removed.');
    }

    /**
     * Hide a review (a reason is required and the reviewer is told) or show it
     * again. Both are logged in Activity.
     */
    public function toggleVisibility(Request $request, AgentReview $review): JsonResponse
    {
        $this->authorize('toggleVisibility', $review);

        $hiding = $review->is_visible;
        $reason = $hiding
            ? $request->validate(
                ['reason' => ['required', 'string', 'min:10', 'max:500']],
                ['reason.required' => 'Please say why the review is being hidden.', 'reason.min' => 'Please give a reason the reviewer can understand (at least 10 characters).'],
            )['reason']
            : null;

        $review = $this->service->toggleVisibility($review, $reason, $request->user());

        $review->loadMissing(['buyer', 'agent', 'appointment.property']);
        \App\Support\AdminAudit::log(
            $review->is_visible ? 'review.shown' : 'review.hidden',
            $review,
            "{$review->buyer?->name} → {$review->agent?->name} ({$review->rating}/5)",
            array_filter(['reason' => $reason]),
        );

        if ($hiding && $review->buyer) {
            app(\App\Services\NotificationService::class)->send($review->buyer, 'review_hidden', [
                'agent_name' => $review->agent?->name,
                'reason'     => $reason,
                'message'    => "Your review of {$review->agent?->name} was hidden by an admin: {$reason}",
            ]);
        }

        return ApiResponse::success(ReviewResource::make($review), 'Review visibility toggled.', 200);
    }
}
