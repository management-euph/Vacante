<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Services;

use Tygh\Addons\Eurosite\Dto\HotelOffer;

/**
 * What the availability check asks the API, and how it reads the answers.
 *
 * A hotel counts as available when ANY checked stay has an Immediate (IM)
 * offer. Which stays are checked (near dates plus the peak-season dates) is
 * Travel Core's AvailabilityWindows, shared by every supplier.
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

    /**
     * What a check that only partly answered may store: the answers better
     * than what each hotel already has. A date that did not answer may have
     * held a better offer, so a worse or equal answer, or none, proves
     * nothing about the hotel. A hotel never checked ('') takes any answer.
     *
     * @param array<string, string> $stored product_code => stored availability
     * @param array<array-key, array<string, mixed>> $best product_code => merge() result
     *
     * @return array<array-key, array<string, mixed>>
     */
    public static function improvements(array $stored, array $best): array
    {
        $out = [];
        foreach ($best as $code => $answer) {
            $new = $answer['availability'] ?? '';
            $rankNew = is_string($new) ? (self::RANK[$new] ?? -1) : -1;
            if ($rankNew > (self::RANK[$stored[(string) $code] ?? ''] ?? -1)) {
                $out[$code] = $answer;
            }
        }

        return $out;
    }

    /** Only Immediate counts: On request and Stop sale hotels are listed, never published. */
    public static function isAvailable(string $code): bool
    {
        return $code === 'IM';
    }
}
