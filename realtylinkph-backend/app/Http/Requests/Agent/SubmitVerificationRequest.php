<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubmitVerificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $profileId = $this->user()?->agentProfile?->id;

        $rules = [
            'applicant_type' => ['required', 'in:salesperson,broker'],
            'full_name'      => ['required', 'string', 'max:255'],
            'mobile'         => ['nullable', 'string', 'max:20'],
            'prc_number'     => ['required', 'string', 'max:50', Rule::unique('agent_profiles', 'prc_number')->ignore($profileId)],
            // Live selfie (webcam capture) — required for both paths.
            'face_image'     => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ];

        // WebP joins the list: listing photos already accept it, and some phones
        // save camera shots that way.
        if ($this->input('applicant_type') === 'broker') {
            // Broker license card.
            $rules['license_doc'] = ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:5120'];
        } else {
            // Salesperson: accreditation (front) + valid ID, optional supervising broker.
            $rules['accreditation_doc']  = ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:5120'];
            $rules['valid_id']           = ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:5120'];
            $rules['supervising_broker'] = ['nullable', 'string', 'max:255'];
        }

        return $rules;
    }

    /**
     * Name the document and the limit. The defaults ("The accreditation doc
     * field must be a file of type: pdf, jpg…", "…must not be greater than
     * 5120 kilobytes") read like a stack trace to an applicant.
     */
    public function messages(): array
    {
        $doc = 'Please upload a PDF, JPG, PNG or WebP under 5 MB.';

        return [
            'prc_number.unique'         => 'That PRC / accreditation number is already registered to another agent.',
            'face_image.required'       => 'Please complete the live face scan.',
            'face_image.uploaded'       => 'The face scan could not be uploaded. Please retake it.',
            'face_image.max'            => 'The face scan is too large. Please retake it.',
            'license_doc.required'      => 'Please upload your PRC broker license card.',
            'license_doc.mimes'         => "License card: $doc",
            'license_doc.max'           => 'License card is over 5 MB. Please use a smaller photo or a PDF.',
            'license_doc.uploaded'      => 'License card could not be uploaded — it may be over 5 MB.',
            'accreditation_doc.required'=> 'Please upload your accreditation document.',
            'accreditation_doc.mimes'   => "Accreditation document: $doc",
            'accreditation_doc.max'     => 'Accreditation document is over 5 MB. Please use a smaller photo or a PDF.',
            'accreditation_doc.uploaded'=> 'Accreditation document could not be uploaded — it may be over 5 MB.',
            'valid_id.required'         => 'Please upload a valid government-issued ID.',
            'valid_id.mimes'            => "Valid ID: $doc",
            'valid_id.max'              => 'Valid ID is over 5 MB. Please use a smaller photo or a PDF.',
            'valid_id.uploaded'         => 'Valid ID could not be uploaded — it may be over 5 MB.',
        ];
    }
}
