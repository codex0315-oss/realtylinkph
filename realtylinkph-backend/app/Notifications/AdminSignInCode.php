<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The 6-digit code an admin types after their password. Sent immediately, not
 * queued: the person is sitting at the sign-in form waiting for it.
 */
class AdminSignInCode extends Notification
{
    public function __construct(public string $code, public int $minutes)
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
            ->subject('Your RealtyLink PH admin sign-in code')
            ->greeting('Admin sign-in')
            ->line('Enter this code to finish signing in:')
            ->line('**' . $this->code . '**')
            ->line("It expires in {$this->minutes} minutes and works once.")
            ->line('If you did not just try to sign in, change your password now. Someone has your password.');
    }
}
