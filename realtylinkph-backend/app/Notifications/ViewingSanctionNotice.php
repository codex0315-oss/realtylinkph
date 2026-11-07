<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * One email for everything that happens to someone's viewing record: a warning,
 * a pause, a lifted pause, a waived strike, a reported no-show. The in-app
 * notification carries the same words; this makes sure it also reaches the inbox.
 */
class ViewingSanctionNotice extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param array<int, string> $lines     paragraphs, in order
     * @param string|null        $reason    quoted back to the user when there is one
     */
    public function __construct(
        public string $subject,
        public string $headline,
        public array $lines,
        public ?string $reason = null,
        public string $actionLabel = 'View your appointments',
        public string $actionPath = '/dashboard/appointments',
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage())
            ->subject($this->subject)
            ->greeting('Hi ' . $notifiable->name . ',')
            ->line('**' . $this->headline . '**');

        foreach ($this->lines as $line) {
            $mail->line($line);
        }

        if ($this->reason !== null && $this->reason !== '') {
            $mail->line('**Reason:** ' . $this->reason);
        }

        return $mail
            ->action($this->actionLabel, config('app.frontend_url') . $this->actionPath)
            ->line('If you think this is a mistake, reply from Help & support and an admin will look at it.');
    }
}
