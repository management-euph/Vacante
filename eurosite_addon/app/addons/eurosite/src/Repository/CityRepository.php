<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Repository;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Repository\RowNarrowingTrait;

/**
 * ?:eurosite_cities — the getCityRequest catalog, with `is_own` marking the
 * rows that also appear in getOwnCityResponse (cities carrying the
 * operator's own offers — the searchable subset that matters most).
 */
class CityRepository
{
    use RowNarrowingTrait;

    /**
     * @param list<array{code: string, name: string, country_code?: string}> $cities
     */
    public function upsertBatch(array $cities, string $countryCode = '', bool $markOwn = false): int
    {
        if ($cities === []) {
            return 0;
        }
        $now = date('Y-m-d H:i:s');
        $own = $markOwn ? 'Y' : 'N';
        $submitted = 0;
        foreach (array_chunk($cities, 500) as $chunk) {
            $tuples = [];
            $params = [];
            foreach ($chunk as $c) {
                $code = trim(TypeCoerce::toString($c['code']));
                if ($code === '') {
                    continue;
                }
                $tuples[] = '(?s, ?s, ?s, ?s, ?s, ?s)';
                array_push(
                    $params,
                    $code,
                    trim(TypeCoerce::toString($c['country_code'] ?? '')) ?: $countryCode,
                    TypeCoerce::toString($c['name']),
                    $own,
                    $now,
                    $now,
                );
            }
            if ($tuples === []) {
                continue;
            }
            // An own-cities pass must never demote is_own back to N for rows
            // the plain cities pass touches afterwards — hence the IF().
            // first_seen_at is written once, on the insert: the whitelist
            // page calls a city new when it appeared after the last Save.
            db_query(
                'INSERT INTO ?:eurosite_cities (city_code, country_code, name, is_own, first_seen_at, last_synced_at)
                 VALUES ' . implode(', ', $tuples) . "
                 ON DUPLICATE KEY UPDATE
                    country_code = IF(VALUES(country_code) <> '', VALUES(country_code), country_code),
                    name = VALUES(name),
                    is_own = IF(VALUES(is_own) = 'Y', 'Y', is_own),
                    last_synced_at = VALUES(last_synced_at)",
                ...$params,
            );
            $submitted += count($tuples);
        }

        return $submitted;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByCode(string $cityCode): ?array
    {
        $row = self::asRow(db_get_row('SELECT * FROM ?:eurosite_cities WHERE city_code = ?s', $cityCode));

        return $row === [] ? null : $row;
    }

    /**
     * Country codes that have at least one own-offer city.
     *
     * @return list<string>
     */
    public function getOwnCountryCodes(): array
    {
        $rows = db_get_fields("SELECT DISTINCT country_code FROM ?:eurosite_cities WHERE is_own = 'Y' AND country_code <> ''");

        return array_values(array_map(static fn ($v): string => TypeCoerce::toString($v), is_array($rows) ? $rows : []));
    }

    /**
     * Own-offer cities per country: the whitelist's "own" count and its
     * "Show only destinations with own hotels" filter.
     *
     * @return array<string, int> country_code => own-offer cities
     */
    public function ownCountsByCountry(): array
    {
        $out = [];
        foreach (self::asRowList(db_get_array(
            "SELECT country_code, COUNT(*) AS n FROM ?:eurosite_cities
             WHERE is_own = 'Y' AND country_code <> '' GROUP BY country_code",
        )) as $row) {
            $out[TypeCoerce::toString($row['country_code'] ?? '')] = TypeCoerce::toInt($row['n'] ?? 0);
        }

        return $out;
    }

    /**
     * Every synced city (or one country's) with what selling it means, for
     * the Destination whitelist page: hotels (active or not), hotels with a
     * price, with an Immediate offer, and live products. One grouped join.
     *
     * @return list<array{city_code: string, country_code: string, name: string, is_own: bool, first_seen_at: string, last_synced_at: string, hotels: int, priced: int, instant: int, live: int}>
     */
    public function pickerRows(string $countryCode = ''): array
    {
        $out = [];
        foreach (self::asRowList(db_get_array(
            "SELECT c.city_code, c.country_code, c.name, c.is_own, c.first_seen_at, c.last_synced_at,
                    COALESCE(h.hotels, 0) AS hotels, COALESCE(h.priced, 0) AS priced,
                    COALESCE(h.instant, 0) AS instant, COALESCE(h.live, 0) AS live
             FROM ?:eurosite_cities c
             LEFT JOIN (
                 SELECT hh.city_code,
                        COUNT(*) AS hotels,
                        SUM(CASE WHEN hh.min_price > 0 THEN 1 ELSE 0 END) AS priced,
                        SUM(CASE WHEN hh.availability = 'IM' THEN 1 ELSE 0 END) AS instant,
                        SUM(CASE WHEN p.status = 'A' THEN 1 ELSE 0 END) AS live
                 FROM ?:eurosite_hotels hh
                 LEFT JOIN ?:products p ON p.product_id = hh.product_id AND hh.product_id > 0
                 GROUP BY hh.city_code
             ) h ON h.city_code = c.city_code
             WHERE c.country_code <> '' ?p
             ORDER BY c.country_code, c.name",
            $countryCode === '' ? '' : db_quote('AND c.country_code = ?s', $countryCode),
        )) as $row) {
            $out[] = [
                'city_code' => TypeCoerce::toString($row['city_code'] ?? ''),
                'country_code' => TypeCoerce::toString($row['country_code'] ?? ''),
                'name' => TypeCoerce::toString($row['name'] ?? ''),
                'is_own' => TypeCoerce::toString($row['is_own'] ?? 'N') === 'Y',
                'first_seen_at' => TypeCoerce::toString($row['first_seen_at'] ?? ''),
                'last_synced_at' => TypeCoerce::toString($row['last_synced_at'] ?? ''),
                'hotels' => TypeCoerce::toInt($row['hotels'] ?? 0),
                'priced' => TypeCoerce::toInt($row['priced'] ?? 0),
                'instant' => TypeCoerce::toInt($row['instant'] ?? 0),
                'live' => TypeCoerce::toInt($row['live'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * Name/code lookup for the whitelist search box; carries the country name
     * so the result line can read "Mamaia — Romania".
     *
     * The code is matched as well as the name, deliberately: a catalog synced
     * before a name column was populated holds rows whose name is empty, and a
     * name-only search returns nothing at all for them.
     *
     * @return list<array<string, mixed>>
     */
    public function search(string $query, int $limit = 20): array
    {
        return self::asRowList(db_get_array(
            'SELECT c.city_code, c.name, c.country_code, c.is_own, co.name AS country_name
             FROM ?:eurosite_cities AS c
             LEFT JOIN ?:eurosite_countries AS co ON co.country_code = c.country_code
             WHERE c.name LIKE ?l OR c.city_code LIKE ?l
             ORDER BY c.is_own DESC, c.name LIMIT ?i',
            "%{$query}%",
            "%{$query}%",
            $limit,
        ));
    }

    public function count(bool $ownOnly = false): int
    {
        return TypeCoerce::toInt(db_get_field(
            'SELECT COUNT(*) FROM ?:eurosite_cities ?p',
            $ownOnly ? "WHERE is_own = 'Y'" : '',
        ));
    }

    public function getLastSyncedAt(): string
    {
        return TypeCoerce::toString(db_get_field('SELECT MAX(last_synced_at) FROM ?:eurosite_cities'));
    }
}
