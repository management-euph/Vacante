<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Repository;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Repository\RowNarrowingTrait;

/**
 * ?:eurosite_destination_whitelist — which countries/cities are enabled for
 * sync + storefront search (sphinx pattern, collapsed to codes since the
 * Eurosite tree is flat country → city).
 *
 * Row shapes:
 *   (country_code, city_code='', selection_type='all')       — every city, new ones included
 *   (country_code, city_code='', selection_type='own')       — every own-offer city, new ones included
 *   (country_code, city_code='', selection_type='specific')  — only the city rows below
 *   (country_code, city_code='XYZ', selection_type='specific') — one city
 *
 * Stores saved before the Destination whitelist page had no 'specific'
 * country row: a country with city rows alone is 'only selected' too.
 * Save replaces every row, so created_at is the time of the last Save.
 */
class DestinationWhitelistRepository
{
    use RowNarrowingTrait;

    /**
     * @return list<array<string, mixed>>
     */
    public function findAll(): array
    {
        return self::asRowList(db_get_array(
            'SELECT * FROM ?:eurosite_destination_whitelist ORDER BY country_code, city_code',
        ));
    }

    /**
     * Distinct whitelisted country codes (both row kinds count).
     *
     * @return list<string>
     */
    public function getCountryCodes(): array
    {
        $rows = db_get_fields('SELECT DISTINCT country_code FROM ?:eurosite_destination_whitelist');

        return array_values(array_filter(array_map(
            static fn ($v): string => trim(TypeCoerce::toString($v)),
            is_array($rows) ? $rows : [],
        ), static fn (string $v): bool => $v !== ''));
    }

    /**
     * City codes searchable for a country: every synced city when the country
     * has an 'all' row, else only its 'specific' city rows.
     *
     * @return list<string>
     */
    public function getAllowedCityCodes(string $countryCode): array
    {
        $type = TypeCoerce::toString(db_get_field(
            "SELECT selection_type FROM ?:eurosite_destination_whitelist
             WHERE country_code = ?s AND city_code = ''",
            $countryCode,
        ));

        $rows = match ($type) {
            'all' => db_get_fields('SELECT city_code FROM ?:eurosite_cities WHERE country_code = ?s', $countryCode),
            'own' => db_get_fields("SELECT city_code FROM ?:eurosite_cities WHERE country_code = ?s AND is_own = 'Y'", $countryCode),
            default => db_get_fields(
                "SELECT city_code FROM ?:eurosite_destination_whitelist WHERE country_code = ?s AND city_code <> ''",
                $countryCode,
            ),
        };

        return array_values(array_filter(array_map(
            static fn ($v): string => trim(TypeCoerce::toString($v)),
            is_array($rows) ? $rows : [],
        ), static fn (string $v): bool => $v !== ''));
    }

    public function isCityAllowed(string $countryCode, string $cityCode): bool
    {
        return in_array($cityCode, $this->getAllowedCityCodes($countryCode), true);
    }

    /**
     * Replace the whole whitelist atomically (the Destination whitelist Save).
     * A country row is 'all', 'own' or 'specific'; a city row is 'specific'.
     *
     * @param list<array{country_code: string, city_code?: string, selection_type?: string}> $entries
     */
    public function replaceAll(array $entries): void
    {
        db_query('START TRANSACTION');
        try {
            db_query('DELETE FROM ?:eurosite_destination_whitelist');
            foreach ($entries as $e) {
                $country = trim(TypeCoerce::toString($e['country_code']));
                if ($country === '') {
                    continue;
                }
                $city = trim(TypeCoerce::toString($e['city_code'] ?? ''));
                $type = TypeCoerce::toString($e['selection_type'] ?? '');
                if ($city !== '') {
                    $type = 'specific';
                } elseif (!in_array($type, ['all', 'own', 'specific'], true)) {
                    $type = 'all';
                }
                db_query(
                    'INSERT INTO ?:eurosite_destination_whitelist (country_code, city_code, selection_type)
                     VALUES (?s, ?s, ?s)
                     ON DUPLICATE KEY UPDATE selection_type = VALUES(selection_type)',
                    $country,
                    $city,
                    $type,
                );
            }
            db_query('COMMIT');
        } catch (\Throwable $e) {
            db_query('ROLLBACK');
            throw $e;
        }
    }

    public function count(): int
    {
        return TypeCoerce::toInt(db_get_field('SELECT COUNT(*) FROM ?:eurosite_destination_whitelist'));
    }

    /** When the whitelist was last saved ('' = never): cities first seen after it are new. */
    public function lastSavedAt(): string
    {
        return TypeCoerce::toString(db_get_field('SELECT MAX(created_at) FROM ?:eurosite_destination_whitelist'));
    }

    /**
     * Active products linked to a Eurosite hotel, with the hotel's place.
     *
     * @return list<array{product_id: int, hotel_name: string, country_code: string, city_code: string, city_name: string, country_name: string}>
     */
    public function liveProducts(): array
    {
        $out = [];
        foreach (self::asRowList(db_get_array(
            "SELECT h.product_id, h.name AS hotel_name, h.country_code, h.city_code,
                    COALESCE(c.name, '') AS city_name, COALESCE(co.name, '') AS country_name
             FROM ?:eurosite_hotels h
             INNER JOIN ?:products p ON p.product_id = h.product_id
             LEFT JOIN ?:eurosite_cities c ON c.city_code = h.city_code
             LEFT JOIN ?:eurosite_countries co ON co.country_code = h.country_code
             WHERE h.product_id > 0 AND p.status = 'A'
             ORDER BY h.country_code, h.city_code, h.name",
        )) as $row) {
            $out[] = [
                'product_id' => TypeCoerce::toInt($row['product_id'] ?? 0),
                'hotel_name' => TypeCoerce::toString($row['hotel_name'] ?? ''),
                'country_code' => TypeCoerce::toString($row['country_code'] ?? ''),
                'city_code' => TypeCoerce::toString($row['city_code'] ?? ''),
                'city_name' => TypeCoerce::toString($row['city_name'] ?? ''),
                'country_name' => TypeCoerce::toString($row['country_name'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Set products to Disabled ('D'). Never deletes: a product disabled by
     * mistake comes back with its URL, reviews and images by enabling it.
     *
     * @param list<int> $productIds
     */
    public function disableProducts(array $productIds): int
    {
        $productIds = array_values(array_filter($productIds, static fn (int $id): bool => $id > 0));
        if ($productIds === []) {
            return 0;
        }

        return TypeCoerce::toInt(db_query(
            "UPDATE ?:products SET status = 'D' WHERE product_id IN (?n) AND status = 'A'",
            $productIds,
        ));
    }
}
