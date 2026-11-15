<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Appointment;
use App\Models\AgentBlockedDate;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

class AvailabilityService
{
    /**
     * How long a viewing takes, and therefore how far apart bookable slots are.
     *
     * This MUST match the Google Calendar event length — the picker offered
     * 30-minute slots while the calendar event was created as a full hour, so
     * 10:00 and 10:30 were both bookable and the two events overlapped in the
     * agent's real calendar. One constant, used by both.
     */
    public const SLOT_MINUTES   = 60;
    public const DAY_START_HOUR = 8;
    public const DAY_END_HOUR   = 18;

    /** A viewing lasts an hour; agents get a gap after each one to travel. */
    public const VIEWING_MINUTES    = 60;
    public const TRAVEL_GAP_MINUTES = 30;

    /** Custom times (outside the default hourly slots). */
    public const CUSTOM_EARLIEST       = '07:00';
    public const CUSTOM_LATEST_START   = '19:00';   // so the viewing ends by 8:00 PM
    public const CUSTOM_STEP_MINUTES   = 15;
    public const CUSTOM_NOTICE_HOURS   = 2;
    public const MAX_DAYS_AHEAD        = 60;

    public function getAvailableSlots(User $agent, string $date): array
    {
        $day = CarbonImmutable::parse($date);

        if ($this->isDayFullyBlocked($agent, $day)) {
            return [];
        }

        $slots   = $this->generateTimeSlots($day);
        $blocked = $this->getBlockedTimeRanges($agent, $day);
        $booked  = $this->bookingsOn($agent, $day);
        $now     = now();

        return array_values(array_filter($slots, function (string $slot) use ($day, $blocked, $booked, $now) {
            $start = Carbon::parse($day->format('Y-m-d') . ' ' . $slot);

            return $start->gt($now)   // no times that have already passed today
                && ! $this->overlapsBlocked($start, $blocked)
                && $this->conflictIn($start, $booked) === null;
        }));
    }

    /**
     * Why this time can't be booked with this agent, or null if it can.
     * A viewing takes an hour, and agents get a 30-minute gap before the next one.
     */
    public function availabilityError(User $agent, Carbon $start): ?string
    {
        $day = CarbonImmutable::parse($start->format('Y-m-d'));

        if ($this->isDayFullyBlocked($agent, $day) || $this->overlapsBlocked($start, $this->getBlockedTimeRanges($agent, $day))) {
            return 'The agent isn’t available at that time. Please choose another time or day.';
        }

        if ($clash = $this->conflictIn($start, $this->bookingsOn($agent, $day))) {
            $from  = $clash->copy()->format('g:i A');
            $until = $clash->copy()->addMinutes(self::VIEWING_MINUTES)->format('g:i A');
            $next  = $clash->copy()->addMinutes(self::VIEWING_MINUTES + self::TRAVEL_GAP_MINUTES)->format('g:i A');

            return "The agent already has a viewing from {$from} to {$until}. Please choose {$next} or later, or an earlier time.";
        }

        return null;
    }

    /** Is this one of the default hourly slots (8:00 AM – 5:00 PM, on the hour)? */
    public function isStandardSlot(Carbon $start): bool
    {
        return $start->minute === 0 && $start->hour >= self::DAY_START_HOUR && $start->hour < self::DAY_END_HOUR;
    }

    /** Rules for a custom time the buyer typed in, or null if it's fine. */
    public function customTimeError(Carbon $start): ?string
    {
        $hm = $start->format('H:i');

        if ($hm < self::CUSTOM_EARLIEST || $hm > self::CUSTOM_LATEST_START) {
            return 'Custom times must start between 7:00 AM and 7:00 PM, so the viewing ends by 8:00 PM.';
        }
        if ($start->minute % self::CUSTOM_STEP_MINUTES !== 0 || $start->second !== 0) {
            return 'Please choose a time in 15-minute steps, like 9:15 or 10:30.';
        }
        if ($start->lt(now()->addHours(self::CUSTOM_NOTICE_HOURS))) {
            return 'Custom times need at least 2 hours’ notice so the agent can confirm.';
        }
        if ($start->gt(now()->addDays(self::MAX_DAYS_AHEAD))) {
            return 'Viewings can be booked up to 60 days ahead.';
        }

        return null;
    }

    /**
     * Upcoming dates that are fully blocked (disable in the buyer's date picker)
     * vs. partially blocked / "limited" (still selectable, fewer times).
     *
     * @return array{blocked: array<int,string>, limited: array<int,string>}
     */
    public function getUnavailability(User $agent, int $days = 60): array
    {
        $today = CarbonImmutable::today();
        $end   = $today->addDays($days);

        $fully   = [];
        $limited = [];

        foreach (AgentBlockedDate::forAgent($agent->id)->get() as $b) {
            $isWholeDay = $b->start_time === null;

            // Which dates in the window does this block touch?
            $dates = [];
            if ($b->is_recurring && $b->recurring_day_of_week !== null) {
                for ($d = $today; $d <= $end; $d = $d->addDay()) {
                    if ($d->dayOfWeek === (int) $b->recurring_day_of_week) {
                        $dates[] = $d->toDateString();
                    }
                }
            } elseif ($b->blocked_date) {
                $bd = CarbonImmutable::parse((string) $b->blocked_date);
                if ($bd >= $today && $bd <= $end) {
                    $dates[] = $bd->toDateString();
                }
            }

            foreach ($dates as $ds) {
                if ($isWholeDay) {
                    $fully[$ds] = true;
                } else {
                    $limited[$ds] = true;
                }
            }
        }

        // A fully-blocked day is never merely "limited".
        $limited = array_diff_key($limited, $fully);

        return [
            'blocked' => array_keys($fully),
            'limited' => array_keys($limited),
        ];
    }

    public function isSlotAvailable(User $agent, Carbon $datetime): bool
    {
        return $this->availabilityError($agent, $datetime) === null;
    }

    /** Start times of the agent's live (pending/confirmed) viewings that day. @return array<int, Carbon> */
    private function bookingsOn(User $agent, CarbonImmutable $day): array
    {
        return Appointment::where('agent_id', $agent->id)
            ->whereIn('status', ['pending', 'confirmed'])
            ->whereDate('preferred_datetime', $day->toDateString())
            ->pluck('preferred_datetime')
            ->map(fn ($dt) => Carbon::parse($dt))
            ->all();
    }

    /**
     * The existing viewing this one would clash with: two viewings clash when
     * they're closer than an hour plus the travel gap.
     *
     * @param array<int, Carbon> $booked
     */
    private function conflictIn(Carbon $start, array $booked): ?Carbon
    {
        $span = self::VIEWING_MINUTES + self::TRAVEL_GAP_MINUTES;
        foreach ($booked as $b) {
            if (abs($b->diffInMinutes($start, false)) < $span) {
                return $b;
            }
        }

        return null;
    }

    /** Does the hour-long viewing starting here overlap a blocked time range? */
    private function overlapsBlocked(Carbon $start, array $ranges): bool
    {
        $s = $start->format('H:i');
        $e = $start->copy()->addMinutes(self::VIEWING_MINUTES)->format('H:i');
        foreach ($ranges as $range) {
            if ($range['start_time'] && $range['end_time']) {
                $bs = substr((string) $range['start_time'], 0, 5);
                $be = substr((string) $range['end_time'], 0, 5);
                if ($s < $be && $bs < $e) {
                    return true;
                }
            }
        }

        return false;
    }

    private function generateTimeSlots(CarbonImmutable $day): array
    {
        $slots = [];
        $start = $day->setTime(self::DAY_START_HOUR, 0);
        $end   = $day->setTime(self::DAY_END_HOUR, 0);

        while ($start < $end) {
            $slots[] = $start->format('H:i');
            $start   = $start->addMinutes(self::SLOT_MINUTES);
        }

        return $slots;
    }

    private function isDayFullyBlocked(User $agent, CarbonImmutable $day): bool
    {
        return AgentBlockedDate::forAgent($agent->id)
            ->where(function ($q) use ($day): void {
                $q->where(function ($q2) use ($day): void {
                    $q2->where('is_recurring', false)
                        ->whereDate('blocked_date', $day->toDateString())
                        ->whereNull('start_time');
                })->orWhere(function ($q2) use ($day): void {
                    $q2->where('is_recurring', true)
                        ->where('recurring_day_of_week', $day->dayOfWeek)
                        ->whereNull('start_time');
                });
            })
            ->exists();
    }

    private function getBlockedTimeRanges(User $agent, CarbonImmutable $day): array
    {
        return AgentBlockedDate::forAgent($agent->id)
            ->where(function ($q) use ($day): void {
                $q->where(function ($q2) use ($day): void {
                    $q2->where('is_recurring', false)
                        ->whereDate('blocked_date', $day->toDateString())
                        ->whereNotNull('start_time');
                })->orWhere(function ($q2) use ($day): void {
                    $q2->where('is_recurring', true)
                        ->where('recurring_day_of_week', $day->dayOfWeek)
                        ->whereNotNull('start_time');
                });
            })
            ->get(['start_time', 'end_time'])
            ->toArray();
    }

    private function getBookedSlots(User $agent, CarbonImmutable $day): array
    {
        return Appointment::where('agent_id', $agent->id)
            ->whereIn('status', ['pending', 'confirmed'])
            ->whereDate('preferred_datetime', $day->toDateString())
            ->pluck('preferred_datetime')
            ->map(fn ($dt) => Carbon::parse($dt)->format('H:i'))
            ->all();
    }

    private function isInBlockedRange(string $slot, array $ranges): bool
    {
        foreach ($ranges as $range) {
            if ($range['start_time'] && $range['end_time']) {
                // DB `time` columns come back as "HH:MM:SS"; slots are "HH:MM" — normalise.
                $start = substr((string) $range['start_time'], 0, 5);
                $end   = substr((string) $range['end_time'], 0, 5);
                if ($slot >= $start && $slot < $end) {
                    return true;
                }
            }
        }

        return false;
    }
}
