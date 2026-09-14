<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emails a 6-digit email-verification code. Queued so a slow/failing mailer
 * never blocks the request.
 */
class EmailVerificationCode extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $code)
    {
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('Your RealtyLinkPH verification code')
            ->greeting('Verify your email')
            ->line('Enter this code in your profile to verify your email address:')
            ->line('**' . $this->code . '**')
            ->line('This code expires in 15 minutes.')
            ->line('If you did not request this, you can safely ignore this email.');
    }
}
