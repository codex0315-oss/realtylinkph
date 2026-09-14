<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emailed to admins when an agent submits a verification application awaiting review.
 */
class NewAgentApplication extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public User $applicant,
        public string $applicantType,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontend = config('app.frontend_url');

        return (new MailMessage())
            ->subject('New agent verification to review — ' . $this->applicant->name)
            ->greeting('Hi ' . $notifiable->name . ',')
            ->line($this->applicant->name . ' submitted a ' . $this->applicantType . ' verification application.')
            ->line('It is now pending your review.')
            ->action('Review applications', $frontend . '/admin/agents')
            ->line('Please approve or decline it from the admin dashboard.');
    }
}
