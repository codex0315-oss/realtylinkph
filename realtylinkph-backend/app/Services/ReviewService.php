<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AgentReview;
use App\Models\Appointment;
use App\Models\User;
use App\Notifications\ReviewReceived;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class ReviewService
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {}

    public function submit(User $buyer, Appointment $appointment, array $data): AgentReview
    {
        $review = DB::transaction(function () use ($buyer, $appointment, $data): AgentReview {
            if (! in_array($appointment->status, ['confirmed', 'completed'], true)) {
                throw new \RuntimeException('You can review an agent only after a confirmed or completed viewing.');
            }

            if ($appointment->buyer_id !== $buyer->id) {
                throw new \RuntimeException('You can only review appointments you attended.');
            }

            if ($appointment->review()->exists()) {
                throw new \RuntimeException('You have already reviewed this viewing.');
            }

            return AgentReview::create([
                'agent_id'       => $appointment->agent_id,
                'buyer_id'       => $buyer->id,
                'appointment_id' => $appointment->id,
                'rating'         => $data['rating'],
                'review_text'    => $data['review_text'] ?? null,
                'is_visible'     => true,
            ]);
        });

        // Notify the agent — in-app (instant) + email (queued).
        $agent    = $appointment->agent ?? User::find($appointment->agent_id);
        $property = $appointment->property;

        if ($agent) {
            $this->notificationService->send($agent, 'new_review', [
                'review_id'      => $review->id,
                'buyer_name'     => $buyer->name,
                'rating'         => $review->rating,
                'property_title' => $property?->title,
                'message'        => "{$buyer->name} left you a {$review->rating}-star review.",
            ]);

            $agent->notify(new ReviewReceived($review->loadMissing(['buyer', 'appointment.property'])));
        }

        return $review;
    }

    /**
     * The buyer's confirmed viewings with this agent that haven't been reviewed
     * yet — i.e. the appointments they're allowed to rate.
     *
     * @return Collection<int, Appointment>
     */
    public function reviewableAppointments(User $buyer, User $agent): Collection
    {
        return Appointment::with('property.photos')
            ->where('buyer_id', $buyer->id)
            ->where('agent_id', $agent->id)
            ->whereIn('status', ['confirmed', 'completed'])
            ->whereDoesntHave('review')
            ->latest('preferred_datetime')
            ->get();
    }

    public function toggleVisibility(AgentReview $review): AgentReview
    {
        $review->update(['is_visible' => ! $review->is_visible]);

        return $review->fresh();
    }

    public function listForAgent(User $agent, int $perPage = 15): LengthAwarePaginator
    {
        return AgentReview::with(['buyer', 'appointment.property'])
            ->where('agent_id', $agent->id)
            ->visible()
            ->latest()
            ->paginate($perPage);
    }

    public function getAverageRating(User $agent): float
    {
        return round(
            AgentReview::where('agent_id', $agent->id)->visible()->avg('rating') ?? 0,
            1
        );
    }
}
