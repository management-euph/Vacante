<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Install;

/**
 * Idempotent schema self-heal for EXISTING installs (sphinx pattern).
 *
 * Dev stores symlink the addon and never rerun addon.xml, so schema shipped
 * after install must converge at runtime. Two steps, both idempotent:
 *
 *  1. Missing TABLES — re-execute addon.xml's own `CREATE TABLE IF NOT
 *     EXISTS` install items verbatim (single source of truth, no DDL drift;
 *     only items starting with that exact phrase are run, so future seed
 *     INSERTs can never replay here).
 *  2. Missing COLUMNS on ?:eurosite_bookings and ?:eurosite_hotels — both
 *     tables predate columns added later (the booking pipeline; the
 *     availability check and product links); checked via INFORMATION_SCHEMA
 *     and ADDed one by one, with their indexes.
 *
 * Called once per request from func.php (AREA 'A' and the cron controller);
 * "reinstall the addon" remains the manual fallback.
 */
final class SchemaMigrator
{
    private static bool $done = false;

    /** Keep in sync with the eurosite_bookings CREATE in addon.xml. */
    public const BOOKING_COLUMNS = [
        'nights' => 'ADD COLUMN `nights` SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER `check_out`',
        'adults' => 'ADD COLUMN `adults` TINYINT UNSIGNED NOT NULL DEFAULT 2 AFTER `nights`',
        'children' => 'ADD COLUMN `children` TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER `adults`',
        'children_ages' => "ADD COLUMN `children_ages` VARCHAR(100) NOT NULL DEFAULT '' AFTER `children`",
        'num_rooms' => 'ADD COLUMN `num_rooms` TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER `children_ages`',
        'rooms_data' => 'ADD COLUMN `rooms_data` JSON DEFAULT NULL AFTER `num_rooms`',
        'room_type' => "ADD COLUMN `room_type` VARCHAR(255) NOT NULL DEFAULT '' AFTER `rooms_data`",
        'board_id' => "ADD COLUMN `board_id` VARCHAR(32) NOT NULL DEFAULT '' COMMENT 'meal/board service code' AFTER `room_type`",
        'meal_name' => "ADD COLUMN `meal_name` VARCHAR(255) NOT NULL DEFAULT '' AFTER `board_id`",
        'series_id' => "ADD COLUMN `series_id` VARCHAR(32) NOT NULL DEFAULT '' COMMENT 'SeriesId from the offer' AFTER `meal_name`",
        'guest_name' => "ADD COLUMN `guest_name` VARCHAR(500) NOT NULL DEFAULT '' AFTER `series_id`",
        'guest_email' => "ADD COLUMN `guest_email` VARCHAR(255) NOT NULL DEFAULT '' AFTER `guest_name`",
        'guest_phone' => "ADD COLUMN `guest_phone` VARCHAR(100) NOT NULL DEFAULT '' AFTER `guest_email`",
        'cancellation_fees_json' => "ADD COLUMN `cancellation_fees_json` JSON DEFAULT NULL COMMENT 'getBookingFees snapshot' AFTER `status`",
        'updated_at' => 'ADD COLUMN `updated_at` DATETIME DEFAULT NULL AFTER `created_at`',
    ];

    /**
     * The availability check and product columns. Keep in sync with the
     * eurosite_hotels CREATE in addon.xml (EurositeSchemaParityTest pins it).
     */
    public const HOTEL_COLUMNS = [
        'inactive_reason' => "ADD COLUMN `inactive_reason` VARCHAR(32) NOT NULL DEFAULT '' AFTER `sync_status`",
        'availability' => "ADD COLUMN `availability` VARCHAR(4) NOT NULL DEFAULT '' AFTER `last_synced_at`",
        'availability_check_in' => 'ADD COLUMN `availability_check_in` DATE DEFAULT NULL AFTER `availability`',
        'availability_window' => "ADD COLUMN `availability_window` VARCHAR(8) NOT NULL DEFAULT '' AFTER `availability_check_in`",
        'availability_checked_at' => 'ADD COLUMN `availability_checked_at` DATETIME DEFAULT NULL AFTER `availability_window`',
        'min_price' => 'ADD COLUMN `min_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `availability_checked_at`',
        'min_gross' => 'ADD COLUMN `min_gross` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `min_price`',
        'price_currency' => "ADD COLUMN `price_currency` VARCHAR(4) NOT NULL DEFAULT '' AFTER `min_gross`",
        'first_image' => "ADD COLUMN `first_image` VARCHAR(512) NOT NULL DEFAULT '' AFTER `price_currency`",
        'gate_hidden' => "ADD COLUMN `gate_hidden` ENUM('Y','N') NOT NULL DEFAULT 'N' AFTER `first_image`",
        'product_skip_reason' => "ADD COLUMN `product_skip_reason` VARCHAR(32) NOT NULL DEFAULT '' AFTER `gate_hidden`",
        'product_updated_at' => 'ADD COLUMN `product_updated_at` DATETIME DEFAULT NULL AFTER `product_skip_reason`',
        'hotel_class' => "ADD COLUMN `hotel_class` VARCHAR(64) NOT NULL DEFAULT '' AFTER `product_updated_at`",
        'meals' => "ADD COLUMN `meals` VARCHAR(255) NOT NULL DEFAULT '' AFTER `hotel_class`",
    ];

    public const HOTEL_INDEXES = [
        'idx_availability' => 'ADD KEY `idx_availability` (`availability`)',
        'idx_product' => 'ADD KEY `idx_product` (`product_id`)',
    ];

    /**
     * @param string $prefix The ?: table prefix, read at the func.php
     *                       boundary (Registry access is banned in src/).
     */
    public static function ensure(string $prefix): void
    {
        if (self::$done) {
            return;
        }
        self::$done = true;

        self::createMissingTables();
        self::addMissingColumns($prefix, 'eurosite_bookings', self::BOOKING_COLUMNS);
        self::addMissingColumns($prefix, 'eurosite_hotels', self::HOTEL_COLUMNS);
        self::addMissingIndexes($prefix, 'eurosite_hotels', self::HOTEL_INDEXES);
    }

    private static function createMissingTables(): void
    {
        $xml = @simplexml_load_file(__DIR__ . '/../../addon.xml');
        if ($xml === false) {
            return;
        }
        foreach ($xml->queries->item ?? [] as $item) {
            if ((string) $item['for'] !== 'install') {
                continue;
            }
            $sql = trim((string) $item);
            if (stripos($sql, 'CREATE TABLE IF NOT EXISTS') !== 0) {
                continue;
            }
            db_query($sql);
        }
    }

    /**
     * @param array<string, string> $columns column => ADD COLUMN definition
     */
    private static function addMissingColumns(string $prefix, string $table, array $columns): void
    {
        $have = self::existing(
            'SELECT COLUMN_NAME AS n FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?s',
            $prefix . $table,
        );
        if ($have === []) {
            return; // table absent — addon not installed; base CREATE owns it
        }
        foreach ($columns as $column => $ddl) {
            if (!isset($have[$column])) {
                db_query('ALTER TABLE ?:' . $table . ' ' . $ddl);
            }
        }
    }

    /**
     * @param array<string, string> $indexes index name => ADD KEY definition
     */
    private static function addMissingIndexes(string $prefix, string $table, array $indexes): void
    {
        $have = self::existing(
            'SELECT DISTINCT INDEX_NAME AS n FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?s',
            $prefix . $table,
        );
        if ($have === []) {
            return;
        }
        foreach ($indexes as $name => $ddl) {
            if (!isset($have[$name])) {
                db_query('ALTER TABLE ?:' . $table . ' ' . $ddl);
            }
        }
    }

    /**
     * @return array<string, true>
     */
    private static function existing(string $sql, string $table): array
    {
        $rows = db_get_array($sql, $table);
        $have = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row) && isset($row['n']) && is_scalar($row['n'])) {
                $have[(string) $row['n']] = true;
            }
        }

        return $have;
    }
}
