<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emailed to an applicant when an admin rejects their agent application,
 * including the reason and when they may re-apply (12h cooldown).
 */
class AgentApplicationRejected extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $reason,
        public ?string $reapplyAt = null,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontend = config('app.frontend_url');

        $mail = (new MailMessage())
            ->subject('Update on your RealtyLinkPH agent application')
            ->greeting('Hi ' . $notifiable->name . ',')
            ->line('Thank you for applying to become a verified agent. After review, your application was **not approved** this time.')
            ->line('**Reason:** ' . $this->reason);

        if ($this->reapplyAt) {
            $mail->line('You can submit a new application after **' . $this->reapplyAt . '**.');
        }

        return $mail
            ->action('Review your application', $frontend . '/dashboard/verify')
            ->line('Please make sure your documents are clear and valid, then feel free to re-apply.');
    }
}
