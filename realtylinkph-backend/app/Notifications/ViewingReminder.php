<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Appointment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Day-before reminder for a confirmed viewing, sent to both parties by the
 * hourly `appointments:send-reminders` command. Copy adapts to the recipient.
 */
class ViewingReminder extends Notification implements ShouldQueue
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
        $other    = $isAgent ? $a->buyer : $a->agent;

        $mail = (new MailMessage())
            ->subject('Reminder: viewing tomorrow — ' . $a->property->title)
            ->greeting('Hi ' . $notifiable->name . ',')
            ->line(($isAgent ? 'You have a viewing with ' : 'Your viewing with ') . $other->name . ' is coming up.')
            ->line('**Property:** ' . $a->property->title)
            ->line('**Address:** ' . $a->property->address)
            ->line('**When:** ' . $when);

        if ($other->phone) {
            $mail->line('**' . ($isAgent ? 'Buyer' : 'Agent') . ' contact:** ' . $other->phone);
        }

        return $mail->action('View your appointments', $frontend . '/dashboard/appointments');
    }
}
