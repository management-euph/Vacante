<?php

declare(strict_types=1);

/**
 * Novoton Holidays - Hotel Search Repository
 *
 * Handles read-side hotel queries: listing, filtering, pagination,
 * sync-selection, and staleness-based retrieval.
 *
 * Extracted from HotelRepository (PR #3 of the architectural audit) to
 * give callers a focused 18-method contract instead of the 49-method
 * HotelRepository god object. SQL is preserved verbatim from the
 * original facade — this is a strict move-not-rewrite extraction.
 *
 * @package NovotonHolidays
 * @since   3.7.0
 */

namespace Tygh\Addons\NovotonHolidays\Repository;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Repository\RowNarrowingTrait;

class HotelSearchRepository implements HotelSearchRepositoryInterface
{
    use RowNarrowingTrait;

    /**
     * Core columns for hotel listing (excludes large hotel_data JSON).
     *
     * Duplicated from HotelRepository::LISTING_COLUMNS — the duplication
     * is intentional and temporary: the facade will be shrunk in a future
     * PR and the constant can then be centralised.
     */
    private const string LISTING_COLUMNS = 'hotel_id, product_id, hotel_name, city, region, country,
        hotel_type, star_rating, property_type, is_adults_only, latitude, longitude,
        has_room_price, packages_count, hotelinfo_synced_at, hotel_list_synced_at,
        last_price_check, created_at, updated_at';

    /**
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    #[\Override]
    public function findAll(array $filters = [], int $limit = 0, int $offset = 0): array
    {
        $where = $this->buildWhereClause($filters);
        $limit_clause = $limit > 0 ? db_quote(' LIMIT ?i, ?i', $offset, $limit) : '';

        return self::asRowList(db_get_array('SELECT ' . self::LISTING_COLUMNS . " FROM ?:novoton_hotels {$where} ORDER BY hotel_name {$limit_clause}"));
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    #[\Override]
    public function findAllForListing(array $filters = [], int $limit = 0, int $offset = 0): array
    {
        $where = $this->buildWhereClause($filters);
        $limit_clause = $limit > 0 ? db_quote(' LIMIT ?i, ?i', $offset, $limit) : '';

        return self::asRowList(db_get_array('SELECT ' . self::LISTING_COLUMNS . " FROM ?:novoton_hotels {$where} ORDER BY hotel_name {$limit_clause}"));
    }

    /**
     * @return list<array<string, mixed>>
     */
    #[\Override]
    public function findByCountry(string $country): array
    {
        return self::asRowList(db_get_array('SELECT ' . self::LISTING_COLUMNS . ' FROM ?:novoton_hotels WHERE country = ?s ORDER BY hotel_name', $country));
    }

    /**
     * @return list<array<string, mixed>>
     */
    #[\Override]
    public function findByCountryForListing(string $country): array
    {
        return self::asRowList(db_get_array(
            'SELECT ' . self::LISTING_COLUMNS . ' FROM ?:novoton_hotels WHERE country = ?s ORDER BY hotel_name',
            $country,
        ));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    #[\Override]
    public function findByCountryIndexed(string $country): array
    {
        return self::asRowMap(db_get_hash_array(
            'SELECT hotel_id, hotel_name, city, product_id, has_room_price FROM ?:novoton_hotels WHERE country = ?s ORDER BY hotel_name',
            'hotel_id',
            $country,
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    #[\Override]
    public function findByCountryWithLimit(string $country, int $limit = 0): array
    {
        $limit_clause = $limit > 0 ? db_quote(' LIMIT ?i', $limit) : '';
        return self::asRowList(db_get_array(
            "SELECT hotel_id, hotel_name, city, product_id FROM ?:novoton_hotels WHERE country = ?s ORDER BY hotel_name{$limit_clause}",
            $country,
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    #[\Override]
    public function findWithoutPackages(int $limit = 0): array
    {
        $limit_clause = $limit > 0 ? db_quote(' LIMIT ?i', $limit) : '';
        $cols = preg_replace('/\b(\w+)\b/', 'h.$1', self::LISTING_COLUMNS);

        return self::asRowList(db_get_array(
            "SELECT {$cols} FROM ?:novoton_hotels h
             LEFT JOIN ?:novoton_hotel_packages p ON h.hotel_id = p.hotel_id
             WHERE p.id IS NULL
             ORDER BY h.hotel_name {$limit_clause}",
        ));
    }

    /**
     * @param list<string> $excludeResorts
     * @param list<string>|null $onlyResorts null = every resort; a list = only these (the destination whitelist)
     * @return list<array<string, mixed>>
     */
    #[\Override]
    public function findUnlinkedWithPrices(string $country, array $excludeResorts = [], int $limit = 0, ?array $onlyResorts = null): array
    {
        $query = 'SELECT ' . self::LISTING_COLUMNS . " FROM ?:novoton_hotels
                  WHERE has_room_price = 'Y' AND country = ?s
                  AND (product_id IS NULL OR product_id = 0)";
        $params = [$country];

        if (!empty($excludeResorts)) {
            $query .= ' AND (city NOT IN (?a) OR city IS NULL)';
            $params[] = $excludeResorts;
        }

        if ($onlyResorts !== null) {
            if ($onlyResorts === []) {
                return [];
            }
            $query .= ' AND city IN (?a)';
            $params[] = $onlyResorts;
        }

        $query .= ' ORDER BY hotel_name';
        if ($limit > 0) {
            $query .= ' LIMIT ?i';
            $params[] = $limit;
        }

        return self::asRowList(db_get_array($query, ...$params));
    }

    /**
     * @return list<array<string, mixed>>
     */
    #[\Override]
    public function findUnlinkedForAdmin(string $country, string $filter = 'prices', int $limit = 500): array
    {
        if ($filter === 'packages') {
            return self::asRowList(db_get_array(
                'SELECT h.*
                 FROM ?:novoton_hotels h
                 INNER JOIN ?:novoton_hotel_packages pkg ON h.hotel_id = pkg.hotel_id
                 WHERE h.country = ?s
                   AND (h.product_id IS NULL OR h.product_id = 0)
                 GROUP BY h.hotel_id
                 ORDER BY h.hotel_name
                 LIMIT ?i',
                $country,
                $limit,
            ));
        }

        return self::asRowList(db_get_array(
            "SELECT h.*
             FROM ?:novoton_hotels h
             WHERE h.country = ?s
               AND h.has_room_price = 'Y'
               AND (h.product_id IS NULL OR h.product_id = 0)
             ORDER BY h.hotel_name
             LIMIT ?i",
            $country,
            $limit,
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    #[\Override]
    public function findNeedingPriceCheck(int $daysStale = 7, int $limit = 100): array
    {
        return self::asRowList(db_get_array(
            'SELECT hotel_id, hotel_name FROM ?:novoton_hotels
             WHERE (last_price_check IS NULL OR last_price_check < DATE_SUB(NOW(), INTERVAL ?i DAY))
             LIMIT ?i',
            $daysStale,
            $limit,
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    #[\Override]
    public function findNeedingPriceUpdate(int $staleHours = 24, int $limit = 100): array
    {
        return self::asRowList(db_get_array(
            "SELECT hotel_id, hotel_name, product_id
             FROM ?:novoton_hotels
             WHERE has_room_price = 'Y'
               AND (last_price_check IS NULL OR last_price_check < DATE_SUB(NOW(), INTERVAL ?i HOUR))
             ORDER BY CASE WHEN last_price_check IS NULL THEN 0 ELSE 1 END, last_price_check ASC
             LIMIT ?i",
            $staleHours,
            $limit,
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    #[\Override]
    public function findWithProductsSortedByStaleness(int $limit = 50): array
    {
        return self::asRowList(db_get_array(
            'SELECT hotel_id, hotel_name, product_id
             FROM ?:novoton_hotels
             WHERE product_id > 0
             ORDER BY CASE WHEN last_price_check IS NULL THEN 0 ELSE 1 END, last_price_check ASC
             LIMIT ?i',
            $limit,
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    #[\Override]
    public function findWithPricesForExport(string $country): array
    {
        return self::asRowList(db_get_array(
            "SELECT hotel_id, hotel_name, city, hotel_type, has_room_price, product_id, last_price_check
             FROM ?:novoton_hotels
             WHERE country = ?s AND has_room_price = 'Y'
             ORDER BY city, hotel_name",
            $country,
        ));
    }

    /**
     * @param list<string> $selectedResorts
     * @return list<array<string, mixed>>
     */
    #[\Override]
    public function findForImport(string $country, string $importMode = 'new_only', array $selectedResorts = [], int $limit = 0): array
    {
        $condition = "country = ?s AND has_room_price = 'Y'";
        $params = [$country];

        if ($importMode === 'new_only') {
            $condition .= ' AND (product_id IS NULL OR product_id = 0)';
        }

        if (!empty($selectedResorts)) {
            $condition .= ' AND city IN (?a)';
            $params[] = $selectedResorts;
        }

        $limit_sql = $limit > 0 ? ' LIMIT ' . (int)($limit) : '';

        return self::asRowList(db_get_array(
            "SELECT * FROM ?:novoton_hotels WHERE {$condition} ORDER BY hotel_name {$limit_sql}",
            ...$params,
        ));
    }

    /**
     * @return list<string>
     */
    #[\Override]
    public function findIdsWithPriceinfoData(): array
    {
        return self::asStringList(db_get_fields(
            "SELECT DISTINCT h.hotel_id FROM ?:novoton_hotels h
             INNER JOIN ?:novoton_hotel_packages p ON h.hotel_id = p.hotel_id
             WHERE p.priceinfo_data IS NOT NULL AND p.priceinfo_data != ''",
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    #[\Override]
    public function findLinkedForSeo(int $offset, int $batch): array
    {
        return self::asRowList(db_get_array(
            'SELECT hotel_id, product_id, hotel_name, city, country, region,
                    star_rating, hotel_type, property_type, latitude, longitude
             FROM ?:novoton_hotels
             WHERE product_id IS NOT NULL AND product_id > 0
             LIMIT ?i, ?i',
            $offset,
            $batch,
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    #[\Override]
    public function findWithPriceinfoData(int $limit = 200): array
    {
        return self::asRowList(db_get_array(
            'SELECT DISTINCT h.hotel_id, h.hotel_name
             FROM ?:novoton_hotels h
             INNER JOIN ?:novoton_hotel_packages p ON h.hotel_id = p.hotel_id
             WHERE p.priceinfo_data IS NOT NULL
             ORDER BY h.hotel_name
             LIMIT ?i',
            $limit,
        ));
    }

    /**
     * @param array<string, mixed> $filters
     */
    #[\Override]
    public function count(array $filters = []): int
    {
        $where = $this->buildWhereClause($filters);
        return TypeCoerce::toInt(db_get_field("SELECT COUNT(*) FROM ?:novoton_hotels {$where}"));
    }

    /**
     * Build a WHERE clause from a filter associative array.
     *
     * Duplicated from HotelRepository::buildWhereClause — intentional and
     * temporary; see class-level docblock.
     * @param array<string, mixed> $filters
     */
    private function buildWhereClause(array $filters): string
    {
        $conditions = [];

        if (!empty($filters['country'])) {
            $conditions[] = db_quote('country = ?s', $filters['country']);
        }
        if (!empty($filters['city'])) {
            $conditions[] = db_quote('city = ?s', $filters['city']);
        }
        if (!empty($filters['resort'])) {
            $conditions[] = db_quote('city = ?s', $filters['resort']);
        }
        if (!empty($filters['has_room_price'])) {
            $conditions[] = db_quote('has_room_price = ?s', $filters['has_room_price']);
        }
        if (!empty($filters['has_product'])) {
            $conditions[] = 'product_id > 0';
        }
        if (!empty($filters['no_packages'])) {
            $conditions[] = 'packages_count = 0';
        }
        if (!empty($filters['has_packages'])) {
            $conditions[] = 'packages_count > 0';
        }
        if (!empty($filters['has_verified_room_price'])) {
            $conditions[] = "has_room_price = 'Y' AND last_price_check IS NOT NULL";
        }
        if (!empty($filters['stars'])) {
            $conditions[] = db_quote('star_rating = ?i', TypeCoerce::toInt($filters['stars']));
        }
        if (!empty($filters['star_rating'])) {
            $conditions[] = db_quote('star_rating = ?i', TypeCoerce::toInt($filters['star_rating']));
        }

        return !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';
    }
}
