<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

/**
 * Emails are case-insensitive: "Juan@Gmail.com" and "juan@gmail.com" are the
 * same person. Lowercase (and trim) before validation so sign-up, sign-in,
 * password reset and "Continue with Google" all find the same account.
 */
trait NormalizesEmail
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }
}
