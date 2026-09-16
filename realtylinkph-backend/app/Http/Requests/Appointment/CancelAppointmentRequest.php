<?php

declare(strict_types=1);

namespace App\Http\Requests\Appointment;

use App\Models\Appointment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A reason is mandatory. The controller used to accept `reason` and discard it,
 * so a confirmed viewing could be dropped with no explanation and no record.
 */
class CancelAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /** @var Appointment $appointment */
        $appointment = $this->route('appointment');
        $isAgent     = $this->user()?->id === $appointment?->agent_id;

        return [
            'reason_code' => ['required', 'string', Rule::in(array_keys(Appointment::reasonsFor($isAgent)))],
            // Free text is optional in general, but required for "Other" —
            // otherwise "Other" becomes a way to say nothing at all.
            'reason_note' => [
                Rule::requiredIf(fn () => $this->input('reason_code') === 'other'),
                'nullable', 'string', 'max:500',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'reason_code.required' => 'Please choose a reason for cancelling.',
            'reason_code.in'       => 'That is not a valid reason for this cancellation.',
            'reason_note.required' => 'Please describe the reason when choosing "Other".',
            'reason_note.max'      => 'Keep the explanation under 500 characters.',
        ];
    }
}
