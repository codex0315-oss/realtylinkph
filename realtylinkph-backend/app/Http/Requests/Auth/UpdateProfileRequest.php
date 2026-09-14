<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'            => ['sometimes', 'required', 'string', 'max:255'],
            'phone'           => ['nullable', 'string', 'max:20'],
            'avatar'          => ['nullable', 'image', 'max:2048'],
            'theme'           => ['sometimes', 'in:light,dark,system'],
            'property_alerts' => ['sometimes', 'boolean'],
        ];
    }
}
