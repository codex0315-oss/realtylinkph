<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emailed to an agent when a buyer messages them while they're offline.
 * Throttled by ConversationService to one per thread per 30 minutes — the
 * in-app bell still shows every message. Queued so mail can never slow a send.
 */
class MessageWhileAway extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Conversation $conversation,
        public Message $message,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $c        = $this->conversation->loadMissing(['property', 'buyer']);
        $frontend = config('app.frontend_url');
        $preview  = mb_substr($this->message->body, 0, 300);

        return (new MailMessage())
            ->subject('New message from ' . $c->buyer->name . ' — ' . $c->property->title)
            ->greeting('Hi ' . $notifiable->name . ',')
            ->line($c->buyer->name . ' sent you a message about **' . $c->property->title . '** while you were away:')
            ->line('> ' . $preview)
            ->line('RealtyLink AI has sent them a short reply with the listing details to keep them warm until you\'re back — it won\'t negotiate or make commitments for you.')
            ->action('Open the conversation', $frontend . '/dashboard/messages')
            ->line('You\'ll get at most one of these emails per conversation every 30 minutes.');
    }
}
