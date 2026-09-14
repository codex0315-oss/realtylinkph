<?php

declare(strict_types=1);

namespace App\Http\Requests\BlockedDate;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBlockedDateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'blocked_date'          => ['sometimes', 'nullable', 'date', 'after_or_equal:today'],
            'start_time'            => ['nullable', 'date_format:H:i'],
            'end_time'              => ['nullable', 'date_format:H:i', 'after:start_time'],
            'reason'                => ['nullable', 'string', 'max:500'],
            'is_recurring'          => ['sometimes', 'boolean'],
            'recurring_day_of_week' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:6'],
        ];
    }
}
