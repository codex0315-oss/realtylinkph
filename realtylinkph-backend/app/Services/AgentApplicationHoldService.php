<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AgentProfile;
use App\Models\User;
use App\Notifications\AgentApplicationHoldNotice;
use App\Support\AdminAudit;
use Illuminate\Support\Collection;

/**
 * Put agent applications on hold — and take them off — with a notice to each
 * applicant (in-app + email).
 *
 * A hold is a pause, not a decision: the application stays pending and keeps
 * its place, and the admin can still approve or reject it at any time (which
 * clears the hold). Typical cause: PRC's license lookup is down, so licenses
 * can't be confirmed. It works on one application or many at once, and skips
 * any that don't apply, so nobody gets the same notice twice.
 */
class AgentApplicationHoldService
{
    public const REASONS = ['prc_unavailable', 'documents', 'other'];

    public const DEFAULT_HOLD_MESSAGES = [
        'prc_unavailable' => "PRC's license verification website is currently unavailable, so we can't confirm licenses right now. Your application is safe and still in line, and there's nothing you need to do. We'll continue your review as soon as PRC is back online.",
        'documents'       => 'We need a closer look at your documents before we can continue your review. Your application is safe and still in line. We\'ll contact you if we need anything from you.',
        'other'           => 'Your application review is paused for a short while. Your application is safe and still in line, and there\'s nothing you need to do.',
    ];

    public const DEFAULT_RESUME_MESSAGE = "Good news: we're reviewing your agent application again. We'll let you know as soon as a decision is made.";

    public function __construct(private readonly NotificationService $notifications)
    {
    }

    /**
     * @param  Collection<int, AgentProfile>  $profiles
     * @return array{held: int, skipped: int}
     */
    public function hold(Collection $profiles, string $reason, string $message, User $admin, ?string $note = null): array
    {
        $held = 0;

        foreach ($profiles as $profile) {
            // Only pending ones, and not ones already on hold.
            if ($profile->status !== 'pending' || $profile->held_at !== null) {
                continue;
            }

            $profile->forceFill([
                'hold_reason'  => $reason,
                'hold_message' => $message,
                'hold_note'    => $note,
                'held_by'      => $admin->name,
                'held_at'      => now(),
            ])->save();

            $this->tell($profile, 'agent_application_on_hold', 'held', $message);
            AdminAudit::log('agent.held', $profile, $this->label($profile), ['reason' => $reason, 'message' => $message], $admin);
            $held++;
        }

        return ['held' => $held, 'skipped' => $profiles->count() - $held];
    }

    /**
     * @param  Collection<int, AgentProfile>  $profiles
     * @return array{resumed: int, skipped: int}
     */
    public function resume(Collection $profiles, string $message, User $admin): array
    {
        $resumed = 0;

        foreach ($profiles as $profile) {
            if ($profile->status !== 'pending' || $profile->held_at === null) {
                continue;
            }

            $profile->clearHold();
            $this->tell($profile, 'agent_application_resumed', 'resumed', $message);
            AdminAudit::log('agent.resumed', $profile, $this->label($profile), ['message' => $message], $admin);
            $resumed++;
        }

        return ['resumed' => $resumed, 'skipped' => $profiles->count() - $resumed];
    }

    private function tell(AgentProfile $profile, string $type, string $kind, string $message): void
    {
        $user = $profile->user;
        if (! $user) {
            return;
        }

        $this->notifications->send($user, $type, ['message' => $message]);
        $user->notify(new AgentApplicationHoldNotice($kind, $message));
    }

    private function label(AgentProfile $profile): string
    {
        return "{$profile->user?->name} ({$profile->user?->email})";
    }
}
