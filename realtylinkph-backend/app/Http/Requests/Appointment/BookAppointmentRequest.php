<?php

declare(strict_types=1);

namespace App\Http\Requests\Appointment;

use Illuminate\Foundation\Http\FormRequest;

class BookAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'preferred_datetime' => ['required', 'date', 'after:now'],
            'notes'              => ['nullable', 'string', 'max:1000'],
        ];
    }
}
