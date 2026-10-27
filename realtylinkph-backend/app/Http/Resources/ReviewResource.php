<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'agent_id'       => $this->agent_id,
            'buyer_id'       => $this->buyer_id,
            'appointment_id' => $this->appointment_id,
            'conversation_id' => $this->conversation_id,
            'rating'         => $this->rating,
            'review_text'    => $this->review_text,
            // The agent's public reply, if they gave one.
            'agent_reply'      => $this->agent_reply,
            'agent_replied_at' => $this->agent_replied_at?->toISOString(),
            'is_visible'     => $this->is_visible,
            // Why it was hidden: for the admin and the reviewer; who did it: admin only.
            'hidden_reason'  => $this->when(! $this->is_visible && $this->canSeeModeration($request), $this->hidden_reason),
            'hidden_by'      => $this->when(! $this->is_visible && ($request->user('sanctum')?->isAdmin() ?? false), $this->hidden_by),
            'hidden_at'      => $this->when(! $this->is_visible && $this->canSeeModeration($request), fn () => $this->hidden_at?->toISOString()),
            // "Verified viewing" badge: the review rests on a viewing that took place.
            'is_verified'    => $this->relationLoaded('appointment') && $this->isVerifiedViewing(),
            'property_title' => $this->whenLoaded('appointment', fn () => $this->appointment?->property?->title),
            'property_id'    => $this->whenLoaded('appointment', fn () => $this->appointment?->property_id),
            'viewed_at'      => $this->whenLoaded('appointment', fn () => $this->appointment?->preferred_datetime?->toISOString()),
            // Cover photo of the viewed listing, when photos were loaded (buyer's Reviews page).
            'property_photo' => $this->when(
                $this->relationLoaded('appointment') && $this->appointment?->property?->relationLoaded('photos'),
                fn () => ($p = $this->appointment->property->photos->firstWhere('is_360', false) ?? $this->appointment->property->photos->first())
                    ? ($p->thumb_url ?? $p->url) : null,
            ),
            'is_edited'      => $this->updated_at !== null && $this->created_at !== null && $this->updated_at->gt($this->created_at->copy()->addMinute()),
            'created_at'     => $this->created_at?->toISOString(),
            'updated_at'     => $this->updated_at?->toISOString(),
            'agent'          => UserResource::make($this->whenLoaded('agent')),
            'buyer'          => $this->whenLoaded('buyer', fn () => $this->buyerFor($request)),
        ];
    }

    private function canSeeModeration(Request $request): bool
    {
        $viewer = $request->user('sanctum');

        return $viewer !== null && ($viewer->isAdmin() || $viewer->id === $this->buyer_id);
    }

    /**
     * Reviews are public (an agent's profile lists them), so the reviewer is
     * shown as a name and a face — never their email or phone. The full
     * record is only for an admin moderating, or the reviewer themselves.
     */
    private function buyerFor(Request $request): mixed
    {
        if ($this->buyer === null) {
            return null;
        }

        $viewer = $request->user('sanctum');
        if ($viewer && ($viewer->isAdmin() || $viewer->id === $this->buyer_id)) {
            return UserResource::make($this->buyer);
        }

        return [
            'id'     => $this->buyer->id,
            'name'   => $this->buyer->name,
            'avatar' => \App\Support\Uploads::url($this->buyer->avatar),
        ];
    }
}
