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

    public function getAvailableSlots(User $agent, string $date): array
    {
        $day = CarbonImmutable::parse($date);

        if ($this->isDayFullyBlocked($agent, $day)) {
            return [];
        }

        $slots    = $this->generateTimeSlots($day);
        $blocked  = $this->getBlockedTimeRanges($agent, $day);
        $booked   = $this->getBookedSlots($agent, $day);

        return array_values(array_filter($slots, function (string $slot) use ($blocked, $booked) {
            return ! in_array($slot, $booked, true) && ! $this->isInBlockedRange($slot, $blocked);
        }));
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
        $day = CarbonImmutable::parse($datetime->format('Y-m-d'));

        if ($this->isDayFullyBlocked($agent, $day)) {
            return false;
        }

        $slot    = $datetime->format('H:i');
        $blocked = $this->getBlockedTimeRanges($agent, $day);
        $booked  = $this->getBookedSlots($agent, $day);

        return ! in_array($slot, $booked, true) && ! $this->isInBlockedRange($slot, $blocked);
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
