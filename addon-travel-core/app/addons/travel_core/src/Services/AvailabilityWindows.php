<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Services;

/**
 * Which stays an availability check asks a supplier about.
 *
 * The stays are "near" dates (N days from today) plus the store's peak-season
 * dates, because a near-dates-only check is wrong for most of the year: in
 * late October Eurosite had an Immediate offer for 50 of 500 whitelisted
 * hotels, the seaside ones being closed until summer. A check that looked only
 * a few weeks ahead would call nearly every seaside hotel unavailable from
 * autumn to spring.
 *
 * Moved from Eurosite's AvailabilityPlan so every supplier's check asks the
 * same way (docs/CONVERGENCE.md). Pure: no database, no clock (today is passed
 * in), so every rule here is unit-tested.
 */
final class AvailabilityWindows
{
    /**
     * "14, 30, 60" → [14, 30, 60]. Unique, ascending, 1–365; junk ignored.
     *
     * @return list<int>
     */
    public static function parseDays(string $raw): array
    {
        $days = [];
        foreach (preg_split('/[\s,;]+/', $raw) ?: [] as $part) {
            if ($part !== '' && ctype_digit($part)) {
                $n = (int) $part;
                if ($n >= 1 && $n <= 365) {
                    $days[$n] = $n;
                }
            }
        }
        sort($days);

        return $days;
    }

    /**
     * The peak-season check-in dates still ahead of $today, as Y-m-d.
     *
     * "07-15" repeats every year: it means the next 15 July (this year's, or
     * next year's once it has passed). "2027-07-15" is that date only, and
     * is dropped once it has passed. Anything else is ignored.
     *
     * @return list<string>
     */
    public static function parseSeasonDates(string $raw, \DateTimeImmutable $today): array
    {
        $today = $today->setTime(0, 0);
        $out = [];
        foreach (preg_split('/[\s,;]+/', trim($raw)) ?: [] as $part) {
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $part, $m) === 1) {
                if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                    continue;
                }
                $date = $today->setDate((int) $m[1], (int) $m[2], (int) $m[3]);
            } elseif (preg_match('/^(\d{2})-(\d{2})$/', $part, $m) === 1) {
                $year = (int) $today->format('Y');
                if (!checkdate((int) $m[1], (int) $m[2], 2024)) { // a leap year: 02-29 is a real month-day
                    continue;
                }
                $date = $today->setDate($year, (int) $m[1], (int) $m[2]);
                if ($date <= $today) {
                    $date = $today->setDate($year + 1, (int) $m[1], (int) $m[2]);
                }
            } else {
                continue;
            }
            if ($date > $today) {
                $out[$date->format('Y-m-d')] = true;
            }
        }
        $dates = array_keys($out);
        sort($dates);

        return $dates;
    }

    /**
     * Every stay to check: near dates first, then season dates. A season
     * date that falls on a near date is checked once, as near.
     *
     * @param list<int> $nearDays
     *
     * @return list<array{kind: string, check_in: string, check_out: string}>
     */
    public static function windows(\DateTimeImmutable $today, array $nearDays, string $seasonDates, int $nights): array
    {
        $today = $today->setTime(0, 0);
        $nights = max(1, $nights);
        $windows = [];
        foreach ($nearDays as $days) {
            $checkIn = $today->modify('+' . $days . ' days');
            $windows[$checkIn->format('Y-m-d')] = [
                'kind' => 'near',
                'check_in' => $checkIn->format('Y-m-d'),
                'check_out' => $checkIn->modify('+' . $nights . ' days')->format('Y-m-d'),
            ];
        }
        foreach (self::parseSeasonDates($seasonDates, $today) as $date) {
            if (isset($windows[$date])) {
                continue;
            }
            $checkIn = new \DateTimeImmutable($date);
            $windows[$date] = [
                'kind' => 'season',
                'check_in' => $date,
                'check_out' => $checkIn->modify('+' . $nights . ' days')->format('Y-m-d'),
            ];
        }

        return array_values($windows);
    }
}
