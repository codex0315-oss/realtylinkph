<?php

declare(strict_types=1);

namespace App\Http\Requests\Property;

use Illuminate\Foundation\Http\FormRequest;

class UploadPhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'photo'  => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'is_360' => ['boolean'],
        ];
    }

    /**
     * Default messages here were actively unhelpful. A file over PHP's
     * upload_max_filesize arrives with UPLOAD_ERR_INI_SIZE, which Laravel
     * reports as the bare "The photo failed to upload." — no mention of size,
     * so an agent whose phone photo was rejected has no idea why.
     */
    public function messages(): array
    {
        return [
            'photo.uploaded' => 'That photo is too large to upload. Please use an image under 10 MB.',
            'photo.max'      => 'That photo is too large. Please use an image under 10 MB.',
            'photo.image'    => 'That file is not an image. Please upload a JPG, PNG or WebP.',
            'photo.mimes'    => 'Unsupported image format. Please use JPG, PNG or WebP.',
        ];
    }
}
