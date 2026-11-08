<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Appointment;
use App\Models\User;
use App\Models\ViewingSanction;
use App\Notifications\ViewingSanctionNotice;
use App\Support\AdminAudit;
use Illuminate\Support\Facades\DB;

/**
 * Everything that changes a person's viewing record outside a plain cancel:
 * a reported no-show, and the four things an admin can do about it (warn,
 * pause, lift a pause, waive a strike). Every one of them tells the person
 * — in the app and by email — and the admin ones are written to the audit trail.
 */
class ViewingSanctionService
{
    public function __construct(
        private readonly NotificationService $notifications,
    ) {}

    /**
     * People who are paused or already at three strikes, for the admin Overview.
     * Only people with a strike or a sanction on record are looked at.
     */
    public function needingAttention(): int
    {
        $ids = Appointment::query()->where('late_cancellation', true)->whereNotNull('cancelled_by_id')->pluck('cancelled_by_id')
            ->merge(Appointment::query()->whereNotNull('no_show_user_id')->pluck('no_show_user_id'))
            ->merge(ViewingSanction::query()->pluck('user_id'))
            ->unique();

        return User::query()
            ->whereIn('id', $ids)
            ->whereIn('role_type', ['buyer', 'ghost_buyer', 'agent'])
            ->get()
            ->filter(fn (User $u) => $u->isBookingLocked() || $u->recentStrikes() >= 3)
            ->count();
    }

    // ─── Strikes the system hands out ────────────────────────────────────────

    /**
     * Tell someone a strike was recorded and where they stand. A late
     * cancellation stays quiet until it is a pattern (strike 3 and beyond get an
     * email); a no-show always gets one.
     */
    public function noteStrike(User $user, Appointment $appointment, string $kind, ?User $reporter = null): void
    {
        $appointment->loadMissing('property');
        $title   = $appointment->property->title;
        $strikes = $user->recentStrikes();
        $left    = max(0, User::STRIKE_LIMIT - $strikes);
        $locked  = $user->isBookingLocked();
        $window  = User::STRIKE_WINDOW_DAYS;
        $days    = User::LOCKOUT_DAYS;

        $standing = $locked
            ? "That brings you to {$strikes} in the last {$window} days, so booking viewings is paused for {$days} days."
            : "That is {$strikes} in the last {$window} days. {$left} more and booking is paused for {$days} days.";

        if ($kind === 'late') {
            $message = $locked || $strikes >= 3
                ? "Warning: that viewing was cancelled within " . Appointment::LATE_WINDOW_HOURS . " hours of the slot, so it counts as a strike. {$standing}"
                : "That viewing was cancelled within " . Appointment::LATE_WINDOW_HOURS . " hours of the slot, so it counts toward your reliability ({$strikes} in the last {$window} days). {$left} more and booking is paused for {$days} days.";

            $this->notifications->send($user, 'late_cancellation_recorded', [
                'appointment_id' => $appointment->id,
                'strikes'        => $strikes,
                'message'        => $locked
                    ? "That was your {$strikes}th strike in {$window} days, so booking viewings is paused for {$days} days."
                    : $message,
            ]);

            if ($locked || $strikes >= 3) {
                $user->notify(new ViewingSanctionNotice(
                    'Your viewing record needs attention',
                    $locked ? 'Booking viewings is paused for now' : 'A late cancellation was recorded',
                    [$message],
                ));
            }

            return;
        }

        // A reported no-show.
        $when    = $appointment->preferred_datetime->setTimezone('Asia/Manila')->format('M j, Y \a\t g:i A');
        $by      = $reporter?->name ?? 'The other party';
        $message = "{$by} reported that you did not attend the viewing for {$title} on {$when}. "
            . 'It counts as ' . User::NO_SHOW_WEIGHT . " strikes. {$standing} If this is wrong, contact support and an admin will review it.";

        $this->notifications->send($user, 'no_show_recorded', [
            'appointment_id' => $appointment->id,
            'strikes'        => $strikes,
            'message'        => $message,
        ]);

        $user->notify(new ViewingSanctionNotice(
            'A no-show was reported for your viewing',
            "A no-show was reported for {$title}",
            [$message],
            $appointment->no_show_note,
        ));
    }

    /**
     * The buyer or the agent reports that the other party did not turn up.
     * Caller has checked Appointment::canReportNoShow().
     */
    public function reportNoShow(Appointment $appointment, User $reporter, ?string $note = null): Appointment
    {
        $appointment->loadMissing(['property', 'buyer', 'agent']);

        $offenderId = $reporter->id === $appointment->buyer_id ? $appointment->agent_id : $appointment->buyer_id;
        $offender   = $offenderId === $appointment->agent_id ? $appointment->agent : $appointment->buyer;

        DB::transaction(function () use ($appointment, $reporter, $offender, $note): void {
            $appointment->update([
                'no_show_user_id'        => $offender->id,
                'no_show_reported_by_id' => $reporter->id,
                'no_show_reported_at'    => now(),
                'no_show_note'           => $note !== null && trim($note) !== '' ? trim($note) : null,
            ]);
        });

        $this->noteStrike($offender, $appointment, 'noshow', $reporter);

        $this->notifications->send($reporter, 'no_show_reported', [
            'appointment_id' => $appointment->id,
            'message'        => "Thanks. We recorded that {$offender->name} did not attend the viewing for {$appointment->property->title}, and told them.",
        ]);

        return $appointment->fresh(['property', 'buyer', 'agent']);
    }

    // ─── What an admin can do ────────────────────────────────────────────────

    public function warn(User $user, User $admin, string $message): ViewingSanction
    {
        $row = ViewingSanction::create([
            'user_id'  => $user->id,
            'admin_id' => $admin->id,
            'type'     => ViewingSanction::WARNING,
            'reason'   => $message,
        ]);

        $this->notifications->send($user, 'viewing_warning', [
            'message' => 'An admin sent you a warning about your viewings: ' . $message,
            'reason'  => $message,
        ]);

        $user->notify(new ViewingSanctionNotice(
            'A warning about your viewings',
            'An admin sent you a warning',
            ['Please keep your viewing commitments. Repeated late cancellations and no-shows can pause booking.'],
            $message,
        ));

        AdminAudit::log('viewing.warned', $user, $user->name, ['reason' => $message], $admin);

        return $row;
    }

    public function pause(User $user, User $admin, int $days, string $reason): ViewingSanction
    {
        $until = now()->addDays($days);

        $row = ViewingSanction::create([
            'user_id'  => $user->id,
            'admin_id' => $admin->id,
            'type'     => ViewingSanction::PAUSE,
            'reason'   => $reason,
            'days'     => $days,
            'until'    => $until,
        ]);

        $untilText = $until->copy()->setTimezone('Asia/Manila')->format('M j, Y g:i A');
        $effect    = $user->role_type === 'agent'
            ? 'Your listings will not take new viewing requests and you cannot confirm new ones until then.'
            : 'You cannot book new viewings until then. Viewings already confirmed are not affected.';

        $this->notifications->send($user, 'viewing_paused', [
            'until'   => $until->toISOString(),
            'days'    => $days,
            'reason'  => $reason,
            'message' => "An admin paused your viewing bookings for {$days} days (until {$untilText}). {$effect}",
        ]);

        $user->notify(new ViewingSanctionNotice(
            'Your viewing bookings are paused',
            "Paused for {$days} days, until {$untilText}",
            [$effect],
            $reason,
        ));

        AdminAudit::log('viewing.paused', $user, $user->name, ['days' => $days, 'until' => $until->toISOString(), 'reason' => $reason], $admin);

        return $row;
    }

    /** Lift any pause early, and restart the automatic lock's clock. */
    public function lift(User $user, User $admin, ?string $reason = null): ViewingSanction
    {
        ViewingSanction::where('user_id', $user->id)
            ->where('type', ViewingSanction::PAUSE)
            ->whereNull('lifted_at')
            ->update(['lifted_at' => now(), 'lifted_by_id' => $admin->id]);

        $row = ViewingSanction::create([
            'user_id'  => $user->id,
            'admin_id' => $admin->id,
            'type'     => ViewingSanction::LIFT,
            'reason'   => $reason !== null && trim($reason) !== '' ? trim($reason) : 'Pause lifted by an admin.',
        ]);

        $this->notifications->send($user, 'viewing_pause_lifted', [
            'reason'  => $reason,
            'message' => 'An admin lifted the pause on your viewings. You can book and confirm viewings again.',
        ]);

        $user->notify(new ViewingSanctionNotice(
            'Your viewing bookings are back on',
            'The pause on your viewings was lifted',
            ['You can book and confirm viewings again. Please keep to the times you confirm.'],
            $reason,
        ));

        AdminAudit::log('viewing.lifted', $user, $user->name, ['reason' => $reason], $admin);

        return $row;
    }

    /** Forgive one strike (a late cancellation or a reported no-show). */
    public function waive(Appointment $appointment, User $admin, string $reason): ViewingSanction
    {
        $appointment->loadMissing('property');
        $user = User::findOrFail($appointment->strikeUserId());

        $appointment->update(['strike_waived_at' => now(), 'strike_waived_by_id' => $admin->id]);

        $row = ViewingSanction::create([
            'user_id'        => $user->id,
            'admin_id'       => $admin->id,
            'appointment_id' => $appointment->id,
            'type'           => ViewingSanction::WAIVE,
            'reason'         => $reason,
        ]);

        $title = $appointment->property->title;

        $this->notifications->send($user, 'strike_waived', [
            'appointment_id' => $appointment->id,
            'reason'         => $reason,
            'message'        => "An admin removed a strike from your record for the viewing at {$title}.",
        ]);

        $user->notify(new ViewingSanctionNotice(
            'A strike was removed from your record',
            "A strike for {$title} was removed",
            ['It no longer counts toward your reliability or the booking limit.'],
            $reason,
        ));

        AdminAudit::log('viewing.strike_waived', $user, $user->name, ['appointment_id' => $appointment->id, 'property' => $title, 'reason' => $reason], $admin);

        return $row;
    }
}
