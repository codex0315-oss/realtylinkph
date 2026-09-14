<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent;

use Illuminate\Foundation\Http\FormRequest;

class ReviewApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return [
            'action' => ['required', 'in:approve,reject'],
            'reason' => ['required_if:action,reject', 'nullable', 'string', 'max:1000'],
        ];
    }
}
