<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Repository;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Repository\RowNarrowingTrait;

/**
 * ?:novoton_destination_whitelist — which countries and resorts we sell
 * (Novoton -> Destinations), Eurosite's row shape keyed by names:
 *
 *   (country, resort = '',  'all')       — every resort, new ones included
 *   (country, resort = '',  'specific')  — only the resort rows below
 *   (country, resort = 'X', 'specific')  — one resort
 *
 * Also the page's catalog: every (country, resort) in ?:novoton_hotels with
 * what selling it means (hotels, priced hotels, live products) and when it
 * was first and last seen in the feed.
 *
 * Existing stores get the table here, not from init.php (which must never
 * run DDL) and not by reinstall: the project deploys by pulling code, and
 * CS-Cart reads addon.xml only at install. The CREATE runs once per
 * TABLE_VERSION, stamped in storage data.
 */
class DestinationWhitelistRepository
{
    use RowNarrowingTrait;

    public const string TABLE_VERSION = '1';

    private const string STAMP = 'novoton_destination_whitelist_schema';

    private static bool $ensured = false;

    /**
     * @return list<array{country: string, resort: string, selection_type: string, reviewed_at: string}>
     */
    public function findAll(): array
    {
        $this->ensureTable();
        $out = [];
        foreach (self::asRowList(db_get_array(
            'SELECT country, resort, selection_type, reviewed_at FROM ?:novoton_destination_whitelist ORDER BY country, resort',
        )) as $row) {
            $out[] = [
                'country' => trim(TypeCoerce::toString($row['country'] ?? '')),
                'resort' => trim(TypeCoerce::toString($row['resort'] ?? '')),
                'selection_type' => TypeCoerce::toString($row['selection_type'] ?? ''),
                'reviewed_at' => TypeCoerce::toString($row['reviewed_at'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Replace the whole whitelist in one transaction (the page's Save).
     * Every row gets the same reviewed_at: resorts first seen after it are
     * the ones the page and the dashboard call new.
     *
     * @param list<array{country: string, resort: string, selection_type: string}> $rows
     */
    public function replaceAll(array $rows, string $reviewedAt): void
    {
        $this->ensureTable();
        db_query('START TRANSACTION');
        try {
            db_query('DELETE FROM ?:novoton_destination_whitelist');
            foreach ($rows as $row) {
                db_query(
                    'INSERT INTO ?:novoton_destination_whitelist (country, resort, selection_type, reviewed_at)
                     VALUES (?s, ?s, ?s, ?s)
                     ON DUPLICATE KEY UPDATE selection_type = VALUES(selection_type), reviewed_at = VALUES(reviewed_at)',
                    $row['country'],
                    $row['resort'],
                    $row['selection_type'],
                    $reviewedAt,
                );
            }
            db_query('COMMIT');
        } catch (\Throwable $e) {
            db_query('ROLLBACK');
            throw $e;
        }
    }

    /**
     * Every resort in the synced hotels, with what selling it means.
     *
     * @return list<array{country: string, resort: string, hotels: int, priced: int, products: int, live: int, first_seen: string, last_seen: string}>
     */
    public function catalog(): array
    {
        $rows = self::asRowList(db_get_array(
            "SELECT h.country, h.city,
                    COUNT(*) AS hotels,
                    SUM(CASE WHEN h.has_room_price = 'Y' THEN 1 ELSE 0 END) AS priced,
                    SUM(CASE WHEN h.product_id > 0 THEN 1 ELSE 0 END) AS products,
                    SUM(CASE WHEN p.status = 'A' THEN 1 ELSE 0 END) AS live,
                    MIN(h.created_at) AS first_seen,
                    MAX(h.hotel_list_synced_at) AS last_seen
             FROM ?:novoton_hotels h
             LEFT JOIN ?:products p ON p.product_id = h.product_id
             WHERE h.city IS NOT NULL AND h.city != '' AND h.country IS NOT NULL AND h.country != ''
             GROUP BY h.country, h.city
             ORDER BY h.country, h.city",
        ));
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'country' => trim(TypeCoerce::toString($row['country'] ?? '')),
                'resort' => trim(TypeCoerce::toString($row['city'] ?? '')),
                'hotels' => TypeCoerce::toInt($row['hotels'] ?? 0),
                'priced' => TypeCoerce::toInt($row['priced'] ?? 0),
                'products' => TypeCoerce::toInt($row['products'] ?? 0),
                'live' => TypeCoerce::toInt($row['live'] ?? 0),
                'first_seen' => TypeCoerce::toString($row['first_seen'] ?? ''),
                'last_seen' => TypeCoerce::toString($row['last_seen'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Active products linked to a Novoton hotel, with the hotel's place.
     *
     * @return list<array{product_id: int, hotel_name: string, country: string, resort: string}>
     */
    public function liveProducts(): array
    {
        $rows = self::asRowList(db_get_array(
            "SELECT h.product_id, h.hotel_name, h.country, h.city
             FROM ?:novoton_hotels h
             INNER JOIN ?:products p ON p.product_id = h.product_id
             WHERE h.product_id > 0 AND p.status = 'A'
             ORDER BY h.country, h.city, h.hotel_name",
        ));
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'product_id' => TypeCoerce::toInt($row['product_id'] ?? 0),
                'hotel_name' => TypeCoerce::toString($row['hotel_name'] ?? ''),
                'country' => trim(TypeCoerce::toString($row['country'] ?? '')),
                'resort' => trim(TypeCoerce::toString($row['city'] ?? '')),
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

    /**
     * Create the table on a store installed before it existed. Once per
     * TABLE_VERSION (stamped); a failure is logged and retried next request,
     * and every reader then sees an empty whitelist, i.e. "not configured".
     */
    private function ensureTable(): void
    {
        if (self::$ensured) {
            return;
        }
        self::$ensured = true;

        if (!function_exists('fn_get_storage_data') || !function_exists('fn_set_storage_data')) {
            return;
        }
        if (fn_get_storage_data(self::STAMP) === self::TABLE_VERSION) {
            return;
        }

        try {
            db_query(
                "CREATE TABLE IF NOT EXISTS ?:novoton_destination_whitelist (
                    `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
                    `country` varchar(100) NOT NULL,
                    `resort` varchar(100) NOT NULL DEFAULT '',
                    `selection_type` enum('all','specific') NOT NULL DEFAULT 'specific',
                    `reviewed_at` datetime DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uk_country_resort` (`country`, `resort`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            );
            fn_set_storage_data(self::STAMP, self::TABLE_VERSION);
        } catch (\Throwable $e) {
            error_log('novoton_holidays: could not create ?:novoton_destination_whitelist — ' . $e->getMessage());
        }
    }
}
