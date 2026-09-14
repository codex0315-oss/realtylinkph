<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emailed to an applicant when an admin approves their agent application.
 * Queued so a slow/failing mailer never blocks the admin's review action.
 */
class AgentApplicationApproved extends Notification implements ShouldQueue
{
    use Queueable;

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontend = config('app.frontend_url');

        return (new MailMessage())
            ->subject('You\'re now a verified agent on RealtyLinkPH 🎉')
            ->greeting('Congratulations, ' . $notifiable->name . '!')
            ->line('Your agent application has been **approved**. You\'re now a verified agent on RealtyLinkPH.')
            ->line('You can start posting property listings, receive buyer inquiries, and manage viewing appointments right away.')
            ->action('Go to your agent dashboard', $frontend . '/dashboard/listings')
            ->line('Welcome aboard — we\'re glad to have you!');
    }
}
