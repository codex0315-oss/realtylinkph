<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
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
            'avatar'             => $this->avatar
                ? (str_starts_with($this->avatar, 'http') ? $this->avatar : asset('storage/' . $this->avatar))
                : null,
            'role_type'          => $this->role_type,
            'theme'              => $this->theme ?? 'light',
            'property_alerts'    => (bool) $this->property_alerts,
            'has_gcal'           => $this->google_access_token !== null,
            'email_verified_at'  => $this->email_verified_at?->toISOString(),
            'last_seen_at'       => $this->last_seen_at?->toISOString(),
            'is_online'          => $this->isOnline(),
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
