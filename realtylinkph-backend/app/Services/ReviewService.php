<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AgentReview;
use App\Models\Appointment;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Notifications\ReviewReceived;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Who may review whom, and on what basis.
 *
 * A buyer may review an agent they have actually dealt with:
 *  - a viewing that took place (completed, or confirmed and past) — this is
 *    the "verified viewing" basis and earns the badge;
 *  - a viewing the agent cancelled, or a request that expired unanswered —
 *    the agent's reliability is exactly what the buyer should be able to rate;
 *  - a conversation in which the agent replied at least once themselves (not
 *    RealtyLink AI's away-reply). One "hi" from the buyer alone is not enough,
 *    or anyone could rate an agent they never dealt with.
 *
 * One review per buyer per agent, editable — a buyer who chatted, then
 * viewed, updates the same review rather than stacking a second one.
 */
class ReviewService
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {}

    /**
     * @return array{
     *   eligible: bool, basis: 'viewing'|'agent_cancelled'|'conversation'|null,
     *   verified: bool, appointments: \Illuminate\Support\Collection<int, Appointment>,
     *   conversation_id: int|null, review: AgentReview|null
     * }
     */
    public function eligibility(User $buyer, User $agent): array
    {
        $existing = AgentReview::where('buyer_id', $buyer->id)->where('agent_id', $agent->id)->first();

        $appointments = Appointment::with('property.photos')
            ->where('buyer_id', $buyer->id)
            ->where('agent_id', $agent->id)
            ->latest('preferred_datetime')
            ->get();

        $viewings  = $appointments->filter(fn (Appointment $a) => $a->isReviewable())->values();
        $cancelled = $appointments->filter(fn (Appointment $a) => $a->wasCancelledByAgent())->values();

        $conversationId = Conversation::where('buyer_id', $buyer->id)
            ->where('agent_id', $agent->id)
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('messages')
                ->whereColumn('messages.conversation_id', 'conversations.id')
                ->where('messages.sender_id', $agent->id)
                ->where('messages.is_ai', false))
            ->latest('last_message_at')
            ->value('id');

        $basis = $viewings->isNotEmpty() ? 'viewing'
            : ($cancelled->isNotEmpty() ? 'agent_cancelled'
            : ($conversationId ? 'conversation' : null));

        return [
            'eligible'        => $basis !== null,
            'basis'           => $basis,
            'verified'        => $basis === 'viewing',
            'appointments'    => $basis === 'viewing' ? $viewings : $cancelled,
            'conversation_id' => $conversationId,
            'review'          => $existing,
        ];
    }

    /**
     * Create the buyer's review of this agent. `appointment_id` (optional)
     * pins it to a specific viewing when they had several.
     */
    public function submit(User $buyer, User $agent, array $data): AgentReview
    {
        if ($buyer->id === $agent->id) {
            throw new \RuntimeException('You cannot review yourself.');
        }

        $e = $this->eligibility($buyer, $agent);

        if (! $e['eligible']) {
            throw new \RuntimeException('You can review an agent after a viewing with them, or once they have replied to you in chat.');
        }
        if ($e['review'] !== null) {
            throw new \RuntimeException('You have already reviewed this agent — you can edit your review instead.');
        }

        $appointmentId = $this->pickAppointment($e, $data['appointment_id'] ?? null);

        $review = AgentReview::create([
            'agent_id'        => $agent->id,
            'buyer_id'        => $buyer->id,
            'appointment_id'  => $appointmentId,
            'conversation_id' => $appointmentId === null ? $e['conversation_id'] : null,
            'rating'          => $data['rating'],
            'review_text'     => $data['review_text'] ?? null,
            'is_visible'      => true,
        ]);

        $review->load(['appointment.property', 'buyer', 'agent']);
        $this->notifyAgent($agent, $buyer, $review, isEdit: false);

        return $review;
    }

    /** Edit an existing review (rating / text). Basis stays as recorded. */
    public function update(AgentReview $review, array $data): AgentReview
    {
        $review->update([
            'rating'      => $data['rating'],
            'review_text' => $data['review_text'] ?? null,
        ]);

        $review->loadMissing(['agent', 'buyer', 'appointment.property']);
        $this->notifyAgent($review->agent, $review->buyer, $review, isEdit: true);

        return $review->fresh(['appointment.property', 'buyer', 'agent']);
    }

    /** The viewing this review is about: the one the buyer chose, else the latest eligible one. */
    private function pickAppointment(array $eligibility, ?int $requested): ?int
    {
        /** @var \Illuminate\Support\Collection<int, Appointment> $candidates */
        $candidates = $eligibility['appointments'];
        if ($candidates->isEmpty()) {
            return null;
        }
        if ($requested !== null) {
            $match = $candidates->firstWhere('id', $requested);
            if ($match === null) {
                throw new \RuntimeException('That viewing is not one you can review.');
            }

            return $match->id;
        }

        return $candidates->first()->id;
    }

    private function notifyAgent(User $agent, User $buyer, AgentReview $review, bool $isEdit): void
    {
        $property = $review->appointment?->property;

        $this->notificationService->send($agent, 'new_review', [
            'review_id'      => $review->id,
            'buyer_name'     => $buyer->name,
            'rating'         => $review->rating,
            'property_title' => $property?->title,
            'message'        => $isEdit
                ? "{$buyer->name} updated their review of you — now {$review->rating} stars."
                : "{$buyer->name} left you a {$review->rating}-star review.",
        ]);

        if (! $isEdit) {
            $agent->notify(new ReviewReceived($review->loadMissing(['buyer', 'appointment.property'])));
        }
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
}
