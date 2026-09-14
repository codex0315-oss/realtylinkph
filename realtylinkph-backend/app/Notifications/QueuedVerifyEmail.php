<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Queued variant of Laravel's email-verification notification.
 *
 * Sending the verification email on a queue decouples email delivery from the
 * registration request: the account is created and a token is returned
 * immediately, and the email is dispatched in the background. A mail-server
 * failure can no longer break (or orphan) a registration.
 */
class QueuedVerifyEmail extends VerifyEmail implements ShouldQueue
{
    use Queueable;
}
