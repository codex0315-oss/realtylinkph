<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use App\Support\Uploads;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'name'               => $this->name,
            'email'              => $this->email,
            'phone'              => $this->phone,
            'avatar'             => Uploads::url($this->avatar),
            'role_type'          => $this->role_type,
            'theme'              => $this->theme ?? 'light',
            'property_alerts'    => (bool) $this->property_alerts,
            'has_gcal'           => $this->google_access_token !== null,
            'email_verified_at'  => $this->email_verified_at?->toISOString(),
            'last_seen_at'       => $this->last_seen_at?->toISOString(),
            'is_online'          => $this->isOnline(),
            /*
             * Viewing reliability. Shown to the person themselves, to an admin,
             * and to an agent looking at a buyer who has requested a viewing —
             * but never published on a buyer's public identity.
             */
            'reliability'        => $this->when(
                $request->user('sanctum')?->id === $this->id
                    || $request->user('sanctum')?->isAdmin()
                    || $request->user('sanctum')?->role_type === 'agent',
                fn () => $this->viewingReliability(),
            ),
            'booking_locked_until' => $this->when(
                $request->user('sanctum')?->id === $this->id || $request->user('sanctum')?->isAdmin(),
                fn () => $this->bookingLockedUntil()?->toISOString(),
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
