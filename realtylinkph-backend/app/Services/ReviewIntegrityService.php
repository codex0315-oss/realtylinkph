<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AccountSignal;
use App\Models\AgentReview;
use App\Models\Appointment;
use App\Models\Conversation;
use Illuminate\Support\Carbon;

/**
 * Looks at a new review for signs it was written from a fake buyer account
 * controlled by the agent, and flags it for an administrator.
 *
 * It never hides or deletes anything by itself: every signal here has honest
 * explanations too (people share mobile data, friends really do review each
 * other's agent), so the decision stays with a person.
 */
class ReviewIntegrityService
{
    /** Accounts younger than this are "new". */
    public const NEW_ACCOUNT_DAYS = 14;

    /** This many 4–5 star reviews for one agent within BURST_HOURS is a burst. */
    public const BURST_COUNT = 3;
    public const BURST_HOURS = 48;

    public const LABELS = [
        'shared_device'         => 'Reviewer and agent used the same device and network',
        'shared_network'        => 'Reviewer and agent used the same network',
        'new_single_agent'      => 'New account that has only ever dealt with this agent',
        'burst'                 => 'Several 4–5 star reviews for this agent in a short time',
        'praised_after_cancel'  => '4–5 stars although the agent cancelled the viewing',
    ];

    /** Work out the reasons and save them on the review (null when nothing stands out). */
    public function assess(AgentReview $review): array
    {
        $reasons = $this->reasons($review);

        $review->forceFill([
            'flag_reasons'    => $reasons ?: null,
            'flagged_at'      => $reasons ? now() : null,
            'flag_cleared_at' => null,
            'flag_cleared_by' => null,
        ])->saveQuietly();

        return $reasons;
    }

    /** @return list<string> */
    public function reasons(AgentReview $review): array
    {
        $buyerId = $review->buyer_id;
        $agentId = $review->agent_id;
        $reasons = [];

        $buyerDevices  = AccountSignal::where('user_id', $buyerId)->pluck('device_hash')->unique();
        $buyerNetworks = AccountSignal::where('user_id', $buyerId)->pluck('network_hash')->unique();

        if ($buyerDevices->isNotEmpty() && AccountSignal::where('user_id', $agentId)->whereIn('device_hash', $buyerDevices)->exists()) {
            $reasons[] = 'shared_device';
        } elseif ($buyerNetworks->isNotEmpty() && AccountSignal::where('user_id', $agentId)->whereIn('network_hash', $buyerNetworks)->exists()) {
            $reasons[] = 'shared_network';
        }

        $buyer = $review->buyer()->first();
        $isNew = $buyer && $buyer->created_at && $buyer->created_at->gt(now()->subDays(self::NEW_ACCOUNT_DAYS));
        if ($isNew && $this->onlyDealtWith($buyerId, $agentId)) {
            $reasons[] = 'new_single_agent';
        }

        $recentPraise = AgentReview::where('agent_id', $agentId)
            ->where('rating', '>=', 4)
            ->where('created_at', '>=', Carbon::now()->subHours(self::BURST_HOURS))
            ->count();
        if ($review->rating >= 4 && $recentPraise >= self::BURST_COUNT) {
            $reasons[] = 'burst';
        }

        if ($review->rating >= 4 && $review->appointment_id) {
            $appt = Appointment::find($review->appointment_id);
            if ($appt && $appt->wasCancelledByAgent()) {
                $reasons[] = 'praised_after_cancel';
            }
        }

        return $reasons;
    }

    /** Has this buyer only ever booked, chatted with or reviewed this one agent? */
    private function onlyDealtWith(int $buyerId, int $agentId): bool
    {
        $others = Appointment::where('buyer_id', $buyerId)->where('agent_id', '!=', $agentId)->exists()
            || Conversation::where('buyer_id', $buyerId)->where('agent_id', '!=', $agentId)->exists()
            || AgentReview::where('buyer_id', $buyerId)->where('agent_id', '!=', $agentId)->exists();

        return ! $others;
    }
}
