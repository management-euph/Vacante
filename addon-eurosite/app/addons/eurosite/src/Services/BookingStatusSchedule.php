<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Services;

/**
 * When to ask Eurosite about a booking still waiting for confirmation, as
 * Eurosite asked (28 Oct 2026): the first time 30 minutes after it was
 * booked, then every 30 minutes for two hours; if the answer is still not
 * final, wait 8 hours, then every 30 minutes for two hours again. After that
 * only staff ask (Travel Core → Bookings, Sync statuses).
 *
 * Minutes after the booking, checks fall at 30–150, then at 630–750. A run
 * every 15 minutes finds a booking due once 25 minutes passed since its
 * last check, so cron's own timing never skips one.
 *
 * Pure: no database, no clock.
 */
final class BookingStatusSchedule
{
    /** The rounds of checks, in minutes after the booking: [first, last]. */
    public const array ROUNDS = [[30, 150], [630, 750]];

    /** Minutes between two checks. */
    public const int EVERY = 30;

    /** How early a check may come, for cron's own timing (minutes). */
    private const int SLACK = 5;

    public const string DUE = 'due';

    public const string WAIT = 'wait';

    public const string DONE = 'done';

    /**
     * Whether to ask about a booking now: 'due', 'wait' (not yet, or too
     * soon after the last check), or 'done' (the schedule is over).
     *
     * @param int $bookedAt when it was booked (unix time)
     * @param int $lastCheckedAt the last automatic check (unix time), 0 if none
     */
    public static function state(int $bookedAt, int $lastCheckedAt, int $now): string
    {
        $minutes = intdiv(max(0, $now - $bookedAt), 60);
        $last = self::ROUNDS[count(self::ROUNDS) - 1][1];
        if ($minutes > $last + self::SLACK) {
            return self::DONE;
        }
        foreach (self::ROUNDS as [$first, $end]) {
            if ($minutes >= $first - self::SLACK && $minutes <= $end + self::SLACK) {
                $sinceLast = $lastCheckedAt > 0 ? intdiv(max(0, $now - $lastCheckedAt), 60) : PHP_INT_MAX;

                return $sinceLast >= self::EVERY - self::SLACK ? self::DUE : self::WAIT;
            }
        }

        return self::WAIT;
    }
}
