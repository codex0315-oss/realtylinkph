<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Appointment;
use App\Services\AppointmentService;
use Illuminate\Console\Command;

/**
 * Closes viewings nobody closed. A confirmed viewing whose slot passed more
 * than GRACE_HOURS ago is marked completed, which moves it to both parties'
 * History and unlocks the buyer's review. Without this, a viewing the agent
 * forgot to mark stayed "confirmed" forever and the buyer never got the
 * review prompt. The grace period leaves room for the agent to cancel a
 * no-show instead, which is the honest record for those.
 *
 * Pending (never-confirmed) requests are cancelled once the slot is a day
 * old — the agent didn't act, and leaving them "pending" blocks the buyer
 * from re-booking that property.
 */
class CompletePastAppointments extends Command
{
    public const GRACE_HOURS = 24;

    protected $signature = 'appointments:complete-past {--dry-run : Report without changing anything}';

    protected $description = 'Mark confirmed viewings older than a day as completed and expire unanswered requests';

    public function handle(AppointmentService $service): int
    {
        $cutoff = now()->subHours(self::GRACE_HOURS);
        $dry    = (bool) $this->option('dry-run');

        $completed = 0;
        Appointment::where('status', 'confirmed')
            ->where('preferred_datetime', '<', $cutoff)
            ->orderBy('preferred_datetime')
            ->lazy()
            ->each(function (Appointment $a) use ($service, $dry, &$completed): void {
                $this->line("#{$a->id} confirmed on {$a->preferred_datetime->toDayDateTimeString()} → completed");
                if (! $dry) {
                    $service->complete($a, auto: true);
                }
                $completed++;
            });

        $expired = 0;
        Appointment::where('status', 'pending')
            ->where('preferred_datetime', '<', $cutoff)
            ->orderBy('preferred_datetime')
            ->lazy()
            ->each(function (Appointment $a) use ($service, $dry, &$expired): void {
                $this->line("#{$a->id} still pending for {$a->preferred_datetime->toDayDateTimeString()} → cancelled (unanswered)");
                if (! $dry) {
                    $service->cancel($a, 'expired');
                }
                $expired++;
            });

        $this->info(sprintf('%s: %d completed, %d expired', $dry ? 'Dry run' : 'Done', $completed, $expired));

        return self::SUCCESS;
    }
}
