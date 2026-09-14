<?php

declare(strict_types=1);

namespace App\Http\Requests\Review;

use Illuminate\Foundation\Http\FormRequest;

class SubmitReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'appointment_id' => ['required', 'integer', 'exists:appointments,id'],
            'rating'         => ['required', 'integer', 'min:1', 'max:5'],
            'review_text'    => ['nullable', 'string', 'max:2000'],
        ];
    }
}
