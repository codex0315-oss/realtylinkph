<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use App\Support\Uploads;
use Illuminate\Http\Resources\Json\JsonResource;

class AgentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'name'           => $this->name,
            'email'          => $this->email,
            'avatar'         => Uploads::url($this->avatar),
            'role_type'      => $this->role_type,
            'agent_profile'  => AgentProfileResource::make($this->whenLoaded('agentProfile')),
            'average_rating' => $this->average_rating !== null ? round((float) $this->average_rating, 1) : null,
            'review_count'   => (int) ($this->review_count ?? 0),
            'listing_count'  => (int) ($this->listing_count ?? 0),
            // Public: an agent is a business, so how reliably they honour
            // confirmed viewings is fair for a buyer to see. Null below the
            // minimum sample, so a new agent isn't branded on one data point.
            'reliability'    => $this->viewingReliability(),
        ];
    }
}
