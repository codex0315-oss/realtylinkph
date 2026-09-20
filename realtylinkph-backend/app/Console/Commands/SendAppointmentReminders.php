<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Appointment;
use App\Services\AppointmentService;
use Illuminate\Console\Command;

/**
 * Day-before reminders. Runs hourly and picks up every confirmed viewing
 * whose slot is within the next LEAD_HOURS and hasn't been reminded yet, so
 * a viewing booked and confirmed at short notice still gets one — on the
 * next run — instead of being skipped because "tomorrow" already passed.
 */
class SendAppointmentReminders extends Command
{
    public const LEAD_HOURS = 24;

    protected $signature = 'appointments:send-reminders {--dry-run : Report without sending}';

    protected $description = 'Send day-before reminders (in-app + email) for confirmed viewings';

    public function handle(AppointmentService $service): int
    {
        $dry  = (bool) $this->option('dry-run');
        $sent = 0;

        Appointment::where('status', 'confirmed')
            ->whereNull('reminded_at')
            ->where('preferred_datetime', '>', now())
            ->where('preferred_datetime', '<=', now()->addHours(self::LEAD_HOURS))
            ->orderBy('preferred_datetime')
            ->lazy()
            ->each(function (Appointment $a) use ($service, $dry, &$sent): void {
                $this->line("#{$a->id} viewing at {$a->preferred_datetime->toDayDateTimeString()} → reminder to buyer #{$a->buyer_id} and agent #{$a->agent_id}");
                if (! $dry) {
                    $service->remind($a);
                }
                $sent++;
            });

        $this->info(sprintf('%s: %d reminder(s)', $dry ? 'Dry run' : 'Done', $sent));

        return self::SUCCESS;
    }
}
