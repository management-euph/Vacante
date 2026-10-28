<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * What the weekly availability check (`room_price`,
 * docs/novoton-availability-gaps-plan.md §4.3) asks Novoton about, and in
 * which order.
 *
 * A unit is either a resort, asked with one resort-level room_price call per
 * date, which answers for every hotel of the resort that has a price; or a
 * hotel whose city matches none of its country's resorts, which a resort
 * answer cannot cover, so it is asked on its own. A resort is a unit while
 * at least one of its hotels is sold (DestinationScope).
 *
 * Least recently checked first: a unit's last check is its hotels' oldest
 * last_price_check, so a resort with a hotel never checked comes first, and
 * a unit the last run did not reach, or did not get a whole answer for,
 * comes first the next night. Each run takes its share: one seventh of the
 * units for a weekly cycle, no more than the call budget allows.
 *
 * Pure: no database, no API, no clock.
 */
final class ResortCheckQueue
{
    public const string RESORT = 'resort';

    public const string HOTEL = 'hotel';

    /** Dates a unit may add for its hotels' first published week (§4.3). */
    public const int MAX_FIRST_WEEKS = 2;

    /**
     * Every unit, least recently checked first; a last_check of '' is never.
     *
     * A country missing from $resorts is left out: without its resort list,
     * its hotels can be told neither by resort nor alone.
     *
     * @param list<array<string, mixed>> $hotels the sold hotels: hotel_id, country, city, last_price_check
     * @param array<string, list<string>> $resorts country => its resorts, as Novoton's resort_list names them
     *
     * @return list<array{kind: string, country: string, resort: string, hotel_ids: list<string>, last_check: string}>
     */
    public static function units(array $hotels, array $resorts): array
    {
        $names = [];
        foreach ($resorts as $country => $list) {
            foreach ($list as $name) {
                $key = self::key($name);
                if ($key !== '') {
                    $names[self::key((string) $country)][$key] ??= trim($name);
                }
            }
        }

        $units = [];
        foreach ($hotels as $hotel) {
            $country = self::key(TypeCoerce::toString($hotel['country'] ?? ''));
            $hotelId = TypeCoerce::toString($hotel['hotel_id'] ?? '');
            if ($hotelId === '' || !isset($names[$country])) {
                continue;
            }
            $city = self::key(TypeCoerce::toString($hotel['city'] ?? ''));
            $checked = self::checkedAt($hotel['last_price_check'] ?? null);
            if ($city !== '' && isset($names[$country][$city])) {
                $id = self::RESORT . '|' . $country . '|' . $city;
                $units[$id] ??= ['kind' => self::RESORT, 'country' => $country, 'resort' => $names[$country][$city], 'hotel_ids' => [], 'last_check' => $checked];
                $units[$id]['hotel_ids'][] = $hotelId;
                $units[$id]['last_check'] = min($units[$id]['last_check'], $checked);
            } else {
                $units[self::HOTEL . '|' . $hotelId] = ['kind' => self::HOTEL, 'country' => $country, 'resort' => '', 'hotel_ids' => [$hotelId], 'last_check' => $checked];
            }
        }

        $units = array_values($units);
        usort($units, static fn (array $a, array $b): int => [$a['last_check'], $a['country'], $a['resort'], $a['hotel_ids'][0]]
            <=> [$b['last_check'], $b['country'], $b['resort'], $b['hotel_ids'][0]]);

        return $units;
    }

    /**
     * How many units one run takes: its share of a cycle of $cycleDays runs
     * (one seventh for a weekly cycle), and no more than the call budget
     * allows at $callsPerUnit calls each.
     */
    public static function share(int $units, int $cycleDays, int $callsPerUnit, int $maxCalls): int
    {
        if ($units <= 0) {
            return 0;
        }
        $share = (int) ceil($units / max(1, $cycleDays));

        return max(0, min($share, intdiv(max(0, $maxCalls), max(1, $callsPerUnit))));
    }

    /**
     * A unit's stays: the check's own, then the first published week of its
     * hotels whose prices start later but that had no offer in their latest
     * check, so a hotel whose season is none of the check's dates is asked
     * about its own (§4.3). At most MAX_FIRST_WEEKS of those, the earliest.
     *
     * @param list<array{kind: string, check_in: string, check_out: string}> $windows AvailabilityWindows::windows()
     * @param list<string> $firstPublished the hotels' first priced dates (Y-m-d), all after today
     *
     * @return list<array{kind: string, check_in: string, check_out: string}>
     */
    public static function dates(array $windows, array $firstPublished, int $nights): array
    {
        $dates = $windows;
        $taken = array_fill_keys(array_column($windows, 'check_in'), true);
        $starts = array_values(array_unique(array_filter(
            $firstPublished,
            static fn (string $d): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1,
        )));
        sort($starts);
        $added = 0;
        foreach ($starts as $start) {
            if ($added >= self::MAX_FIRST_WEEKS) {
                break;
            }
            if (isset($taken[$start])) {
                continue;
            }
            $dates[] = [
                'kind' => 'first_week',
                'check_in' => $start,
                'check_out' => (new \DateTimeImmutable($start))->modify('+' . max(1, $nights) . ' days')->format('Y-m-d'),
            ];
            $taken[$start] = true;
            $added++;
        }

        return $dates;
    }

    /**
     * A unit's dates in two: the ones to ask Novoton, and the ones its
     * hotels' published prices (priceinfo) already answer: a stay none of
     * them has a price for can have no offer, so asking would only cost a
     * call. A date is left out only when every hotel's window is known and
     * none touches the stay; one hotel without a known window keeps every
     * date asked. A window is not known when:
     *
     *  - it is missing: the hotel's prices were never read;
     *  - it ended before today: the calendar was not rebuilt since;
     *  - the latest check found an offer on a future date outside it.
     *
     * @param list<array{kind: string, check_in: string, check_out: string}> $dates
     * @param list<string> $hotelIds the unit's hotels
     * @param array<array-key, array{from: ?string, to: ?string, next: ?string}> $windows hotel_id => its published price window (from and to null: no future price) and the earliest offer of its latest check
     *
     * @return array{ask: list<array{kind: string, check_in: string, check_out: string}>, skip: list<array{kind: string, check_in: string, check_out: string}>}
     */
    public static function split(array $dates, array $hotelIds, array $windows, string $today): array
    {
        $known = [];
        foreach ($hotelIds as $hotelId) {
            $window = $windows[$hotelId] ?? null;
            if ($window === null || !self::trusted($window, $today)) {
                return ['ask' => $dates, 'skip' => []];
            }
            $known[] = $window;
        }

        $split = ['ask' => [], 'skip' => []];
        foreach ($dates as $date) {
            $priced = $known === [];
            foreach ($known as $window) {
                $priced = $priced || self::touches($window, $date['check_in'], $date['check_out']);
            }
            $split[$priced ? 'ask' : 'skip'][] = $date;
        }

        return $split;
    }

    /**
     * Whether a window can answer for its hotel today.
     *
     * @param array{from: ?string, to: ?string, next: ?string} $window
     */
    private static function trusted(array $window, string $today): bool
    {
        ['from' => $from, 'to' => $to, 'next' => $next] = $window;
        if (($from === null) !== ($to === null) || ($to !== null && $to < $today)) {
            return false;
        }

        return $next === null || $next < $today || ($from !== null && $from <= $next && $next <= $to);
    }

    /**
     * Whether one night of the stay has a published price (check-out is not a night).
     *
     * @param array{from: ?string, to: ?string, next: ?string} $window
     */
    private static function touches(array $window, string $checkIn, string $checkOut): bool
    {
        return $window['from'] !== null && $window['to'] !== null
            && $checkIn <= $window['to'] && $checkOut > $window['from'];
    }

    private static function key(string $name): string
    {
        return mb_strtoupper(trim($name));
    }

    /** A real check time, or '' for never (NULL, empty or a zero date). */
    private static function checkedAt(mixed $value): string
    {
        $value = TypeCoerce::toString($value);

        return preg_match('/^[1-9]\d{3}-\d{2}-\d{2}/', $value) === 1 ? $value : '';
    }
}
