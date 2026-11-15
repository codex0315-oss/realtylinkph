<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emailed when an admin puts an agent application on hold (e.g. PRC's lookup
 * is down) and again when the review resumes. Queued, so a bulk notice to many
 * applicants never holds up the admin's request.
 */
class AgentApplicationHoldNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $kind,      // 'held' | 'resumed'
        public string $message,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $held = $this->kind === 'held';

        return (new MailMessage())
            ->subject($held ? 'Your RealtyLinkPH agent application is on hold' : 'We\'re reviewing your RealtyLinkPH agent application')
            ->greeting('Hi ' . $notifiable->name . ',')
            ->line($this->message)
            ->line($held
                ? 'Your application is safe and keeps its place in line. You don\'t need to apply again.'
                : 'We\'ll email you as soon as a decision is made.')
            ->action('View your application', config('app.frontend_url') . '/dashboard/profile');
    }
}
