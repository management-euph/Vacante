<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Services;

/**
 * Whether one hotel-list answer (a country, a city) is complete enough to
 * count hotels missing from it (docs/novoton-availability-gaps-plan.md §4.2).
 *
 * A supplier outage often looks like a short or empty list, and three such
 * answers would mark a whole country "left the feed". So an answer counts
 * only when it is not empty and holds at least 90% of the hotels the group
 * had (HotelAvailabilityRepository::listedCount()), or at most one fewer: a
 * city of three hotels must still be able to lose one. Anything else is no
 * information, never "missing".
 */
final class ListCompleteness
{
    public const int MIN_PERCENT = 90;

    /**
     * @param int $count hotels in this answer
     * @param int $previous hotels the group had (0 when none was listed yet)
     */
    public static function complete(int $count, int $previous, int $minPercent = self::MIN_PERCENT): bool
    {
        return $count > 0 && ($count * 100 >= $previous * $minPercent || $count >= $previous - 1);
    }
}
