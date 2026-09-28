<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Helpers;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * The location line of a search offer card: "Resort, Country".
 *
 * The Sphinx search offer names only the resort/city (destination_name);
 * the country comes from the hotel's row (HotelRepository::findCountryNames).
 */
final class OfferLocation
{
    /** The destination plus the country, unless the destination already ends with it. */
    public static function label(string $destination, string $country): string
    {
        $destination = trim($destination);
        $country = trim($country);
        if ($country === '') {
            return $destination;
        }
        if ($destination === '') {
            return $country;
        }
        if (mb_strtolower(mb_substr($destination, -mb_strlen($country))) === mb_strtolower($country)) {
            return $destination;
        }

        return $destination . ', ' . $country;
    }

    /**
     * Distinct hotel ids of the offers, for the country lookup.
     *
     * @param list<array<string, mixed>> $offers
     * @return list<string>
     */
    public static function hotelIds(array $offers): array
    {
        $ids = [];
        foreach ($offers as $offer) {
            $id = TypeCoerce::toString($offer['hotel_id'] ?? '');
            if ($id !== '' && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Rewrites each offer's `destination` to "Resort, Country".
     *
     * @param list<array<string, mixed>> $offers flattened offers (SearchOfferNormalizer)
     * @param array<string, string> $countryByHotel hotel_id => country name
     * @return list<array<string, mixed>>
     */
    public static function applyCountry(array $offers, array $countryByHotel): array
    {
        foreach ($offers as $i => $offer) {
            $country = $countryByHotel[TypeCoerce::toString($offer['hotel_id'] ?? '')] ?? '';
            $offers[$i]['destination'] = self::label(TypeCoerce::toString($offer['destination'] ?? ''), $country);
        }

        return $offers;
    }
}
