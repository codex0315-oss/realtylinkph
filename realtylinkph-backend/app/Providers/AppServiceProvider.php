<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\AgentReview;
use App\Policies\ReviewPolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Mailer\Bridge\Brevo\Transport\BrevoTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Model::preventLazyLoading(! app()->isProduction());
        Model::shouldBeStrict(! app()->isProduction());

        // Laravel finds policies by model name (AgentReview → AgentReviewPolicy).
        // This one is called ReviewPolicy, so without an explicit mapping every
        // review authorisation silently failed with 403 — buyers couldn't
        // submit reviews and admins couldn't hide them. Caught by the
        // workflow tests.
        Gate::policy(AgentReview::class, ReviewPolicy::class);

        // Laravel has no built-in Brevo driver; Symfony Mailer does. This is
        // the HTTPS (`brevo+api`) variant, not `brevo+smtp` — the whole point
        // is to avoid SMTP ports, which Render's free tier blocks outbound.
        Mail::extend('brevo', function (): \Symfony\Component\Mailer\Transport\TransportInterface {
            return (new BrevoTransportFactory())->create(
                new Dsn('brevo+api', 'default', (string) config('services.brevo.key')),
            );
        });
    }
}
