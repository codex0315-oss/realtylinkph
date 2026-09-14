<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\AgentReview;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emailed to an agent when a buyer leaves them a rating/review.
 */
class ReviewReceived extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public AgentReview $review) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $review   = $this->review;
        $buyer    = $review->buyer?->name ?? 'A buyer';
        $stars    = str_repeat('★', $review->rating) . str_repeat('☆', 5 - $review->rating);
        $property = $review->appointment?->property?->title;
        $frontend = config('app.frontend_url');

        $mail = (new MailMessage())
            ->subject('You received a new ' . $review->rating . '-star review')
            ->greeting('Hi ' . $notifiable->name . ',')
            ->line($buyer . ' left you a new review.')
            ->line('**Rating:** ' . $stars . ' (' . $review->rating . '/5)');

        if ($property) {
            $mail->line('**Viewing:** ' . $property);
        }

        if ($review->review_text) {
            $mail->line('**Their feedback:** “' . $review->review_text . '”');
        }

        return $mail
            ->action('View your reviews', $frontend . '/dashboard/reviews')
            ->line('Great reviews help you stand out to future buyers.');
    }
}
