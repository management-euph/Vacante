<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Install;

/**
 * Idempotent schema-delta applier for EXISTING installs.
 *
 * The addon.xml `for="upgrade"` ALTERs are inert in practice: the addon
 * version is pinned at 1.0.0 and no CS-Cart upgrade scheme exists, so
 * installed sites never received schema deltas — code shipped ahead of a
 * hand-applied ALTER crashed on missing columns (INSTALL.md §8 documented
 * this as a manual deploy-checklist step). Mirrors travel_core's
 * fn_travel_core_ensure_schema(): called on admin page load (init.php via
 * the fn_sphinx_holidays_ensure_schema shell, AREA 'A'), guarded once per
 * request, and only ever ADDS what is missing — fresh installs already get
 * complete tables from the base CREATEs, and tables that don't exist yet
 * are skipped entirely.
 *
 * Keep the lists in sync with addon.xml's `for="upgrade"` items: every new
 * upgrade ALTER gets a matching entry here so existing installs converge.
 */
final class SchemaMigrator
{
    private static bool $done = false;

    /**
     * @param string $prefix The ?: table prefix, read at the func.php boundary
     *                       (Registry access is banned inside src/ bodies).
     */
    public static function ensure(string $prefix): void
    {
        if (self::$done) {
            return;
        }
        self::$done = true;

        // table (unprefixed) => column => ADD COLUMN definition
        $columns = [
            'sphinx_hotels' => [
                'address' => "ADD COLUMN `address` VARCHAR(500) DEFAULT NULL COMMENT 'Street address from API' AFTER `longitude`",
                'phone' => "ADD COLUMN `phone` VARCHAR(50) DEFAULT NULL COMMENT 'Hotel phone number' AFTER `address`",
                'email' => "ADD COLUMN `email` VARCHAR(255) DEFAULT NULL COMMENT 'Hotel email address' AFTER `phone`",
                'website' => "ADD COLUMN `website` VARCHAR(500) DEFAULT NULL COMMENT 'Hotel website URL' AFTER `email`",
                'address_city' => "ADD COLUMN `address_city` VARCHAR(255) DEFAULT NULL COMMENT 'City from the hotel API address' AFTER `website`",
                'address_country' => "ADD COLUMN `address_country` VARCHAR(255) DEFAULT NULL COMMENT 'Country from the hotel API address' AFTER `address_city`",
                'geo_city' => "ADD COLUMN `geo_city` VARCHAR(255) DEFAULT NULL COMMENT 'Reverse-geocoded city (OSM Nominatim) — last rung of the City ladder' AFTER `address_country`",
                'geo_region' => "ADD COLUMN `geo_region` VARCHAR(255) DEFAULT NULL COMMENT 'Reverse-geocoded region/province (OSM Nominatim) — last rung of the Region ladder' AFTER `geo_city`",
                'street_address' => "ADD COLUMN `street_address` VARCHAR(255) DEFAULT NULL COMMENT 'Reverse-geocoded street (OSM Nominatim), approximate' AFTER `geo_region`",
                'geocoded_at' => "ADD COLUMN `geocoded_at` DATETIME DEFAULT NULL COMMENT 'Last reverse-geocode attempt (stamped even when nothing was found)' AFTER `street_address`",
                'location_source' => "ADD COLUMN `location_source` VARCHAR(20) DEFAULT NULL COMMENT 'Weakest rung that produced City/Region: destination|ancestor|address|geocode' AFTER `geocoded_at`",
                'product_skip_reason' => "ADD COLUMN `product_skip_reason` VARCHAR(50) DEFAULT NULL COMMENT 'Why product linking was skipped (category_failed, invalid_country, etc.)' AFTER `product_id`",
                'images_json' => "ADD COLUMN `images_json` JSON DEFAULT NULL COMMENT 'All image objects from API [{url, ...}, ...]' AFTER `image_url`",
                'product_needs_update' => "ADD COLUMN `product_needs_update` ENUM('Y','N') DEFAULT 'N' COMMENT 'Set to Y when API data differs from CS-Cart product' AFTER `images_json`",
                'calendar_prices_raw' => "ADD COLUMN `calendar_prices_raw` JSON DEFAULT NULL COMMENT 'Per-date raw from-prices for the booking calendar (calendar_prices cron)' AFTER `sync_status`",
            ],
            'sphinx_destinations' => [
                'full_path' => "ADD COLUMN `full_path` VARCHAR(500) DEFAULT NULL COMMENT 'Breadcrumb: City, Region, Country, Continent' AFTER `country_code`",
                // NULL on the rows already there ("known before"): upgrading flags nothing as new.
                'first_seen_at' => "ADD COLUMN `first_seen_at` DATETIME DEFAULT NULL COMMENT 'First destinations sync that listed it: new since the last whitelist Save' AFTER `hotel_count`",
            ],
            'sphinx_sync_log' => [
                'rate_limit_hits' => 'ADD COLUMN `rate_limit_hits` INT UNSIGNED DEFAULT 0 AFTER `error_message`',
                'sync_mode' => "ADD COLUMN `sync_mode` VARCHAR(20) DEFAULT 'full' AFTER `rate_limit_hits`",
            ],
            'sphinx_bookings' => [
                'payment_terms_json' => "ADD COLUMN `payment_terms_json` JSON DEFAULT NULL COMMENT 'Payment terms from API (verify / orders sync)' AFTER `api_response`",
                'cancellation_fees_json' => "ADD COLUMN `cancellation_fees_json` JSON DEFAULT NULL COMMENT 'Cancellation fees from API (verify / orders sync)' AFTER `payment_terms_json`",
                'pricing_json' => "ADD COLUMN `pricing_json` JSON DEFAULT NULL COMMENT 'Full pricing breakdown from verify (marketing/discount/selling/commission/supplier/taxes/fees)' AFTER `cancellation_fees_json`",
            ],
        ];

        // table => index => ADD KEY definition
        $indexes = [
            'sphinx_hotels' => [
                'idx_sync_product' => 'ADD KEY `idx_sync_product` (`sync_status`, `product_id`)',
                'idx_sync_country' => 'ADD KEY `idx_sync_country` (`sync_status`, `country_code`)',
                'idx_country_sync_synced' => 'ADD KEY `idx_country_sync_synced` (`country_code`, `sync_status`, `last_synced_at`)',
                // Hotels admin grid sorts by name by default — without this,
                // every page load filesorts the whole table.
                'idx_name' => 'ADD KEY `idx_name` (`name`)',
            ],
            'sphinx_destinations' => [
                'idx_full_path' => 'ADD KEY `idx_full_path` (`full_path`(100))',
            ],
        ];

        $tables = array_values(array_unique(array_merge(array_keys($columns), array_keys($indexes), ['sphinx_cache'])));
        $prefixed = array_map(static fn (string $t): string => $prefix . $t, $tables);

        // Two catalog reads cover every check below.
        $columnRows = db_get_array(
            'SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (?a)',
            $prefixed,
        );
        $indexRows = db_get_array(
            'SELECT DISTINCT TABLE_NAME, INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (?a)',
            $prefixed,
        );
        if (!is_array($columnRows) || !is_array($indexRows)) {
            return;
        }

        $haveColumn = [];
        $cacheDataType = '';
        foreach ($columnRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $table = isset($row['TABLE_NAME']) && is_scalar($row['TABLE_NAME']) ? (string) $row['TABLE_NAME'] : '';
            $column = isset($row['COLUMN_NAME']) && is_scalar($row['COLUMN_NAME']) ? (string) $row['COLUMN_NAME'] : '';
            $haveColumn[$table][$column] = true;
            if ($table === $prefix . 'sphinx_cache' && $column === 'cache_data') {
                $cacheDataType = isset($row['DATA_TYPE']) && is_scalar($row['DATA_TYPE']) ? strtolower((string) $row['DATA_TYPE']) : '';
            }
        }
        $haveIndex = [];
        foreach ($indexRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $table = isset($row['TABLE_NAME']) && is_scalar($row['TABLE_NAME']) ? (string) $row['TABLE_NAME'] : '';
            $index = isset($row['INDEX_NAME']) && is_scalar($row['INDEX_NAME']) ? (string) $row['INDEX_NAME'] : '';
            $haveIndex[$table][$index] = true;
        }

        foreach ($columns as $table => $defs) {
            if (!isset($haveColumn[$prefix . $table])) {
                continue; // table absent — addon not (fully) installed; base CREATE owns it
            }
            foreach ($defs as $column => $ddl) {
                if (!isset($haveColumn[$prefix . $table][$column])) {
                    db_query('ALTER TABLE ?:' . $table . ' ' . $ddl);
                }
            }
        }

        foreach ($indexes as $table => $defs) {
            if (!isset($haveColumn[$prefix . $table])) {
                continue;
            }
            foreach ($defs as $index => $ddl) {
                if (!isset($haveIndex[$prefix . $table][$index])) {
                    db_query('ALTER TABLE ?:' . $table . ' ' . $ddl);
                }
            }
        }

        // Charset fix: cache_data was LONGBLOB before the UTF-8 correction.
        if ($cacheDataType === 'longblob') {
            db_query('ALTER TABLE ?:sphinx_cache MODIFY `cache_data` LONGTEXT NOT NULL');
        }

        // Widen coordinate columns to the 8-decimal standard (latitude DECIMAL(10,8),
        // longitude DECIMAL(11,8); originally DECIMAL(10,7)). Idempotent: MODIFY only
        // when the stored scale is still below 8, so it runs once then no-ops.
        $coordScaleRows = db_get_array(
            "SELECT TABLE_NAME, COLUMN_NAME, NUMERIC_SCALE FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME IN ('latitude', 'longitude')
               AND TABLE_NAME IN (?a)",
            [$prefix . 'sphinx_hotels', $prefix . 'sphinx_destinations'],
        );
        if (is_array($coordScaleRows)) {
            $coordScale = [];
            foreach ($coordScaleRows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $coordTableName = $row['TABLE_NAME'] ?? null;
                $coordColName = $row['COLUMN_NAME'] ?? null;
                if (!is_string($coordTableName) || !is_string($coordColName)) {
                    continue;
                }
                $coordRowScale = $row['NUMERIC_SCALE'] ?? null;
                $coordScale[$coordTableName][$coordColName] = is_numeric($coordRowScale) ? (int) $coordRowScale : 0;
            }
            foreach (['sphinx_hotels', 'sphinx_destinations'] as $coordTable) {
                $have = $coordScale[$prefix . $coordTable] ?? null;
                if ($have !== null && (($have['latitude'] ?? 0) < 8 || ($have['longitude'] ?? 0) < 8)) {
                    db_query(
                        'ALTER TABLE ?:' . $coordTable
                        . ' MODIFY `latitude` DECIMAL(10,8) DEFAULT NULL, MODIFY `longitude` DECIMAL(11,8) DEFAULT NULL',
                    );
                }
            }
        }
    }
}
