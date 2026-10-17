<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Helpers;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * The date ranges of a package's priceinfo seasons, in either shape the
 * stored data comes in: a flat list (`seasons[]` with FromDate/ToDate, the
 * shape the API sends and the calendar reads) or one nested under
 * `seasons.season` (DateFrom/DateTo). A single season may arrive as an object
 * instead of a one-item list.
 *
 * Pure: no database, no clock (today is passed in).
 */
final class SeasonDates
{
    /**
     * Every season of one decoded priceinfo, as Y-m-d ranges. Seasons without
     * a valid range are skipped.
     *
     * @param array<array-key, mixed> $priceinfo decoded priceinfo_data
     *
     * @return list<array{from: string, to: string}>
     */
    public static function seasons(array $priceinfo): array
    {
        $raw = $priceinfo['seasons'] ?? [];
        if (is_array($raw) && array_key_exists('season', $raw)) {
            $raw = $raw['season'];
        }
        if (!is_array($raw)) {
            return [];
        }
        if (self::isSeason($raw)) {
            $raw = [$raw];
        }

        $ranges = [];
        foreach ($raw as $season) {
            if (!is_array($season)) {
                continue;
            }
            $from = self::date($season['FromDate'] ?? $season['DateFrom'] ?? null);
            $to = self::date($season['ToDate'] ?? $season['DateTo'] ?? null);
            if ($from !== '' && $to !== '' && $from <= $to) {
                $ranges[] = ['from' => $from, 'to' => $to];
            }
        }

        return $ranges;
    }

    /**
     * The hotel's current or next accommodation period across all its
     * packages: from the earliest start to the latest end of the seasons that
     * have not ended before $today. NULL when every season is over (or there
     * is none), so a page never offers last season's dates as if they were
     * bookable.
     *
     * @param iterable<array<array-key, mixed>> $priceinfos decoded priceinfo_data, one per package
     * @param string $today Y-m-d
     *
     * @return array{from: string, to: string}|null
     */
    public static function window(iterable $priceinfos, string $today): ?array
    {
        $from = '';
        $to = '';
        foreach ($priceinfos as $priceinfo) {
            foreach (self::seasons($priceinfo) as $season) {
                if ($season['to'] < $today) {
                    continue;
                }
                if ($from === '' || $season['from'] < $from) {
                    $from = $season['from'];
                }
                if ($season['to'] > $to) {
                    $to = $season['to'];
                }
            }
        }

        return $from === '' ? null : ['from' => $from, 'to' => $to];
    }

    /** @param array<array-key, mixed> $value */
    private static function isSeason(array $value): bool
    {
        foreach (['FromDate', 'DateFrom', 'ToDate', 'DateTo', 'Season', 'IdSeason', 'SeasonNr'] as $key) {
            if (array_key_exists($key, $value)) {
                return true;
            }
        }

        return false;
    }

    /** The Y-m-d part of a date value ('2027-05-15' or '2027-05-15 00:00:00'), '' when it is none. */
    private static function date(mixed $value): string
    {
        $date = substr(trim(TypeCoerce::toString($value)), 0, 10);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : '';
    }
}
