<?php

declare(strict_types=1);

namespace App\Http\Requests\BlockedDate;

use Illuminate\Foundation\Http\FormRequest;

class StoreBlockedDateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'blocked_date'          => ['required_if:is_recurring,false', 'nullable', 'date', 'after_or_equal:today'],
            'start_time'            => ['nullable', 'date_format:H:i', 'required_with:end_time'],
            'end_time'              => ['nullable', 'date_format:H:i', 'after:start_time', 'required_with:start_time'],
            'reason'                => ['nullable', 'string', 'max:500'],
            'is_recurring'          => ['boolean'],
            'recurring_day_of_week' => ['required_if:is_recurring,true', 'nullable', 'integer', 'min:0', 'max:6'],
        ];
    }
}
