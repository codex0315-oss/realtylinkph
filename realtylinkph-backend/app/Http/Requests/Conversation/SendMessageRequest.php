<?php

declare(strict_types=1);

namespace App\Http\Requests\Conversation;

use App\Support\ChatFiles;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** Text, up to 5 photos / videos / files, or both. */
class SendMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'body'    => ['nullable', 'string', 'max:5000', 'required_without:files'],
            'files'   => ['nullable', 'array', 'max:' . ChatFiles::MAX_FILES],
            'files.*' => ['file'],
        ];
    }

    public function messages(): array
    {
        return [
            'body.required_without' => 'Type a message or attach a file.',
            'files.max'             => 'You can send up to ' . ChatFiles::MAX_FILES . ' files at a time.',
            'files.*.file'          => 'One of the files didn’t upload properly. Please try again.',
        ];
    }

    /** Each file's real type and size, and the total. */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $total = 0;
            foreach ((array) $this->file('files', []) as $file) {
                $check = ChatFiles::check($file);
                if (is_string($check)) {
                    $v->errors()->add('files', $check);

                    return;
                }
                $total += (int) $file->getSize();
            }
            if ($total > ChatFiles::MAX_TOTAL_BYTES) {
                $v->errors()->add('files', 'These files are too big together. Send up to 50 MB per message.');
            }
        });
    }
}
