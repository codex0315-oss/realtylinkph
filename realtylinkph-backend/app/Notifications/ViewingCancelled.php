<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Appointment;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emailed to both parties when a viewing is cancelled. The copy adapts to whether
 * the recipient is the person who cancelled or the other party.
 */
class ViewingCancelled extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Appointment $appointment,
        public User $canceller,
    ) {}

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
        $isCanceller = $notifiable->getKey() === $this->canceller->getKey();

        $mail = (new MailMessage())
            ->subject('Viewing cancelled — ' . $a->property->title)
            ->greeting('Hi ' . $notifiable->name . ',');

        if ($isCanceller) {
            $mail->line('You cancelled your viewing for **' . $a->property->title . '**.');
        } else {
            $mail->line($this->canceller->name . ' cancelled the viewing for **' . $a->property->title . '**.');
        }

        return $mail
            ->line('**Was scheduled for:** ' . $when)
            ->action('View your appointments', $frontend . '/dashboard/appointments')
            ->line('You can book a new viewing anytime.');
    }
}
