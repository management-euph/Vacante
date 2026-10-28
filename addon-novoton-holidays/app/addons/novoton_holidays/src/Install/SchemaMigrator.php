<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Install;

/**
 * Columns added after install, for stores that already have the tables
 * (the Eurosite and Sphinx pattern). addon.xml's CREATE TABLE has them for a
 * new install; here each one is added where it is missing.
 *
 * These carry what offers_update reports (Novoton's answer of 28 Oct 2026:
 * run offers_update every 12 hours, then ask priceinfo only for what it
 * returned):
 *  - a package it names gets price_changed_at: priceinfo asks for it again;
 *  - a hotel it names with a package its stored hotelinfo does not list gets
 *    hotelinfo_due_at: hotelinfo asks for it again, which lists the package;
 *  - hotelinfo_tried_at is the last hotelinfo request for a hotel, answered
 *    or not, so a hotel Novoton does not answer for is asked once a day, not
 *    every five minutes.
 *
 * novoton_hotel_images (and images_checked_at) remember which product image
 * each Novoton photo became, for the weekly photo refresh (refresh_images).
 *
 * Runs in the admin area once per deployed version of this file (init.php,
 * stamp-gated and guarded) and before every cron run (cron.php and the cron
 * URL), so a job never reads a column that is not there yet.
 */
final class SchemaMigrator
{
    private static bool $done = false;

    /** Keep in sync with the novoton_hotels CREATE in addon.xml. */
    public const array HOTEL_COLUMNS = [
        'hotelinfo_due_at' => "ADD COLUMN `hotelinfo_due_at` datetime DEFAULT NULL COMMENT 'offers_update named a package hotelinfo does not list yet' AFTER `hotelinfo_synced_at`",
        'hotelinfo_tried_at' => "ADD COLUMN `hotelinfo_tried_at` datetime DEFAULT NULL COMMENT 'Last hotelinfo request, answered or not' AFTER `hotelinfo_due_at`",
        'images_checked_at' => "ADD COLUMN `images_checked_at` datetime DEFAULT NULL COMMENT 'Last hotel_images refresh that got an answer' AFTER `hotelinfo_tried_at`",
    ];

    /** Keep in sync with the novoton_hotel_images CREATE in addon.xml. */
    public const string IMAGES_TABLE = "CREATE TABLE IF NOT EXISTS `?:novoton_hotel_images` (
                `hotel_id` varchar(50) NOT NULL COMMENT 'IdHotel from API',
                `path_key` char(40) NOT NULL COMMENT 'SHA-1 of path',
                `path` varchar(500) NOT NULL DEFAULT '' COMMENT 'As hotel_images lists it',
                `product_id` int(11) unsigned NOT NULL DEFAULT 0 COMMENT 'The product it was matched for',
                `pair_id` int(11) unsigned NOT NULL DEFAULT 0 COMMENT 'The product image it is (images_links)',
                `origin` varchar(8) NOT NULL DEFAULT 'added' COMMENT 'added: attached from Novoton, may be replaced; kept: on the product before, never removed',
                `sha1` char(40) NOT NULL DEFAULT '' COMMENT 'SHA-1 of the downloaded file',
                `dhash` char(16) NOT NULL DEFAULT '' COMMENT 'Difference hash: the same picture saved again',
                `listed` char(1) NOT NULL DEFAULT 'Y' COMMENT 'In the latest hotel_images answer',
                `first_seen_at` datetime DEFAULT NULL,
                `last_seen_at` datetime DEFAULT NULL,
                PRIMARY KEY (`hotel_id`, `path_key`),
                KEY `idx_pair` (`pair_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Novoton hotel photos and the product images they became'";

    /** Keep in sync with the novoton_hotel_packages CREATE in addon.xml. */
    public const array PACKAGE_COLUMNS = [
        'price_changed_at' => "ADD COLUMN `price_changed_at` datetime DEFAULT NULL COMMENT 'offers_update named this package: priceinfo again' AFTER `synced_at`",
    ];

    /**
     * @param string $prefix the ?: table prefix, read at the caller (Registry
     *                       access stays out of src/)
     */
    public static function ensure(string $prefix): void
    {
        if (self::$done) {
            return;
        }
        self::$done = true;

        if (self::addMissingColumns($prefix, 'novoton_hotels', self::HOTEL_COLUMNS)) {
            db_query(self::IMAGES_TABLE);
        }
        self::addMissingColumns($prefix, 'novoton_hotel_packages', self::PACKAGE_COLUMNS);
    }

    /**
     * @param array<string, string> $columns column => ADD COLUMN definition
     *
     * @return bool whether the table is there (the add-on is installed)
     */
    private static function addMissingColumns(string $prefix, string $table, array $columns): bool
    {
        $rows = db_get_array(
            'SELECT COLUMN_NAME AS n FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?s',
            $prefix . $table,
        );
        $have = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row) && isset($row['n']) && is_scalar($row['n'])) {
                $have[(string) $row['n']] = true;
            }
        }
        if ($have === []) {
            return false; // table absent: the add-on is not installed, its CREATE owns it
        }
        foreach ($columns as $column => $ddl) {
            if (!isset($have[$column])) {
                db_query('ALTER TABLE ?:' . $table . ' ' . $ddl);
            }
        }

        return true;
    }
}
