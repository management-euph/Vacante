<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Services;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Everything the Eurosite → Package routes page shows, pre-formatted (Smarty
 * 5 modifiers throw inside the admin capture): each route's transport, where
 * it leaves from, where it goes, the stays offered and the departure dates
 * still ahead.
 *
 * Eurosite's routes carry departure dates but no length of stay, so the
 * page shows the stays the store offers ("Packages: nights offered").
 */
final class PackageRoutesView
{
    /** Dates a row lists before "+N more". */
    public const int DATES_SHOWN = 6;

    /**
     * @param list<array<string, mixed>> $rows PackageRouteListing rows
     * @param list<int> $nights the stays offered (ConfigProvider::getPackageNights)
     * @param string $today Y-m-d: earlier departures are gone
     *
     * @return list<array{transport: string, departure: string, departure_code: string, arrival: string, arrival_code: string, arrival_place: string, nights: string, dates: string, more_dates: int, last_synced_at: string}>
     */
    public static function rows(array $rows, array $nights, string $today): array
    {
        $out = [];
        foreach ($rows as $row) {
            $transport = TypeCoerce::toString($row['transport'] ?? '') === 'bus' ? 'bus' : 'plane';
            $city = TypeCoerce::toString($row['city_name'] ?? '');
            $zone = TypeCoerce::toString($row['zone_name'] ?? '');
            $country = TypeCoerce::toString($row['country_name'] ?? '');
            $ahead = self::datesAhead(TypeCoerce::toString($row['dates_json'] ?? ''), $today);
            $out[] = [
                'transport' => $transport,
                'departure' => TypeCoerce::toString($row['dep_city_name'] ?? ''),
                'departure_code' => TypeCoerce::toString($row['dep_city_code'] ?? ''),
                'arrival' => $city,
                'arrival_code' => TypeCoerce::toString($row['city_code'] ?? ''),
                // "Antalya · Turkey": the zone when it is not the city itself, then the country.
                'arrival_place' => implode(' · ', array_filter(
                    [strcasecmp($zone, $city) === 0 ? '' : $zone, $country],
                    static fn (string $part): bool => $part !== '',
                )),
                'nights' => implode(', ', $nights),
                // "5 Jun, 12 Jun, 19 Jun": the next ones, '' when none is ahead.
                'dates' => implode(', ', array_map(
                    static fn (string $date): string => self::shortDate($date, $today),
                    array_slice($ahead, 0, self::DATES_SHOWN),
                )),
                'more_dates' => max(0, count($ahead) - self::DATES_SHOWN),
                'last_synced_at' => self::when(TypeCoerce::toString($row['last_synced_at'] ?? '')),
            ];
        }

        return $out;
    }

    /** "8 Nov 2026, 03:34", or '' for no time. */
    public static function when(string $at): string
    {
        $ts = $at !== '' && !str_starts_with($at, '0000') ? strtotime($at) : false;

        return $ts === false ? '' : date('j M Y, H:i', $ts);
    }

    /**
     * The departure dates from today on, in order.
     *
     * @return list<string>
     */
    private static function datesAhead(string $json, string $today): array
    {
        $dates = json_decode($json, true);
        $ahead = [];
        foreach (is_array($dates) ? $dates : [] as $date) {
            if (is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 && $date >= $today) {
                $ahead[] = $date;
            }
        }
        sort($ahead);

        return $ahead;
    }

    /** "5 Jun", with the year when it is not this year's: "5 Jun 2027". */
    private static function shortDate(string $date, string $today): string
    {
        $ts = strtotime($date);
        if ($ts === false) {
            return $date;
        }

        return date(substr($date, 0, 4) === substr($today, 0, 4) ? 'j M' : 'j M Y', $ts);
    }
}
