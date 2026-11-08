<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Services;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * The package modes of one hotel's page (board 16.2): how the hotel can be
 * reached with transport ("Cu autocarul", "Cu avionul"), from which cities,
 * on which dates and for how many nights.
 *
 * Read from the saved routes (static/package-routes → ?:sphinx_package_routes,
 * cron-synced): a route goes to a destination, which is the hotel's own or
 * one above it (city, region, country), so the hotel's destination and its
 * parents are looked up. No API call: the page knows its modes at once.
 *
 * Each route row is one transport, one departure city, one destination and
 * one length; its dates are the days it leaves. A city's dates are those of
 * all its routes to the hotel, each date with the lengths it has.
 */
final class PackageDepartures
{
    /** The transports a package can have (packages/search "transport"). */
    public const array TRANSPORTS = ['bus', 'flight'];

    /** How far up the destination tree a route may go to (a loop guard). */
    private const int MAX_DEPTH = 12;

    /**
     * The hotel's package modes: for each transport it has, the departure
     * cities (most dates first), each with its dates from $today on and the
     * nights of each date. [] when the hotel has none (only accommodation).
     *
     * @return list<array{transport: string, departures: list<array{id: int, name: string, dates: list<array{date: string, nights: list<int>}>}>}>
     */
    public static function forHotel(string $hotelId, string $today): array
    {
        $destinationId = TypeCoerce::toInt(db_get_field(
            "SELECT destination_id FROM ?:sphinx_hotels WHERE hotel_id = ?s AND sync_status = 'active'",
            $hotelId,
        ));
        $chain = self::chain($destinationId);
        if ($chain === []) {
            return [];
        }

        return self::group(TypeCoerce::toRowList(db_get_array(
            'SELECT transport_type, departure_id, departure_name, available_dates, duration
             FROM ?:sphinx_package_routes WHERE arrival_id IN (?n)',
            $chain,
        )), $today);
    }

    /**
     * Whether $modes have this departure: the transport, the city (0: any
     * of them), the date and its length.
     *
     * @param list<array{transport: string, departures: list<array{id: int, name: string, dates: list<array{date: string, nights: list<int>}>}>}> $modes forHotel()
     */
    public static function offers(array $modes, string $transport, int $departureId, string $date, int $nights): bool
    {
        foreach ($modes as $mode) {
            if ($mode['transport'] !== $transport) {
                continue;
            }
            foreach ($mode['departures'] as $departure) {
                if ($departureId > 0 && $departure['id'] !== $departureId) {
                    continue;
                }
                foreach ($departure['dates'] as $day) {
                    if ($day['date'] === $date && in_array($nights, $day['nights'], true)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * A destination and the ones above it, nearest first.
     *
     * @return list<int>
     */
    public static function chain(int $destinationId): array
    {
        $chain = [];
        while ($destinationId > 0 && !in_array($destinationId, $chain, true) && count($chain) < self::MAX_DEPTH) {
            $chain[] = $destinationId;
            $destinationId = TypeCoerce::toInt(db_get_field(
                'SELECT parent_id FROM ?:sphinx_destinations WHERE destination_id = ?i',
                $destinationId,
            ));
        }

        return $chain;
    }

    /**
     * The routes, by transport and departure city: each city's dates from
     * $today on, sorted, each with its lengths; a city without a date ahead
     * is left out, and a transport without a city.
     *
     * @param list<array<string, mixed>> $routes rows of ?:sphinx_package_routes
     *
     * @return list<array{transport: string, departures: list<array{id: int, name: string, dates: list<array{date: string, nights: list<int>}>}>}>
     */
    public static function group(array $routes, string $today): array
    {
        /** @var array<string, array<int, array{name: string, dates: array<string, array<int, true>>}>> $byTransport */
        $byTransport = [];
        foreach ($routes as $route) {
            $transport = TypeCoerce::toString($route['transport_type'] ?? '');
            $departureId = TypeCoerce::toInt($route['departure_id'] ?? 0);
            $nights = TypeCoerce::toInt($route['duration'] ?? 0);
            if (!in_array($transport, self::TRANSPORTS, true) || $departureId <= 0 || $nights <= 0) {
                continue;
            }
            $decoded = json_decode(TypeCoerce::toString($route['available_dates'] ?? '[]'), true);
            foreach (TypeCoerce::toStringList(is_array($decoded) ? $decoded : []) as $date) {
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || $date < $today) {
                    continue;
                }
                $byTransport[$transport][$departureId] ??= ['name' => TypeCoerce::toString($route['departure_name'] ?? ''), 'dates' => []];
                $byTransport[$transport][$departureId]['dates'][$date][$nights] = true;
            }
        }

        $modes = [];
        foreach (self::TRANSPORTS as $transport) {
            if (!isset($byTransport[$transport])) {
                continue;
            }
            $departures = [];
            foreach ($byTransport[$transport] as $departureId => $city) {
                ksort($city['dates']);
                $dates = [];
                foreach ($city['dates'] as $date => $lengths) {
                    $nights = array_keys($lengths);
                    sort($nights);
                    $dates[] = ['date' => (string) $date, 'nights' => $nights];
                }
                $departures[] = ['id' => $departureId, 'name' => $city['name'], 'dates' => $dates];
            }
            usort($departures, static fn (array $a, array $b): int => [count($b['dates']), $a['name']] <=> [count($a['dates']), $b['name']]);
            $modes[] = ['transport' => $transport, 'departures' => $departures];
        }

        return $modes;
    }
}
