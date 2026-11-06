<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Services;

use Tygh\Addons\TravelCore\Dto\Hotel\HotelSeoData;

/**
 * Builds the human-readable location line shown under the hotel name on the
 * product page. The rule is keyed on DATA, not provider: when a street
 * address exists the postal style wins — "street, city, country" (sphinx
 * hotels carry the API address); otherwise the destination style —
 * "city, region, country" (novoton's street comes from geocoding, when run).
 *
 * Provider data is messy, so the line is normalised for display:
 *  - street: newlines become comma separators, whitespace collapses, and
 *    place names the line already shows are de-duplicated out of it — both
 *    as comma segments ("Sakip Sabancibulvari\r\nTURKEY, ANTALYA" keeps only
 *    the street) and as a trailing "/ City" suffix ("… Konyaaltı / Antalya").
 *    Segment matching is by EQUALITY, so "Antalya Caddesi 12" survives.
 *  - casing: SHOUTING is fixed without damaging already-correct values.
 *    City/region/country are re-cased only when single-case (GOLEM → Golem,
 *    but "Palma de Mallorca" passes through); inside the street only
 *    ALL-CAPS words are re-cased ("LIMAN MAH." → "Liman Mah.", while
 *    "Rruga e Plazhit", "Konyaaltı" and "4A/4B" stay untouched).
 *  - repeats: parts equal to an earlier part vanish (city == region data).
 *
 * @since 1.5.0
 */
final class HotelLocationLine
{
    public static function build(HotelSeoData $hotel): string
    {
        $city = self::caseLabel(trim((string) ($hotel->city ?? '')));
        $region = self::caseLabel(trim((string) ($hotel->region ?? '')));
        $country = self::caseLabel(trim((string) ($hotel->country ?? '')));
        $street = self::sanitizeStreet((string) ($hotel->address ?? ''), [$city, $region, $country]);

        $parts = $street !== ''
            ? [$street, $city, $country]
            : [$city, $region, $country];

        $out = [];
        $seen = [];
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $key = mb_strtolower($part, 'UTF-8');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $part;
        }

        return implode(', ', $out);
    }

    /**
     * The destination style without the street, split for the hotel list
     * card ("Mamaia, Constanța · România"): the places under the country
     * (city, region) and the country, cased and de-duplicated as build()
     * does. A street never shows on a list card.
     *
     * @param HotelSeoData $hotel the supplier's hotel
     *
     * @return array{places: list<string>, country: string}
     */
    public static function destination(HotelSeoData $hotel): array
    {
        $country = self::caseLabel(trim((string) ($hotel->country ?? '')));
        $seen = $country !== '' ? [mb_strtolower($country, 'UTF-8') => true] : [];
        $places = [];
        foreach ([$hotel->city, $hotel->region] as $raw) {
            $part = self::caseLabel(trim((string) ($raw ?? '')));
            $key = mb_strtolower($part, 'UTF-8');
            if ($part === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $places[] = $part;
        }

        return ['places' => $places, 'country' => $country];
    }

    /**
     * Re-case a place label ONLY when it is single-case (all-upper or
     * all-lower); mixed-case values are someone's deliberate spelling
     * ("Palma de Mallorca", "Antalya City") and pass through untouched.
     */
    private static function caseLabel(string $value): string
    {
        if ($value === '') {
            return '';
        }
        if ($value === mb_strtoupper($value, 'UTF-8') || $value === mb_strtolower($value, 'UTF-8')) {
            return mb_convert_case(mb_strtolower($value, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
        }

        return $value;
    }

    /**
     * Normalise a raw provider street string for display next to the given
     * place names (city/region/country — already display-cased).
     *
     * @param list<string> $knownPlaces
     */
    private static function sanitizeStreet(string $street, array $knownPlaces): string
    {
        $street = str_replace(["\r\n", "\r", "\n"], ', ', $street);
        $street = (string) preg_replace('/\s+/u', ' ', $street);
        $street = (string) preg_replace('/\s*,[\s,]*/u', ', ', $street);
        $street = trim($street, ' ,/');
        if ($street === '') {
            return '';
        }

        $places = [];
        foreach ($knownPlaces as $place) {
            if ($place !== '') {
                $places[] = $place;
            }
        }

        // Trailing "/ City" (or "/ Region", "/ Country") suffixes — exact
        // trailing match only, so slashes inside house numbers (4A/4B) are safe.
        $changed = true;
        while ($changed && $street !== '') {
            $changed = false;
            foreach ($places as $place) {
                $stripped = (string) preg_replace(
                    '#\s*/\s*' . preg_quote($place, '#') . '\s*$#iu',
                    '',
                    $street,
                );
                if ($stripped !== $street) {
                    $street = trim($stripped, ' ,/');
                    $changed = true;
                }
            }
        }

        // Comma segments that ARE a known place (the street carrying its own
        // "TURKEY, ANTALYA" tail) — equality, not containment.
        $kept = [];
        foreach (explode(',', $street) as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }
            foreach ($places as $place) {
                if (mb_strtolower($segment, 'UTF-8') === mb_strtolower($place, 'UTF-8')) {
                    continue 2;
                }
            }
            $kept[] = $segment;
        }
        $street = implode(', ', $kept);

        // Fix SHOUTING words only (2+ consecutive capitals); mixed-case and
        // lowercase tokens are deliberate spelling and stay untouched.
        $street = (string) preg_replace_callback(
            '/\b\p{Lu}{2,}\b/u',
            static fn (array $m): string => mb_convert_case(mb_strtolower($m[0], 'UTF-8'), MB_CASE_TITLE, 'UTF-8'),
            $street,
        );

        return trim($street, ' ,/');
    }
}
