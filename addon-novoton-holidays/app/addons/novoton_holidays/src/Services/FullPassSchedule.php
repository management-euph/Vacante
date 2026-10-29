<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Services;

/**
 * When the full passes of hotel details (hotelinfo) and season prices
 * (priceinfo) start, as Novoton asked on 28 Oct 2026:
 *  - hotel details every 3 days while Novoton loads the new season
 *    (February to May), every 7 days otherwise;
 *  - season prices once a month, offers_update covering the changes between;
 *  - from 05:00 Romanian time, never in their busiest hour (03:00–04:00).
 *
 * A pass is due on the day its interval ends, from START_HOUR: one that
 * finished at 05:20 on the 1st is due again at 05:00 on the 4th, and one
 * that ran in the afternoon (the first, or a manual one) moves back to the
 * morning. The jobs run every 5 minutes, so a due pass starts within minutes.
 *
 * Pure: the caller passes the time. Dates are in PHP's time zone, which
 * CS-Cart sets to the store's.
 */
final class FullPassSchedule
{
    /** The months Novoton loads the new season's data in: February to May. */
    public const array PEAK_MONTHS = [2, 3, 4, 5];

    /** The hour a due pass starts from (the store's time). */
    public const int START_HOUR = 5;

    /** Novoton's busiest hour at night (03:00–04:00): no full pass starts in it. */
    public const int BUSY_HOUR = 3;

    /** Days between two hotel-details passes, for the month $now is in. */
    public static function hotelInfoDays(int $now, int $peakDays, int $otherDays): int
    {
        return max(1, in_array((int) date('n', $now), self::PEAK_MONTHS, true) ? $peakDays : $otherDays);
    }

    /**
     * Whether a full pass may start now.
     *
     * @param int|null $lastFullAt when the last full pass finished (unix
     *                             time); null if none ever did, and the first
     *                             one starts at once
     */
    public static function due(?int $lastFullAt, int $days, int $now): bool
    {
        if ((int) date('G', $now) === self::BUSY_HOUR) {
            return false;
        }

        return $lastFullAt === null || $now >= self::nextAt($lastFullAt, $days);
    }

    /** When the pass after one that finished at $lastFullAt is due (unix time). */
    public static function nextAt(int $lastFullAt, int $days): int
    {
        $finished = (new \DateTimeImmutable('@' . $lastFullAt))
            ->setTimezone(new \DateTimeZone(date_default_timezone_get()));

        return $finished->setTime(self::START_HOUR, 0)->modify('+' . max(1, $days) . ' days')->getTimestamp();
    }
}
