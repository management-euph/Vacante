<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Repository;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Repository\RowNarrowingTrait;

/**
 * ?:eurosite_hotels — the operator's own hotels (getOwnHotelsRequest),
 * keyed (tourop_code, product_code). The per-row tourop_code — live rows
 * report "LA" — is the source of truth for search/booking payloads, NOT the
 * addon's tourop_code setting (spec-example "EU").
 */
class HotelRepository
{
    use RowNarrowingTrait;

    /**
     * @param list<array{code: string, name: string, city_code: string, tourop: string, rooms: array<string, string>}> $hotels
     */
    public function upsertBatch(array $hotels, string $countryCode = ''): int
    {
        if ($hotels === []) {
            return 0;
        }
        $now = date('Y-m-d H:i:s');
        $submitted = 0;
        foreach (array_chunk($hotels, 250) as $chunk) {
            $tuples = [];
            $params = [];
            foreach ($chunk as $h) {
                $code = trim(TypeCoerce::toString($h['code']));
                if ($code === '') {
                    continue;
                }
                $tuples[] = '(?s, ?s, ?s, ?s, ?s, ?s, ?s)';
                array_push(
                    $params,
                    trim(TypeCoerce::toString($h['tourop'])),
                    $code,
                    TypeCoerce::toString($h['name']),
                    trim(TypeCoerce::toString($h['city_code'])),
                    $countryCode,
                    (string) json_encode($h['rooms'], JSON_UNESCAPED_UNICODE),
                    $now,
                );
            }
            if ($tuples === []) {
                continue;
            }
            db_query(
                'INSERT INTO ?:eurosite_hotels
                    (tourop_code, product_code, name, city_code, country_code, rooms_json, last_synced_at)
                 VALUES ' . implode(', ', $tuples) . "
                 ON DUPLICATE KEY UPDATE
                    name = VALUES(name),
                    city_code = VALUES(city_code),
                    country_code = IF(VALUES(country_code) <> '', VALUES(country_code), country_code),
                    rooms_json = VALUES(rooms_json),
                    sync_status = 'active',
                    inactive_reason = '',
                    last_synced_at = VALUES(last_synced_at)",
                ...$params,
            );
            $submitted += count($tuples);
        }

        return $submitted;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getByCity(string $cityCode): array
    {
        return self::asRowList(db_get_array(
            "SELECT * FROM ?:eurosite_hotels WHERE city_code = ?s AND sync_status = 'active' ORDER BY name",
            $cityCode,
        ));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByProductCode(string $productCode): ?array
    {
        $row = self::asRow(db_get_row(
            'SELECT * FROM ?:eurosite_hotels WHERE product_code = ?s LIMIT 1',
            $productCode,
        ));

        return $row === [] ? null : $row;
    }

    /**
     * Distinct tourop codes present in a city's hotel rows ("LA" live) —
     * what search payloads should target.
     *
     * @return list<string>
     */
    public function getTouropCodesForCity(string $cityCode): array
    {
        $rows = db_get_fields(
            "SELECT DISTINCT tourop_code FROM ?:eurosite_hotels WHERE city_code = ?s AND sync_status = 'active'",
            $cityCode,
        );

        return array_values(array_filter(array_map(
            static fn ($v): string => trim(TypeCoerce::toString($v)),
            is_array($rows) ? $rows : [],
        ), static fn (string $v): bool => $v !== ''));
    }

    public function count(): int
    {
        return TypeCoerce::toInt(db_get_field('SELECT COUNT(*) FROM ?:eurosite_hotels'));
    }

    /**
     * Hide, and keep, every hotel whose destination is not whitelisted.
     *
     * The rows stay: when their destination is whitelisted again, the next
     * `hotels` sync upserts them back to active (clearing the reason), with
     * their product link and availability intact. Returns rows changed.
     *
     * @param list<string> $allowedCityCodes
     */
    public function deactivateOutside(array $allowedCityCodes): int
    {
        if ($allowedCityCodes === []) {
            return 0; // never "hide everything": an empty list means nothing is configured
        }

        return TypeCoerce::toInt(db_query(
            "UPDATE ?:eurosite_hotels SET sync_status = 'inactive', inactive_reason = 'not_whitelisted'
             WHERE city_code NOT IN (?a) AND sync_status = 'active'",
            $allowedCityCodes,
        ));
    }

    public function countActive(): int
    {
        return TypeCoerce::toInt(db_get_field("SELECT COUNT(*) FROM ?:eurosite_hotels WHERE sync_status = 'active'"));
    }

    /**
     * Hotels hidden because their destination is not whitelisted.
     *
     * @return array{hotels: int, destinations: int}
     */
    public function countNotWhitelisted(): array
    {
        $row = self::asRow(db_get_row(
            "SELECT COUNT(*) AS hotels, COUNT(DISTINCT city_code) AS destinations
             FROM ?:eurosite_hotels WHERE sync_status = 'inactive' AND inactive_reason = 'not_whitelisted'",
        ));

        return ['hotels' => TypeCoerce::toInt($row['hotels'] ?? 0), 'destinations' => TypeCoerce::toInt($row['destinations'] ?? 0)];
    }

    /**
     * Listed hotels per availability code ('' = not checked yet).
     *
     * @return array<string, int>
     */
    public function countByAvailability(): array
    {
        $out = ['IM' => 0, 'OR' => 0, 'ST' => 0, 'NONE' => 0, '' => 0];
        foreach (self::asRowList(db_get_array(
            "SELECT availability, COUNT(*) AS n FROM ?:eurosite_hotels WHERE sync_status = 'active' GROUP BY availability",
        )) as $row) {
            $code = TypeCoerce::toString($row['availability'] ?? '');
            $out[isset($out[$code]) ? $code : ''] += TypeCoerce::toInt($row['n'] ?? 0);
        }

        return $out;
    }

    /** Hotels linked to a CS-Cart product that still exists. */
    public function countProducts(): int
    {
        return TypeCoerce::toInt(db_get_field(
            'SELECT COUNT(*) FROM ?:eurosite_hotels h JOIN ?:products p ON p.product_id = h.product_id',
        ));
    }

    public function countGateHidden(): int
    {
        return TypeCoerce::toInt(db_get_field(
            "SELECT COUNT(*) FROM ?:eurosite_hotels WHERE gate_hidden = 'Y' AND product_id > 0",
        ));
    }

    /**
     * Listed hotels per destination, with the destination's name: the
     * hotel list's destination filter and the whitelist page's counts.
     *
     * @return list<array{city_code: string, country_code: string, name: string, hotels: int}>
     */
    public function countsByCity(): array
    {
        $out = [];
        foreach (self::asRowList(db_get_array(
            "SELECT h.city_code, h.country_code, COALESCE(c.name, '') AS name, COUNT(*) AS hotels
             FROM ?:eurosite_hotels h
             LEFT JOIN ?:eurosite_cities c ON c.city_code = h.city_code
             WHERE h.sync_status = 'active'
             GROUP BY h.city_code, h.country_code, c.name
             ORDER BY hotels DESC, name",
        )) as $row) {
            $out[] = [
                'city_code' => TypeCoerce::toString($row['city_code'] ?? ''),
                'country_code' => TypeCoerce::toString($row['country_code'] ?? ''),
                'name' => TypeCoerce::toString($row['name'] ?? ''),
                'hotels' => TypeCoerce::toInt($row['hotels'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * Synced hotels per destination, listed or not (the whitelist page shows
     * how many hotels a destination brings before it is whitelisted).
     *
     * @return array<string, int> city_code => hotels
     */
    public function countAllByCity(string $countryCode = ''): array
    {
        $out = [];
        foreach (self::asRowList(db_get_array(
            'SELECT city_code, COUNT(*) AS n FROM ?:eurosite_hotels WHERE 1 ?p GROUP BY city_code',
            $countryCode !== '' ? db_quote(' AND country_code = ?s', $countryCode) : '',
        )) as $row) {
            $out[TypeCoerce::toString($row['city_code'] ?? '')] = TypeCoerce::toInt($row['n'] ?? 0);
        }

        return $out;
    }

    /**
     * What the availability check asks about: one group per destination and
     * tour operator, since one price search covers one city for one operator.
     *
     * @return list<array{tourop_code: string, country_code: string, city_code: string, hotels: int}>
     */
    public function getAvailabilityTargets(string $onlyCity = ''): array
    {
        $out = [];
        foreach (self::asRowList(db_get_array(
            "SELECT tourop_code, country_code, city_code, COUNT(*) AS hotels
             FROM ?:eurosite_hotels
             WHERE sync_status = 'active' ?p
             GROUP BY tourop_code, country_code, city_code
             ORDER BY country_code, city_code",
            $onlyCity !== '' ? db_quote(' AND city_code = ?s', $onlyCity) : '',
        )) as $row) {
            $out[] = [
                'tourop_code' => TypeCoerce::toString($row['tourop_code'] ?? ''),
                'country_code' => TypeCoerce::toString($row['country_code'] ?? ''),
                'city_code' => TypeCoerce::toString($row['city_code'] ?? ''),
                'hotels' => TypeCoerce::toInt($row['hotels'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * Store what one destination's check found.
     *
     * Every listed hotel of the group gets an answer: the ones in $best get
     * their best offer, the rest are recorded as checked with no offer
     * (NONE), which is different from never checked (''). Offers for hotels
     * this store has not synced are counted, not stored.
     *
     * @param array<string, array<string, mixed>> $best product_code => AvailabilityPlan::merge() result
     *
     * @return array{IM: int, OR: int, ST: int, NONE: int, not_synced: int}
     */
    public function applyAvailability(string $tourop, string $cityCode, array $best, string $now): array
    {
        $codes = array_map(
            static fn ($v): string => TypeCoerce::toString($v),
            (array) db_get_fields(
                "SELECT product_code FROM ?:eurosite_hotels WHERE tourop_code = ?s AND city_code = ?s AND sync_status = 'active'",
                $tourop,
                $cityCode,
            ),
        );
        $counts = ['IM' => 0, 'OR' => 0, 'ST' => 0, 'NONE' => 0, 'not_synced' => 0];
        $local = array_flip($codes);
        foreach (array_keys($best) as $code) {
            if (!isset($local[(string) $code])) {
                $counts['not_synced']++;
            }
        }

        $none = [];
        foreach ($codes as $code) {
            $b = $best[$code] ?? null;
            if ($b === null) {
                $none[] = $code;
                continue;
            }
            $availability = TypeCoerce::toString($b['availability'] ?? 'NONE');
            $counts[isset($counts[$availability]) ? $availability : 'NONE']++;
            db_query(
                'UPDATE ?:eurosite_hotels SET
                    availability = ?s, availability_check_in = ?s, availability_window = ?s,
                    availability_checked_at = ?s, min_price = ?d, min_gross = ?d, price_currency = ?s,
                    category = IF(?i > 0, ?i, category),
                    first_image = IF(?s <> \'\', ?s, first_image)
                 WHERE tourop_code = ?s AND product_code = ?s',
                $availability,
                TypeCoerce::toString($b['check_in'] ?? ''),
                TypeCoerce::toString($b['window'] ?? ''),
                $now,
                TypeCoerce::toFloat($b['min_price'] ?? 0),
                TypeCoerce::toFloat($b['min_gross'] ?? 0),
                TypeCoerce::toString($b['currency'] ?? ''),
                TypeCoerce::toInt($b['category'] ?? 0),
                TypeCoerce::toInt($b['category'] ?? 0),
                TypeCoerce::toString($b['first_image'] ?? ''),
                TypeCoerce::toString($b['first_image'] ?? ''),
                $tourop,
                $code,
            );
        }
        if ($none !== []) {
            $counts['NONE'] += count($none);
            db_query(
                "UPDATE ?:eurosite_hotels SET
                    availability = 'NONE', availability_check_in = NULL, availability_window = '',
                    availability_checked_at = ?s, min_price = 0, min_gross = 0
                 WHERE tourop_code = ?s AND product_code IN (?a)",
                $now,
                $tourop,
                $none,
            );
        }

        return $counts;
    }

    /**
     * One hotel with what the product step needs from the details cache.
     *
     * @return array<string, mixed>|null
     */
    public function findWithDetails(string $tourop, string $productCode): ?array
    {
        $row = self::asRow(db_get_row(
            self::DETAILS_SELECT . ' WHERE h.tourop_code = ?s AND h.product_code = ?s',
            $tourop,
            $productCode,
        ));

        return $row === [] ? null : $row;
    }

    /**
     * Listed Immediate hotels that are not products yet (add_products).
     *
     * @return list<array<string, mixed>>
     */
    public function getProductCandidates(string $onlyCity = '', int $limit = 0): array
    {
        return self::asRowList(db_get_array(
            self::DETAILS_SELECT
            . " WHERE h.sync_status = 'active' AND h.availability = 'IM'
                AND (h.product_id IS NULL OR h.product_id = 0) ?p
              ORDER BY h.country_code, h.city_code, h.name ?p",
            $onlyCity !== '' ? db_quote(' AND h.city_code = ?s', $onlyCity) : '',
            $limit > 0 ? db_quote(' LIMIT ?i', $limit) : '',
        ));
    }

    /**
     * Hotels that are products (update_products): least recently refreshed first.
     *
     * @return list<array<string, mixed>>
     */
    public function getLinked(string $onlyCity = '', int $limit = 0): array
    {
        return self::asRowList(db_get_array(
            self::DETAILS_SELECT
            . ' WHERE h.product_id > 0 ?p
              ORDER BY (h.product_updated_at IS NULL) DESC, h.product_updated_at, h.name ?p',
            $onlyCity !== '' ? db_quote(' AND h.city_code = ?s', $onlyCity) : '',
            $limit > 0 ? db_quote(' LIMIT ?i', $limit) : '',
        ));
    }

    /**
     * Hotels that are products, for "Apply templates now" on the SEO
     * Templates page: fn_travel_core_seo_bulk_apply() pages through them and
     * reads product_id, hotel_id (the product code, for a unique URL) and name.
     *
     * @return list<array<string, mixed>>
     */
    public function linkedBatchForSeo(int $offset, int $batch): array
    {
        return self::asRowList(db_get_array(
            self::SEO_SELECT . ' WHERE h.product_id > 0 ORDER BY h.tourop_code, h.product_code LIMIT ?i, ?i',
            max(0, $offset),
            max(1, $batch),
        ));
    }

    /**
     * One hotel for the SEO Templates preview: a product if there is one,
     * else any listed hotel.
     *
     * @return array<string, mixed>|null
     */
    public function sampleForSeo(): ?array
    {
        $row = self::asRow(db_get_row(
            self::SEO_SELECT . " WHERE h.sync_status = 'active'
              ORDER BY (h.product_id > 0) DESC, (c.description IS NULL), h.name LIMIT 1",
        ));

        return $row === [] ? null : $row;
    }

    /**
     * Selected hotels by "TOUROP:CODE" key (the hotel list's checkboxes).
     *
     * @param list<string> $keys
     *
     * @return list<array<string, mixed>>
     */
    public function findManyWithDetails(array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            [$tourop, $code] = array_pad(explode(':', $key, 2), 2, '');
            if ($tourop === '' || $code === '') {
                continue;
            }
            $row = $this->findWithDetails($tourop, $code);
            if ($row !== null) {
                $out[] = $row;
            }
        }

        return $out;
    }

    public function linkProduct(string $tourop, string $productCode, int $productId): void
    {
        db_query(
            "UPDATE ?:eurosite_hotels SET product_id = ?i, product_skip_reason = '', product_updated_at = ?s
             WHERE tourop_code = ?s AND product_code = ?s",
            $productId,
            date('Y-m-d H:i:s'),
            $tourop,
            $productCode,
        );
    }

    public function setSkipReason(string $tourop, string $productCode, string $reason): void
    {
        db_query(
            'UPDATE ?:eurosite_hotels SET product_skip_reason = ?s WHERE tourop_code = ?s AND product_code = ?s',
            $reason,
            $tourop,
            $productCode,
        );
    }

    public function touchProductUpdated(string $tourop, string $productCode): void
    {
        db_query(
            'UPDATE ?:eurosite_hotels SET product_updated_at = ?s WHERE tourop_code = ?s AND product_code = ?s',
            date('Y-m-d H:i:s'),
            $tourop,
            $productCode,
        );
    }

    /**
     * The hotel a CS-Cart product was made from.
     *
     * @return array<string, mixed>|null
     */
    public function findByProductId(int $productId): ?array
    {
        if ($productId <= 0) {
            return null;
        }
        $row = self::asRow(db_get_row(self::DETAILS_SELECT . ' WHERE h.product_id = ?i LIMIT 1', $productId));

        return $row === [] ? null : $row;
    }

    /** Hotel row + its cached details (pictures, description, payload for the coordinates) + names. */
    private const DETAILS_SELECT = "SELECT h.*,
            c.pictures_json, c.description, c.payload_json, c.fetched_at AS info_fetched_at,
            COALESCE(ci.name, '') AS city_name, COALESCE(co.name, '') AS country_name
        FROM ?:eurosite_hotels h
        LEFT JOIN ?:eurosite_product_info_cache c ON c.tourop_code = h.tourop_code AND c.product_code = h.product_code
        LEFT JOIN ?:eurosite_cities ci ON ci.city_code = h.city_code
        LEFT JOIN ?:eurosite_countries co ON co.country_code = h.country_code";

    /** DETAILS_SELECT plus the product code as hotel_id (a unique URL suffix when two slugs clash). */
    private const SEO_SELECT = "SELECT h.*,
            c.pictures_json, c.description, c.payload_json,
            CONCAT('EUS-', h.tourop_code, '-', h.product_code) AS hotel_id,
            COALESCE(ci.name, '') AS city_name, COALESCE(co.name, '') AS country_name
        FROM ?:eurosite_hotels h
        LEFT JOIN ?:eurosite_product_info_cache c ON c.tourop_code = h.tourop_code AND c.product_code = h.product_code
        LEFT JOIN ?:eurosite_cities ci ON ci.city_code = h.city_code
        LEFT JOIN ?:eurosite_countries co ON co.country_code = h.country_code";

    public function getLastSyncedAt(): string
    {
        return TypeCoerce::toString(db_get_field('SELECT MAX(last_synced_at) FROM ?:eurosite_hotels'));
    }
}
