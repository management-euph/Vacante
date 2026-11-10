<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Services;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * The package modes of one Eurosite hotel's page (board 16.2): how the hotel
 * can be reached ("Cu autocarul", "Cu avionul"), from which cities, on which
 * dates and for how many nights.
 *
 * Read from the package routes (?:eurosite_package_routes, the weekly
 * package_routes job): a route goes to a destination city, so the hotel's
 * city is looked up. No API call: the page knows its modes at once. Only a
 * hotel the store sells (listed, with a product) has any.
 *
 * Eurosite's routes give dates but no length of stay: each date offers the
 * stays the store chose ("Packages: nights offered").
 *
 * The hotel page's bar knows a departure city by a positive number (Travel
 * Core's PackageModesProviderInterface; PackageBar.jsx parses it as one);
 * Eurosite's is a code ("ROBCH1"), so its number is the code's CRC32
 * (departureId()), the same on every request and every page.
 */
final class PackageDepartures
{
    /** Eurosite's transport => the hotel page's, in the page's order: the coach, then the plane. */
    public const array TRANSPORTS = ['bus' => 'bus', 'plane' => 'flight'];

    /**
     * The hotel's package modes: per transport, the departure cities (most
     * dates first), each with its dates from $today on and their nights.
     * [] when the hotel has none (only accommodation) or is not sold.
     *
     * @param list<int> $nights the stays offered on each date
     *
     * @return list<array{transport: string, departures: list<array{id: int, code: string, country_code: string, name: string, dates: list<array{date: string, nights: list<int>}>}>}>
     */
    public static function forHotel(string $productCode, string $today, array $nights): array
    {
        $cityCode = TypeCoerce::toString(db_get_field(
            "SELECT city_code FROM ?:eurosite_hotels
             WHERE product_code = ?s AND sync_status = 'active' AND product_id > 0
             LIMIT 1",
            $productCode,
        ));
        if ($cityCode === '') {
            return [];
        }

        return self::group(TypeCoerce::toRowList(db_get_array(
            'SELECT transport, dep_city_code, dep_country_code, dep_city_name, dates_json
             FROM ?:eurosite_package_routes WHERE city_code = ?s',
            $cityCode,
        )), $today, $nights);
    }

    /**
     * A departure city's number on the hotel's page.
     */
    public static function departureId(string $code): int
    {
        $id = crc32(strtoupper(trim($code)));

        return $id > 0 ? $id : 1;
    }

    /**
     * The routes by transport and departure city: each city's dates from
     * $today on, in order, each with the stays offered; a city without a
     * date ahead is left out, and a transport without a city.
     *
     * @param list<array<string, mixed>> $routes rows of ?:eurosite_package_routes
     * @param list<int> $nights
     *
     * @return list<array{transport: string, departures: list<array{id: int, code: string, country_code: string, name: string, dates: list<array{date: string, nights: list<int>}>}>}>
     */
    public static function group(array $routes, string $today, array $nights): array
    {
        if ($nights === []) {
            return [];
        }
        /** @var array<string, array<int, array{code: string, country_code: string, name: string, dates: array<string, true>}>> $byTransport */
        $byTransport = [];
        foreach ($routes as $route) {
            $transport = self::TRANSPORTS[TypeCoerce::toString($route['transport'] ?? '')] ?? '';
            $code = strtoupper(TypeCoerce::toString($route['dep_city_code'] ?? ''));
            if ($transport === '' || $code === '') {
                continue;
            }
            $decoded = json_decode(TypeCoerce::toString($route['dates_json'] ?? '[]'), true);
            foreach (TypeCoerce::toStringList(is_array($decoded) ? $decoded : []) as $date) {
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || $date < $today) {
                    continue;
                }
                $id = self::departureId($code);
                $byTransport[$transport][$id] ??= [
                    'code' => $code,
                    'country_code' => strtoupper(TypeCoerce::toString($route['dep_country_code'] ?? '')),
                    'name' => TypeCoerce::toString($route['dep_city_name'] ?? ''),
                    'dates' => [],
                ];
                $byTransport[$transport][$id]['dates'][$date] = true;
            }
        }

        $modes = [];
        foreach (self::TRANSPORTS as $transport) {
            if (!isset($byTransport[$transport])) {
                continue;
            }
            $departures = [];
            foreach ($byTransport[$transport] as $id => $city) {
                $dates = array_keys($city['dates']);
                sort($dates);
                $departures[] = [
                    'id' => $id,
                    'code' => $city['code'],
                    'country_code' => $city['country_code'],
                    'name' => $city['name'],
                    'dates' => array_map(static fn (string $date): array => ['date' => $date, 'nights' => $nights], $dates),
                ];
            }
            usort($departures, static fn (array $a, array $b): int => [count($b['dates']), $a['name']] <=> [count($a['dates']), $b['name']]);
            $modes[] = ['transport' => $transport, 'departures' => $departures];
        }

        return $modes;
    }

    /**
     * The modes as the hotel page's config carries them: per transport, its
     * cities (the bar asks a city's dates when it is chosen).
     *
     * @param list<array{transport: string, departures: list<array{id: int, code: string, country_code: string, name: string, dates: list<array{date: string, nights: list<int>}>}>}> $modes forHotel()
     *
     * @return list<array{transport: string, departures: list<array{id: int, name: string}>}>
     */
    public static function cities(array $modes): array
    {
        return array_map(static fn (array $mode): array => [
            'transport' => $mode['transport'],
            'departures' => array_map(
                static fn (array $departure): array => ['id' => $departure['id'], 'name' => $departure['name'] !== '' ? $departure['name'] : $departure['code']],
                $mode['departures'],
            ),
        ], $modes);
    }

    /**
     * The dates of one transport from one city, each with its nights; []
     * when the hotel has none.
     *
     * @param list<array{transport: string, departures: list<array{id: int, code: string, country_code: string, name: string, dates: list<array{date: string, nights: list<int>}>}>}> $modes forHotel()
     *
     * @return list<array{date: string, nights: list<int>}>
     */
    public static function dates(array $modes, string $transport, int $departureId): array
    {
        return self::departure($modes, $transport, $departureId)['dates'] ?? [];
    }

    /**
     * The departure city a quote asks about, when the hotel has it on that
     * date for that many nights; null otherwise (the quote is not asked).
     *
     * @param list<array{transport: string, departures: list<array{id: int, code: string, country_code: string, name: string, dates: list<array{date: string, nights: list<int>}>}>}> $modes forHotel()
     *
     * @return array{id: int, code: string, country_code: string, name: string, dates: list<array{date: string, nights: list<int>}>}|null
     */
    public static function offered(array $modes, string $transport, int $departureId, string $date, int $nights): ?array
    {
        $departure = self::departure($modes, $transport, $departureId);
        foreach ($departure['dates'] ?? [] as $day) {
            if ($day['date'] === $date && in_array($nights, $day['nights'], true)) {
                return $departure;
            }
        }

        return null;
    }

    /**
     * @param list<array{transport: string, departures: list<array{id: int, code: string, country_code: string, name: string, dates: list<array{date: string, nights: list<int>}>}>}> $modes
     *
     * @return array{id: int, code: string, country_code: string, name: string, dates: list<array{date: string, nights: list<int>}>}|null
     */
    private static function departure(array $modes, string $transport, int $departureId): ?array
    {
        foreach ($modes as $mode) {
            if ($mode['transport'] !== $transport) {
                continue;
            }
            foreach ($mode['departures'] as $departure) {
                if ($departure['id'] === $departureId) {
                    return $departure;
                }
            }
        }

        return null;
    }
}
