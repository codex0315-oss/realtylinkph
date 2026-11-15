<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use App\Support\Documents;
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
            // The fixed checklist and the verdict worked out from it (null on older applications).
            'ai_checks'          => $this->when($isAdmin, $this->ai_checks),
            'ai_verdict'         => $this->when($isAdmin, $this->ai_verdict),
            // True while the queued assessment hasn't landed yet.
            'ai_pending'         => $this->when($isAdmin, $this->status === 'pending' && $this->ai_assessed_at === null),
            // Official places to verify the credential — fixed and checked, never model-generated.
            'verification_links' => $this->when($isAdmin, fn () => \App\Support\AgentCredentialReference::links($this->applicant_type)),
            'reviewed_at'        => $this->reviewed_at?->toISOString(),
            // On hold (e.g. PRC's lookup is down). The applicant sees that it's on
            // hold, why, and the notice they were sent; the admins' own note and
            // who did it stay admin-only.
            'on_hold'            => $this->when($isOwnerOrAdmin, fn () => $this->isOnHold()),
            'hold_reason'        => $this->when($isOwnerOrAdmin, $this->hold_reason),
            'hold_message'       => $this->when($isOwnerOrAdmin, $this->hold_message),
            'held_at'            => $this->when($isOwnerOrAdmin, fn () => $this->held_at?->toISOString()),
            'hold_note'          => $this->when($isAdmin, $this->hold_note),
            'held_by'            => $this->when($isAdmin, $this->held_by),
            // Set by the admin Agents page's history tabs (from the audit trail).
            'reviewed_by'        => $this->when($isAdmin && array_key_exists('reviewed_by', $this->resource->getAttributes()), fn () => $this->resource->getAttributes()['reviewed_by']),
            // When a rejected applicant may re-apply (12h after rejection); null otherwise.
            'reapply_at'         => $this->reapplyAt()?->toISOString(),
            // Document images — admin only, and as 15-minute signed URLs (Documents::url).
            'license_doc'        => $this->when($isAdmin, Documents::url($this->license_doc)),
            'accreditation_doc'  => $this->when($isAdmin, Documents::url($this->accreditation_doc)),
            'valid_id'           => $this->when($isAdmin, Documents::url($this->valid_id)),
            'face_image'         => $this->when($isAdmin, Documents::url($this->face_image)),
            'has_gcal'           => $this->google_access_token !== null,
            'created_at'         => $this->created_at?->toISOString(),
            'user'               => UserResource::make($this->whenLoaded('user')),
        ];
    }
}
