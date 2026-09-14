<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AgentProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isAdmin        = $request->user()?->isAdmin() ?? false;
        $isOwnerOrAdmin = $isAdmin || $request->user()?->id === $this->user_id;

        return [
            'id'                 => $this->id,
            'user_id'            => $this->user_id,
            'applicant_type'     => $this->applicant_type,
            'prc_number'         => $this->prc_number,
            'supervising_broker' => $this->supervising_broker,
            'status'             => $this->status,
            'admin_note'         => $this->when($isOwnerOrAdmin, $this->admin_note),
            // RealtyLink AI advisory note — visible to the applicant and admin.
            'ai_comment'         => $this->when($isOwnerOrAdmin, $this->ai_comment),
            'ai_assessed_at'     => $this->ai_assessed_at?->toISOString(),
            'reviewed_at'        => $this->reviewed_at?->toISOString(),
            // When a rejected applicant may re-apply (12h after rejection); null otherwise.
            'reapply_at'         => $this->reapplyAt()?->toISOString(),
            // Document images — admin only (privacy).
            'license_doc'        => $this->when($isAdmin, $this->license_doc ? asset('storage/' . $this->license_doc) : null),
            'accreditation_doc'  => $this->when($isAdmin, $this->accreditation_doc ? asset('storage/' . $this->accreditation_doc) : null),
            'valid_id'           => $this->when($isAdmin, $this->valid_id ? asset('storage/' . $this->valid_id) : null),
            'face_image'         => $this->when($isAdmin, $this->face_image ? asset('storage/' . $this->face_image) : null),
            'has_gcal'           => $this->google_access_token !== null,
            'created_at'         => $this->created_at?->toISOString(),
            'user'               => UserResource::make($this->whenLoaded('user')),
        ];
    }
}
