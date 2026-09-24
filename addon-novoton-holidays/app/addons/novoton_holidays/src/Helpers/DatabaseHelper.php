<?php

declare(strict_types=1);

/**
 * Novoton Holidays - Database Helper
 *
 * Optimized database operations:
 * - Batch inserts/updates (reduce N+1 queries)
 * - Upsert operations (INSERT ... ON DUPLICATE KEY UPDATE)
 * - Bulk lookups with caching
 *
 * @package NovotonHolidays
 * @since 3.1.0 (instance-based since 3.3.0)
 */

namespace Tygh\Addons\NovotonHolidays\Helpers;

use Tygh\Addons\NovotonHolidays\Constants;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

class DatabaseHelper implements DatabaseHelperInterface
{
    /**
     * Batch update hotels has_room_price flag after room_price API checks
     *
     * @param list<string> $withPrices Hotel IDs that have prices (set has_room_price = 'Y')
     * @param list<string> $withoutPrices Hotel IDs without prices (set has_room_price = 'N')
     * @return int Number of rows updated
     */
    public function batchUpdateHasRoomPriceFlag(array $withPrices, array $withoutPrices): int
    {
        $updated = 0;

        if (!empty($withPrices)) {
            $updated += TypeCoerce::toInt(\db_query(
                "UPDATE ?:novoton_hotels
                 SET has_room_price = 'Y', last_price_check = NOW()
                 WHERE hotel_id IN (?a)",
                $withPrices,
            ));
        }

        if (!empty($withoutPrices)) {
            $updated += TypeCoerce::toInt(\db_query(
                "UPDATE ?:novoton_hotels
                 SET has_room_price = 'N', last_price_check = NOW()
                 WHERE hotel_id IN (?a)",
                $withoutPrices,
            ));
        }

        return $updated;
    }

    /**
     * Bulk lookup: Get existing product IDs for multiple hotel IDs
     *
     * @return array<string, int> Map of hotel_id => product_id
     * @param list<string> $hotelIds
     */
    public function getProductIdsByHotelIds(array $hotelIds): array
    {
        if (empty($hotelIds)) {
            return [];
        }

        $productCodes = array_map(fn ($id): string => Constants::PRODUCT_CODE_PREFIX . $id, $hotelIds);

        $results = TypeCoerce::toStringMap(\db_get_hash_array(
            'SELECT product_code, product_id
             FROM ?:products
             WHERE product_code IN (?a)',
            'product_code',
            $productCodes,
        ));

        $map = [];
        foreach ($hotelIds as $hotelId) {
            $code = Constants::PRODUCT_CODE_PREFIX . $hotelId;
            if (isset($results[$code])) {
                $row = TypeCoerce::toStringMap($results[$code]);
                $map[$hotelId] = TypeCoerce::toInt($row['product_id'] ?? null);
            }
        }

        return $map;
    }

    /**
     * Bulk lookup: Check which hotel IDs already exist in database
     *
     * @return list<string> Existing hotel IDs
     * @param list<string> $hotelIds
     */
    public function getExistingHotelIds(array $hotelIds): array
    {
        if (empty($hotelIds)) {
            return [];
        }

        return TypeCoerce::toStringList(\db_get_fields(
            'SELECT hotel_id FROM ?:novoton_hotels WHERE hotel_id IN (?a)',
            $hotelIds,
        ));
    }

    /**
     * Bulk upsert hotels (INSERT ... ON DUPLICATE KEY UPDATE)
     *
     * @return array{inserted: int, updated: int}
     * @param list<array<string, mixed>> $hotels
     */
    public function upsertHotels(array $hotels): array
    {
        if (empty($hotels)) {
            return ['inserted' => 0, 'updated' => 0];
        }

        $now = date('Y-m-d H:i:s');
        $inserted = 0;
        $updated = 0;

        foreach ($hotels as $hotel) {
            if (empty($hotel['hotel_id'])) {
                continue;
            }

            $hotel['updated_at'] = $now;

            $affected = TypeCoerce::toInt(\db_query(
                'INSERT INTO ?:novoton_hotels ?e
                 ON DUPLICATE KEY UPDATE ?u',
                array_merge($hotel, ['created_at' => $now]),
                $hotel,
            ));

            if ($affected === 1) {
                $inserted++;
            } elseif ($affected >= 2) {
                $updated++;
            }
        }

        return ['inserted' => $inserted, 'updated' => $updated];
    }

    /**
     * Upsert hotel packages (batch)
     *
     * @return int Number of packages upserted
     * @param list<array<string, mixed>> $packages
     */
    public function upsertHotelPackages(string $hotelId, array $packages): int
    {
        if (empty($packages)) {
            return 0;
        }

        $count = 0;

        foreach ($packages as $pkg) {
            $packageId = $pkg['IdCont'] ?? $pkg['package_id'] ?? '';
            $packageName = $pkg['PackageName'] ?? $pkg['package_name'] ?? '';

            if (empty($packageId)) {
                continue;
            }

            \db_query(
                'INSERT INTO ?:novoton_hotel_packages
                 (hotel_id, package_id, package_name, created_at)
                 VALUES (?s, ?s, ?s, NOW())
                 ON DUPLICATE KEY UPDATE package_name = ?s, updated_at = NOW()',
                $hotelId,
                $packageId,
                $packageName,
                $packageName,
            );

            $count++;
        }

        return $count;
    }

    /**
     * Link products to hotels in batch
     *
     * @param list<array<string, mixed>> $links Array of ['hotel_id' => x, 'product_id' => y]
     * @return int Number of links created
     */
    public function linkProductsToHotels(array $links): int
    {
        if (empty($links)) {
            return 0;
        }

        $count = 0;

        foreach ($links as $link) {
            if (empty($link['hotel_id']) || empty($link['product_id'])) {
                continue;
            }

            $pid = TypeCoerce::toInt($link['product_id']);
            if ($pid <= 0) {
                continue;
            }

            \db_query(
                'UPDATE ?:novoton_hotels SET product_id = ?i WHERE hotel_id = ?s',
                $pid,
                $link['hotel_id'],
            );

            $count++;
        }

        return $count;
    }

    /** @var string[] Allowed column names for dynamic queries */
    private const array ALLOWED_COLUMNS = [
        'hotel_id', 'product_id', 'hotel_name', 'city', 'region', 'country',
        'hotel_type', 'star_rating', 'latitude', 'longitude', 'has_room_price',
        'packages_count', 'hotelinfo_synced_at', 'hotel_list_synced_at',
        'created_at', 'updated_at', 'hotel_data', 'last_price_check',
        'street_address', 'geocoded_at',
    ];

    /**
     * Get hotels for sync with optimized field selection
     * @param array<string, mixed> $conditions
     * @param list<string> $fields
     * @return list<array<string, mixed>>
     */
    public function getHotelsForSync(array $conditions = [], int $limit = 0, array $fields = []): array
    {
        if (empty($fields)) {
            $fields = ['hotel_id', 'hotel_name', 'country', 'city', 'has_room_price', 'product_id'];
        }

        // Validate field names against whitelist
        $fields = array_intersect($fields, self::ALLOWED_COLUMNS);
        if (empty($fields)) {
            $fields = ['hotel_id'];
        }

        $fieldList = implode(', ', $fields);
        $where = [];
        $params = [];

        foreach ($conditions as $key => $value) {
            // Validate column name against whitelist
            if (!in_array($key, self::ALLOWED_COLUMNS, true)) {
                continue;
            }
            if (is_array($value)) {
                $where[] = "{$key} IN (?a)";
                $params[] = $value;
            } elseif ($value === null) {
                $where[] = "{$key} IS NULL";
            } else {
                $where[] = "{$key} = ?s";
                $params[] = $value;
            }
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        $limitClause = $limit > 0 ? 'LIMIT ?i' : '';

        if ($limit > 0) {
            $params[] = $limit;
        }

        $query = "SELECT {$fieldList} FROM ?:novoton_hotels {$whereClause} ORDER BY hotel_name {$limitClause}";

        return TypeCoerce::toRowList(\db_get_array($query, ...$params));
    }

    /**
     * Hotels for the room_price check, least recently checked first.
     *
     * The check used getHotelsForSync(), ordered by hotel_name with a default
     * limit of 500: every run re-checked the same first 500 hotels of the
     * alphabet, and the rest were never checked — so they never got
     * has_room_price = 'Y' and never became products. Never-checked hotels
     * come first, then the oldest checks, so consecutive runs cover them all.
     *
     * @param array<string, mixed> $conditions column => value (whitelisted columns only)
     * @return list<array<string, mixed>>
     */
    public function getHotelsForPriceCheck(array $conditions = [], int $limit = 0): array
    {
        $where = [];
        $params = [];
        foreach ($conditions as $key => $value) {
            if (!in_array($key, self::ALLOWED_COLUMNS, true) || is_array($value) || $value === null) {
                continue;
            }
            $where[] = "{$key} = ?s";
            $params[] = $value;
        }

        $whereClause = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';
        $limitClause = '';
        if ($limit > 0) {
            $limitClause = 'LIMIT ?i';
            $params[] = $limit;
        }

        $query = "SELECT hotel_id, hotel_name, country FROM ?:novoton_hotels {$whereClause}"
            . ' ORDER BY (last_price_check IS NULL) DESC, last_price_check ASC, hotel_name'
            . " {$limitClause}";

        return TypeCoerce::toRowList(\db_get_array($query, ...$params));
    }

    /**
     * Get last sync date for a specific sync type
     */
    public function getLastSyncDate(string $syncType, ?string $subType = null): ?string
    {
        $query = "SELECT MAX(sync_date) FROM ?:novoton_sync_log
                  WHERE sync_type = ?s AND status = 'completed'";
        $params = [$syncType];

        if ($subType !== null) {
            $query .= ' AND notes LIKE ?s';
            // Escape LIKE wildcards in the value before wrapping with %
            $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $subType);
            $params[] = '%"sync_type":"' . $escaped . '"%';
        }

        $result = \db_get_field($query, ...$params);
        if (empty($result)) {
            return null;
        }

        return TypeCoerce::toString($result);
    }

    /**
     * Get sync statistics from sync_log
     * @return array<string, mixed>
     */
    public function getSyncStats(string $syncType, int $days = 30): array
    {
        $stats = TypeCoerce::toStringMap(\db_get_row(
            'SELECT
                COUNT(*) as total_runs,
                SUM(products_updated) as total_updated,
                SUM(products_failed) as total_failed,
                AVG(duration_seconds) as avg_duration,
                MAX(sync_date) as last_sync
             FROM ?:novoton_sync_log
             WHERE sync_type = ?s
             AND sync_date > DATE_SUB(NOW(), INTERVAL ?i DAY)',
            $syncType,
            $days,
        ));

        return [
            'total_runs' => TypeCoerce::toInt($stats['total_runs'] ?? 0),
            'total_updated' => TypeCoerce::toInt($stats['total_updated'] ?? 0),
            'total_failed' => TypeCoerce::toInt($stats['total_failed'] ?? 0),
            'avg_duration' => round(TypeCoerce::toFloat($stats['avg_duration'] ?? 0), 1),
            'last_sync' => $stats['last_sync'] ?? null,
        ];
    }

    /**
     * Cleanup old sync logs
     *
     * @return int Number of deleted records
     */
    public function cleanupOldLogs(int $days = 90): int
    {
        return TypeCoerce::toInt(\db_query(
            'DELETE FROM ?:novoton_sync_log
             WHERE sync_date < DATE_SUB(NOW(), INTERVAL ?i DAY)',
            $days,
        ));
    }

    /**
     * Get product code for hotel ID
     */
    public function getProductCode(string $hotelId): string
    {
        return Constants::PRODUCT_CODE_PREFIX . $hotelId;
    }

    /**
     * Extract hotel ID from product code
     */
    public function extractHotelId(string $productCode): ?string
    {
        $prefix = Constants::PRODUCT_CODE_PREFIX;
        if (str_starts_with($productCode, $prefix)) {
            return substr($productCode, strlen($prefix));
        }

        preg_match('/\d+/', $productCode, $matches);
        return $matches[0] ?? null;
    }
}
