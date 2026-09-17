<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use App\Support\Uploads;
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
            // RealtyLink AI's advisory note is for the reviewing admin only. It
            // was briefly shown to applicants too, which invited them to argue
            // with a pre-check that isn't a decision.
            'ai_comment'         => $this->when($isAdmin, $this->ai_comment),
            'ai_assessed_at'     => $this->when($isAdmin, $this->ai_assessed_at?->toISOString()),
            // True while the queued assessment hasn't landed yet.
            'ai_pending'         => $this->when($isAdmin, $this->status === 'pending' && $this->ai_assessed_at === null),
            'reviewed_at'        => $this->reviewed_at?->toISOString(),
            // When a rejected applicant may re-apply (12h after rejection); null otherwise.
            'reapply_at'         => $this->reapplyAt()?->toISOString(),
            // Document images — admin only (privacy).
            'license_doc'        => $this->when($isAdmin, Uploads::url($this->license_doc)),
            'accreditation_doc'  => $this->when($isAdmin, Uploads::url($this->accreditation_doc)),
            'valid_id'           => $this->when($isAdmin, Uploads::url($this->valid_id)),
            'face_image'         => $this->when($isAdmin, Uploads::url($this->face_image)),
            'has_gcal'           => $this->google_access_token !== null,
            'created_at'         => $this->created_at?->toISOString(),
            'user'               => UserResource::make($this->whenLoaded('user')),
        ];
    }
}
