<?php

declare(strict_types=1);

namespace App\Http\Requests\Property;

use Illuminate\Foundation\Http\FormRequest;

class ReorderPhotosRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'photo_ids'   => ['required', 'array', 'min:1'],
            'photo_ids.*' => ['required', 'integer', 'exists:property_photos,id'],
        ];
    }
}
