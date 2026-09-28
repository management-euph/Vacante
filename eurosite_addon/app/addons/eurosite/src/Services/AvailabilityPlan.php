<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Services;

use Tygh\Addons\Eurosite\Dto\HotelOffer;

/**
 * What the availability check asks the API, and how it reads the answers.
 *
 * A hotel counts as available when ANY checked stay has an Immediate (IM)
 * offer. The stays are "near" dates (N days from today) plus the store's
 * peak-season dates, because the live data showed a near-dates-only check
 * is wrong for most of the year: for 23–30 Oct only 50 of 500 whitelisted
 * hotels had an Immediate offer, the seaside ones being closed until
 * summer. A check that looked only a few weeks ahead would hide nearly every
 * seaside product from autumn to spring.
 *
 * Pure: no database, no API, no clock (today is passed in), so every rule
 * here is unit-tested.
 */
final class AvailabilityPlan
{
    /** Best first. NONE = checked, no offer in any window. */
    public const RANK = ['IM' => 3, 'OR' => 2, 'ST' => 1, 'NONE' => 0];

    /** One double room, two adults: the stay the list and the products quote. */
    public const ROOM = ['code' => 'DB', 'adults' => 2, 'children' => []];

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

    /**
     * Fold one offer into what is known about its hotel.
     *
     * The better availability wins (IM > OR > ST); at the same availability
     * the lower price wins, so the price shown and put on the product is
     * the cheapest Immediate stay found. The window that won is kept: the
     * list says which date proved the hotel available.
     *
     * @param array<string, mixed>|null $current
     * @param array{kind: string, check_in: string, check_out: string} $window
     *
     * @return array{availability: string, min_price: float, min_gross: float, currency: string, check_in: string, window: string, category: int, first_image: string, name: string, city_code: string, country_code: string, class: string, meals: list<string>}
     */
    public static function merge(?array $current, HotelOffer $offer, array $window): array
    {
        $code = $offer->availabilityCode !== '' ? $offer->availabilityCode : 'OR';
        $candidate = [
            'availability' => $code,
            'min_price' => $offer->price,
            'min_gross' => $offer->gross,
            'currency' => $offer->currency,
            'check_in' => $window['check_in'],
            'window' => $window['kind'],
            'category' => $offer->category,
            'first_image' => $offer->firstImage,
            'name' => $offer->productName,
            'city_code' => $offer->cityCode,
            'country_code' => $offer->countryCode,
            'class' => $offer->class,
            'meals' => self::mealNames($offer),
        ];
        if ($current === null) {
            return $candidate;
        }

        /** @var array{availability: string, min_price: float, min_gross: float, currency: string, check_in: string, window: string, category: int, first_image: string, name: string, city_code: string, country_code: string, class: string, meals: list<string>} $current */
        $rankNew = self::RANK[$code] ?? 0;
        $rankOld = self::RANK[$current['availability']] ?? 0;
        $better = $rankNew > $rankOld
            || ($rankNew === $rankOld && $offer->price > 0 && ($current['min_price'] <= 0 || $offer->price < $current['min_price']));

        $winner = $better ? $candidate : $current;
        // Stars and a cover image are facts about the hotel, not the offer:
        // keep whichever answer carried them.
        $winner['category'] = $winner['category'] > 0 ? $winner['category'] : ($better ? $current['category'] : $candidate['category']);
        $winner['first_image'] = $winner['first_image'] !== '' ? $winner['first_image'] : ($better ? $current['first_image'] : $candidate['first_image']);
        // The class too; and the meal plans are every plan ANY offer sold
        // (the board feature lists what the hotel offers, not the cheapest).
        $winner['class'] = $winner['class'] !== '' ? $winner['class'] : ($better ? $current['class'] : $candidate['class']);
        $winner['meals'] = array_values(array_unique([...$current['meals'], ...$candidate['meals']]));

        return $winner;
    }

    /**
     * The meal plan names an offer sells ("Demipensiune", "All Inclusive").
     *
     * @return list<string>
     */
    public static function mealNames(HotelOffer $offer): array
    {
        $names = [];
        foreach ($offer->meals as $meal) {
            $name = trim($meal['name'] ?? '');
            if ($name !== '') {
                $names[$name] = true;
            }
        }

        return array_map('strval', array_keys($names));
    }

    /** Only Immediate counts: On request and Stop sale hotels are listed, never published. */
    public static function isAvailable(string $code): bool
    {
        return $code === 'IM';
    }
}
