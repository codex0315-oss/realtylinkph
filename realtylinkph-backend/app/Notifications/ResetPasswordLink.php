<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Config;

/**
 * Password reset email.
 *
 * Replaces Laravel's built-in ResetPassword notification, which builds its link
 * from a named `password.reset` route. This is an API-only backend with no such
 * route, so the default threw RouteNotFoundException and the endpoint 500'd.
 *
 * The link points at the Nuxt app instead, which posts the token back to
 * /api/reset-password. Queued so a slow mailer never blocks the request.
 */
class ResetPasswordLink extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $token) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim((string) Config::get('app.frontend_url'), '/')
            . '/reset-password?token=' . $this->token
            . '&email=' . urlencode($notifiable->getEmailForPasswordReset());

        // Matches the broker's `expire` setting in config/auth.php.
        $minutes = (int) Config::get('auth.passwords.users.expire', 60);

        return (new MailMessage())
            ->subject('Reset your RealtyLink PH password')
            ->greeting('Hi ' . ($notifiable->name ?? 'there') . ',')
            ->line('We received a request to reset the password for your RealtyLink PH account.')
            ->action('Choose a new password', $url)
            ->line("This link expires in {$minutes} minutes and can only be used once.")
            ->line('If you did not request a password reset, you can safely ignore this email — nothing has changed.')
            ->salutation('— The RealtyLink PH team');
    }
}
