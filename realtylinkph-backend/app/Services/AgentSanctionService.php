<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AgentSanction;
use App\Models\Property;
use App\Models\User;
use App\Notifications\ViewingSanctionNotice;
use App\Support\AdminAudit;
use Illuminate\Support\Facades\DB;

/**
 * What an administrator can do about an agent who games the platform, for
 * example by reviewing themselves from fake buyer accounts. In steps:
 *
 *   warning     — a recorded notice, nothing else changes
 *   suspension  — listings taken down (with the reason shown on each) and no
 *                 publishing for 7, 14 or 30 days; lifted early by an admin
 *   revocation  — verification removed: back to a buyer account, listings
 *                 taken down, and a longer wait before applying again
 *
 * The agent is told every time, in the app and by email, and every step goes
 * into the Activity log.
 */
class AgentSanctionService
{
    public function __construct(private readonly NotificationService $notifications)
    {
    }

    public function warn(User $agent, string $reason, User $admin): AgentSanction
    {
        $s = $this->record($agent, AgentSanction::WARNING, $reason, $admin);
        AdminAudit::log('agent.warned', $agent, $this->label($agent), ['reason' => $reason], $admin);
        $this->tell($agent, 'agent_warning',
            'A warning on your agent account',
            'Your agent account has received a warning',
            ['An administrator reviewed activity on your account and issued a warning. Further issues may lead to your listings being suspended or your verification being removed.'],
            $reason);

        return $s;
    }

    public function suspend(User $agent, int $days, string $reason, User $admin): AgentSanction
    {
        abort_unless(in_array($days, AgentSanction::SUSPENSION_DAYS, true), 422, 'Choose a suspension of 7, 14 or 30 days.');
        $until = now()->addDays($days);

        $s = DB::transaction(function () use ($agent, $days, $reason, $admin, $until) {
            $s = $this->record($agent, AgentSanction::SUSPENSION, $reason, $admin, $until);
            $this->takeDownListings($agent, "Listings suspended until {$until->setTimezone('Asia/Manila')->format('M j, Y')}: {$reason}");

            return $s;
        });

        AdminAudit::log('agent.suspended', $agent, $this->label($agent), ['reason' => $reason, 'days' => $days], $admin);
        $this->tell($agent, 'agent_suspended',
            'Your listings are suspended',
            "Your listings are suspended for {$days} days",
            ["Your published listings have been taken down and you can't publish listings until {$until->setTimezone('Asia/Manila')->format('F j, Y')}. You can still manage your viewings and messages. After that date you can publish your listings again."],
            $reason, 'Open My Listings', '/dashboard/listings');

        return $s;
    }

    public function lift(User $agent, string $reason, User $admin): void
    {
        $active = AgentSanction::where('user_id', $agent->id)->activeSuspension()->get();
        abort_if($active->isEmpty(), 422, 'This agent has no suspension to lift.');

        foreach ($active as $s) {
            $s->update(['lifted_at' => now()]);
        }
        $this->record($agent, AgentSanction::LIFT, $reason, $admin);
        AdminAudit::log('agent.suspension_lifted', $agent, $this->label($agent), ['reason' => $reason], $admin);
        $this->tell($agent, 'agent_suspension_lifted',
            'Your listing suspension was lifted',
            'You can publish listings again',
            ['An administrator lifted the suspension on your account. Open My Listings to publish your listings again.'],
            $reason, 'Open My Listings', '/dashboard/listings');
    }

    public function revoke(User $agent, string $reason, User $admin): AgentSanction
    {
        $profile = $agent->agentProfile;
        abort_unless($agent->isAgent() && $profile, 422, 'Only verified agents can have their verification revoked.');

        $s = DB::transaction(function () use ($agent, $profile, $reason, $admin) {
            $s = $this->record($agent, AgentSanction::REVOCATION, $reason, $admin);
            $this->takeDownListings($agent, "Agent verification revoked: {$reason}");
            $profile->update(['status' => 'rejected', 'admin_note' => "Verification revoked: {$reason}", 'reviewed_at' => now()]);
            $profile->forceFill(['revoked_at' => now()])->save();
            $agent->update(['role_type' => 'buyer']);

            return $s;
        });

        AdminAudit::log('agent.revoked', $agent, $this->label($agent), ['reason' => $reason], $admin);
        $wait = \App\Models\AgentProfile::REVOKE_COOLDOWN_DAYS;
        $this->tell($agent, 'agent_verification_revoked',
            'Your agent verification was removed',
            'Your agent verification was removed',
            ["Your account is now a buyer account and your listings have been taken down. You can apply to become a verified agent again after {$wait} days."],
            $reason, 'View your profile', '/dashboard/profile');

        return $s;
    }

    private function record(User $agent, string $type, string $reason, User $admin, $until = null): AgentSanction
    {
        return AgentSanction::create([
            'user_id' => $agent->id, 'admin_id' => $admin->id, 'admin_name' => $admin->name,
            'type' => $type, 'reason' => $reason, 'until' => $until,
        ]);
    }

    /** Same as an admin take-down: back to draft, with the reason the agent sees on each card. */
    private function takeDownListings(User $agent, string $reason): void
    {
        Property::where('agent_id', $agent->id)->where('status', 'published')
            ->update(['status' => 'draft', 'unpublish_reason' => mb_substr($reason, 0, 1000), 'unpublished_at' => now()]);
    }

    private function tell(User $agent, string $type, string $subject, string $headline, array $lines, string $reason, string $label = 'Open your dashboard', string $path = '/dashboard'): void
    {
        $this->notifications->send($agent, $type, ['message' => "{$headline}. Reason: {$reason}", 'reason' => $reason]);
        $agent->notify(new ViewingSanctionNotice($subject, $headline, $lines, $reason, $label, $path));
    }

    private function label(User $agent): string
    {
        return "{$agent->name} ({$agent->email})";
    }
}
