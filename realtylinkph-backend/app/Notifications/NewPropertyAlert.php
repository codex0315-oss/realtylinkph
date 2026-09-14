<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Property;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emailed to buyers who opted into property alerts whenever a new listing
 * is published. Queued so a slow/failing mailer never blocks publishing.
 */
class NewPropertyAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Property $property)
    {
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontend = config('app.frontend_url');
        $price    = number_format((float) $this->property->price);

        return (new MailMessage())
            ->subject('New property on RealtyLinkPH: ' . $this->property->title)
            ->greeting('A new listing just dropped! 🏡')
            ->line($this->property->title . ' — ₱' . $price)
            ->line($this->property->address ?? '')
            ->action('View Property', $frontend . '/properties/' . $this->property->id)
            ->line('You are receiving this because you enabled Property Alerts. You can turn them off anytime in your profile.');
    }
}
