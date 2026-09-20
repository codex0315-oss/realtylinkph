<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Appointment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AppointmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'property_id'        => $this->property_id,
            'buyer_id'           => $this->buyer_id,
            'agent_id'           => $this->agent_id,
            'preferred_datetime' => $this->preferred_datetime?->toISOString(),
            'status'             => $this->status,
            'can_review'         => $this->canBeReviewedBy($request->user()),
            'gcal_event_id'      => $this->gcal_event_id,
            'notes'              => $this->notes,
            // Cancellation record — both parties see why, and whether it counted.
            'cancel_reason_code' => $this->cancel_reason_code,
            'cancel_reason'      => Appointment::reasonLabel($this->cancel_reason_code, $this->cancelled_by_id === $this->agent_id),
            'cancel_reason_note' => $this->cancel_reason_note,
            'cancelled_by_id'    => $this->cancelled_by_id,
            'cancelled_at'       => $this->cancelled_at?->toISOString(),
            'late_cancellation'  => (bool) $this->late_cancellation,
            // Would cancelling right now count against me? Drives the warning
            // in the cancel dialog so nobody is surprised after the fact.
            'cancel_is_late'     => $this->wouldBeLateCancellation(),
            'created_at'         => $this->created_at?->toISOString(),
            'property'           => PropertyResource::make($this->whenLoaded('property')),
            'buyer'              => UserResource::make($this->whenLoaded('buyer')),
            'agent'              => UserResource::make($this->whenLoaded('agent')),
        ];
    }
}
