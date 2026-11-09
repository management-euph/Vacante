<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Repository;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * ?:eurosite_package_routes — where Eurosite's packages go, the cities they
 * leave from and on which dates (getPackageNVRoutesRequest, kept by the
 * weekly `package_routes` job, as the spec asks: fetch the routes now and
 * then and keep a local copy for the search).
 *
 * One row per transport ('plane', 'bus'), destination city and departure
 * city; its departure dates as a JSON list, the first and the last beside it.
 */
final class PackageRouteRepository
{
    /** Rows per INSERT. */
    private const int CHUNK = 200;

    /**
     * Replace one transport's routes with what Eurosite answered: write each
     * route, then drop the ones the answer no longer has. A route without a
     * single departure date is left out: there is nothing to book on it.
     *
     * @param list<array{transport: string, country_code: string, country_name: string, city_code: string, city_name: string, zone_code: string, zone_name: string, dep_country_code: string, dep_country_name: string, dep_city_code: string, dep_city_name: string, dates: list<string>}> $routes
     *
     * @return int the routes written
     */
    public function replaceTransport(string $transport, array $routes, string $now): int
    {
        $rows = [];
        foreach ($routes as $route) {
            if ($route['transport'] !== $transport || $route['dates'] === []) {
                continue;
            }
            $dates = $route['dates'];
            $rows[] = [
                $transport,
                $route['city_code'],
                $route['dep_city_code'],
                $route['country_code'],
                $route['country_name'],
                $route['city_name'],
                $route['zone_code'],
                $route['zone_name'],
                $route['dep_country_code'],
                $route['dep_country_name'],
                $route['dep_city_name'],
                (string) json_encode($dates),
                $dates[0],
                $dates[count($dates) - 1],
                count($dates),
                $now,
            ];
        }

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            db_query(
                'INSERT INTO ?:eurosite_package_routes
                    (transport, city_code, dep_city_code, country_code, country_name, city_name, zone_code, zone_name,
                     dep_country_code, dep_country_name, dep_city_name, dates_json, first_date, last_date, dates_count, last_synced_at)
                 VALUES ' . implode(', ', array_fill(0, count($chunk), '(?s, ?s, ?s, ?s, ?s, ?s, ?s, ?s, ?s, ?s, ?s, ?s, ?s, ?s, ?i, ?s)')) . '
                 ON DUPLICATE KEY UPDATE
                    country_code = VALUES(country_code), country_name = VALUES(country_name), city_name = VALUES(city_name),
                    zone_code = VALUES(zone_code), zone_name = VALUES(zone_name), dep_country_code = VALUES(dep_country_code),
                    dep_country_name = VALUES(dep_country_name), dep_city_name = VALUES(dep_city_name), dates_json = VALUES(dates_json),
                    first_date = VALUES(first_date), last_date = VALUES(last_date), dates_count = VALUES(dates_count),
                    last_synced_at = VALUES(last_synced_at)',
                ...array_merge(...$chunk),
            );
        }
        db_query(
            'DELETE FROM ?:eurosite_package_routes WHERE transport = ?s AND (last_synced_at IS NULL OR last_synced_at < ?s)',
            $transport,
            $now,
        );

        return count($rows);
    }

    public function count(): int
    {
        return TypeCoerce::toInt(db_get_field('SELECT COUNT(*) FROM ?:eurosite_package_routes'));
    }
}
