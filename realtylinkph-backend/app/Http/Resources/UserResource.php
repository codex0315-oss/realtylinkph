<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use App\Support\Uploads;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /** Set by forOwner(): the response goes to this user themselves. */
    private bool $owner = false;

    /**
     * The user's own account, for sign-in / register responses. Those requests
     * carry no token yet, so without this the owner-only fields (email
     * verified, password set, theme...) were left out and the app showed
     * "Verify your email" to people who already had.
     */
    public static function forOwner(User $user): static
    {
        $resource = static::make($user);
        $resource->owner = true;

        return $resource;
    }

    public function toArray(Request $request): array
    {
        $viewer = $request->user('sanctum');
        $isSelf = $this->owner || ($viewer !== null && $viewer->id === $this->id);
        $isAdmin = $viewer !== null && $viewer->isAdmin();
        // Account settings are for the owner (and an admin), not for everyone
        // who sees this person as a listing's agent or a chat partner.
        $ownerOrAdmin = $isSelf || $isAdmin;

        return [
            'id'                 => $this->id,
            'name'               => $this->name,
            'email'              => $this->email,
            'phone'              => $this->phone,
            'avatar'             => Uploads::url($this->avatar),
            'cover_url'          => Uploads::url($this->cover_photo),
            'cover_position'     => (int) ($this->cover_position ?? 50),
            'role_type'          => $this->role_type,
            'theme'              => $this->when($ownerOrAdmin, fn () => $this->theme ?? 'light'),
            'property_alerts'    => $this->when($ownerOrAdmin, fn () => (bool) $this->property_alerts),
            'has_gcal'           => $this->when($ownerOrAdmin, fn () => $this->google_access_token !== null),
            // False for "Continue with Google" sign-ups who never set one.
            'password_set'       => $this->when($isSelf, fn () => $this->password_set !== false),
            'email_verified_at'  => $this->when($ownerOrAdmin, fn () => $this->email_verified_at?->toISOString()),
            'last_seen_at'       => $this->last_seen_at?->toISOString(),
            'is_online'          => $this->isOnline(),
            /*
             * Viewing reliability. Shown to the person themselves, to an admin,
             * and to an agent looking at a buyer who has requested a viewing —
             * but never published on a buyer's public identity.
             */
            'reliability'        => $this->when(
                $ownerOrAdmin || $viewer?->role_type === 'agent',
                fn () => $this->viewingReliability(),
            ),
            // Agent's listings suspended by an admin (AgentSanctionService): owner and admins.
            'listing_suspended_until' => $this->when(
                $ownerOrAdmin && $this->role_type === 'agent',
                fn () => $this->listingSuspendedUntil()?->toISOString(),
            ),
            'booking_locked_until' => $this->when(
                $ownerOrAdmin,
                fn () => $this->bookingLockedUntil()?->toISOString(),
            ),
            // Their own strike count, so they can see where they stand before it matters.
            'viewing_strikes'    => $this->when(
                $ownerOrAdmin,
                fn () => ['count' => $this->recentStrikes(), 'limit' => User::STRIKE_LIMIT, 'window_days' => User::STRIKE_WINDOW_DAYS],
            ),
            'created_at'         => $this->created_at?->toISOString(),
            /*
             * A rejected application disappears from the applicant's own view
             * once its 12h cooldown expires — their settings and sidebar go
             * back to "Become an Agent" instead of carrying a rejection
             * indefinitely. The row itself stays for the admin's records; this
             * only controls what the applicant sees.
             */
            'agent_profile'      => $this->whenLoaded(
                'agentProfile',
                fn () => $this->agentProfile?->isSpentRejection()
                    ? null
                    : AgentProfileResource::make($this->agentProfile),
            ),
        ];
    }
}
