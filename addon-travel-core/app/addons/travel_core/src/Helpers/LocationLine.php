<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Helpers;

/**
 * "Place, Country" for a hotel's location line on search results — the
 * providers' offers name only the resort/city.
 */
final class LocationLine
{
    /** The place plus the country, unless the place already ends with it. */
    public static function placeAndCountry(string $place, string $country): string
    {
        $place = trim($place);
        $country = trim($country);
        if ($country === '') {
            return $place;
        }
        if ($place === '') {
            return $country;
        }
        if (mb_strtolower(mb_substr($place, -mb_strlen($country))) === mb_strtolower($country)) {
            return $place;
        }

        return $place . ', ' . $country;
    }
}
