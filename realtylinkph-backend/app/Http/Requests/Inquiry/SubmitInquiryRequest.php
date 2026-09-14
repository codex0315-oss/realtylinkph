<?php

declare(strict_types=1);

namespace App\Http\Requests\Inquiry;

use Illuminate\Foundation\Http\FormRequest;

class SubmitInquiryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'    => ['required', 'string', 'max:255'],
            'email'   => ['required', 'email', 'max:255'],
            'phone'   => ['nullable', 'string', 'max:20'],
            'message' => ['required', 'string', 'max:2000'],
        ];
    }
}
