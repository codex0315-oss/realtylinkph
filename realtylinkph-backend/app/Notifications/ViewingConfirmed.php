<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Appointment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emailed to the buyer when the agent confirms their viewing — the one moment
 * in the booking flow a buyer most wants a message they can keep. Queued so a
 * slow mailer never delays the confirmation itself.
 */
class ViewingConfirmed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Appointment $appointment) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $a        = $this->appointment->loadMissing(['property', 'agent']);
        $when     = $a->preferred_datetime->setTimezone('Asia/Manila')->format('l, M j, Y \a\t g:i A');
        $frontend = config('app.frontend_url');

        $mail = (new MailMessage())
            ->subject('Viewing confirmed — ' . $a->property->title)
            ->greeting('Hi ' . $notifiable->name . ',')
            ->line($a->agent->name . ' confirmed your viewing.')
            ->line('**Property:** ' . $a->property->title)
            ->line('**Address:** ' . $a->property->address)
            ->line('**When:** ' . $when);

        if ($a->agent->phone) {
            $mail->line('**Agent contact:** ' . $a->agent->phone);
        }

        return $mail
            ->action('View your appointments', $frontend . '/dashboard/appointments')
            ->line('Need to change plans? Cancel from your appointments at least ' . Appointment::LATE_WINDOW_HOURS . ' hours before the slot so it doesn\'t count against your reliability.');
    }
}
