<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Appointment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emailed when a buyer schedules a viewing. The same class serves both parties —
 * the copy adapts based on whether the recipient is the agent or the buyer.
 * Queued so a slow/failing mailer never blocks the booking.
 */
class ViewingRequested extends Notification implements ShouldQueue
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
        $a        = $this->appointment->loadMissing(['property', 'buyer', 'agent']);
        $when     = $a->preferred_datetime->setTimezone('Asia/Manila')->format('l, M j, Y \a\t g:i A');
        $frontend = config('app.frontend_url');
        $isAgent  = $notifiable->getKey() === $a->agent_id;

        $mail = (new MailMessage())->greeting('Hi ' . $notifiable->name . ',');

        if ($isAgent) {
            $mail->subject('New viewing request — ' . $a->property->title)
                ->line($a->buyer->name . ' requested a viewing for one of your listings.');
        } else {
            $mail->subject('Viewing request sent — ' . $a->property->title)
                ->line('Your viewing request has been sent to ' . $a->agent->name . '.');
        }

        $mail->line('**Property:** ' . $a->property->title)
            ->line('**When:** ' . $when);

        if ($a->notes) {
            $mail->line('**Notes:** ' . $a->notes);
        }

        if ($isAgent) {
            $mail->action('Review in your dashboard', $frontend . '/dashboard/appointments')
                ->line('You can confirm or decline it from your appointments.');
        } else {
            $mail->line('The viewing is **pending** — you\'ll be notified once the agent confirms.')
                ->action('View your appointments', $frontend . '/dashboard/appointments');
        }

        return $mail;
    }
}
