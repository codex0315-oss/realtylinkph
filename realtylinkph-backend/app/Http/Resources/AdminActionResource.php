<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AdminAction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminActionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'admin_id'      => $this->admin_id,
            'admin_name'    => $this->admin_name,
            'action'        => $this->action,
            'label'         => AdminAction::LABELS[$this->action] ?? $this->action,
            'subject_type'  => $this->subject_type,
            'subject_id'    => $this->subject_id,
            'subject_label' => $this->subject_label,
            'details'       => $this->details,
            'ip'            => $this->ip,
            'created_at'    => $this->created_at?->toISOString(),
        ];
    }
}
