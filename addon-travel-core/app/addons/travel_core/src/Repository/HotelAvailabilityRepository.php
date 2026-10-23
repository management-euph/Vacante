<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Repository;

/**
 * The hotel availability records (docs/novoton-availability-gaps-plan.md §4.1).
 *
 * ?:travel_hotel_availability holds one row per supplier hotel: what its
 * supplier's jobs saw (in the hotel list, priced, offered, booked) and the
 * state the weekly step derived from it. A separate table, so the busy
 * provider hotel tables need no ALTER, and Travel Core's runtime schema heal
 * brings it to installed stores. Each supplier fills the columns it can.
 *
 * ?:travel_hotel_state_log records every state or product status change:
 * who, when and why, so each change can be explained and the weekly email
 * can list them.
 */
final class HotelAvailabilityRepository
{
    /** Same statement as addon.xml's install/upgrade items (AvailabilityTablesTest pins it). */
    public const string CREATE_RECORDS_SQL = 'CREATE TABLE IF NOT EXISTS `?:travel_hotel_availability` (
        `provider`              VARCHAR(32)       NOT NULL COMMENT \'Registry name: novoton, eurosite, sphinx\',
        `hotel_id`              VARCHAR(64)       NOT NULL COMMENT \'Supplier hotel id\',
        `product_id`            INT UNSIGNED      NOT NULL DEFAULT 0,
        `destination_key`       VARCHAR(64)       NOT NULL DEFAULT \'\' COMMENT \'Resort, city code or destination id: the peer group\',
        `known_since`           DATE              DEFAULT NULL COMMENT \'When the supplier first listed the hotel\',
        `listed_last_seen_at`   DATETIME          DEFAULT NULL COMMENT \'Last complete hotel list that had it\',
        `listed_missing_runs`   SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'Complete lists in a row without it\',
        `contract_from`         DATE              DEFAULT NULL COMMENT \'First future date with a published price\',
        `contract_to`           DATE              DEFAULT NULL COMMENT \'Last future date with a published price\',
        `contract_last_date`    DATE              DEFAULT NULL COMMENT \'Latest priced date ever seen\',
        `offer_seen_at`         DATETIME          DEFAULT NULL COMMENT \'Last bookable offer seen, on request included\',
        `offer_stay_date`       DATE              DEFAULT NULL COMMENT \'Check-in of that offer\',
        `immediate_seen_at`     DATETIME          DEFAULT NULL COMMENT \'Last offer that could be booked at once\',
        `offer_next_date`       DATE              DEFAULT NULL COMMENT \'Earliest future check-in with an offer in the latest check\',
        `offer_checked_at`      DATETIME          DEFAULT NULL COMMENT \'Last check that answered for this hotel, offer or not\',
        `offer_months`          TEXT              DEFAULT NULL COMMENT \'JSON: stay month => date it was last seen\',
        `last_booking_check_in` DATE              DEFAULT NULL COMMENT \'Check-in of the latest confirmed booking\',
        `backfilled_at`         DATETIME          DEFAULT NULL COMMENT \'Past seasons filled in from stored data\',
        `state`                 VARCHAR(20)       NOT NULL DEFAULT \'no_data\',
        `state_since`           DATETIME          DEFAULT NULL,
        `state_reason`          VARCHAR(32)       NOT NULL DEFAULT \'\',
        `season_published`      CHAR(1)           NOT NULL DEFAULT \'N\' COMMENT \'Between seasons, next season already priced or offered\',
        `gate_hidden`           CHAR(1)           NOT NULL DEFAULT \'N\' COMMENT \'This gate set the product to Hidden\',
        `override`              VARCHAR(16)       NOT NULL DEFAULT \'\' COMMENT \'Admin decision: keep_visible, keep_hidden, closed\',
        `override_note`         VARCHAR(255)      NOT NULL DEFAULT \'\',
        `override_by`           INT UNSIGNED      NOT NULL DEFAULT 0,
        `override_at`           DATETIME          DEFAULT NULL,
        `redirect_to`           VARCHAR(255)      NOT NULL DEFAULT \'\' COMMENT \'Closed hotels: target product, category or URL\',
        `created_at`            DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT \'Recording started\',
        `updated_at`            TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`provider`, `hotel_id`),
        KEY `idx_product` (`product_id`),
        KEY `idx_state` (`provider`, `state`),
        KEY `idx_destination` (`provider`, `destination_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';

    /** Same statement as addon.xml's install/upgrade items (AvailabilityTablesTest pins it). */
    public const string CREATE_LOG_SQL = 'CREATE TABLE IF NOT EXISTS `?:travel_hotel_state_log` (
        `log_id`     BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `provider`   VARCHAR(32)  NOT NULL,
        `hotel_id`   VARCHAR(64)  NOT NULL,
        `product_id` INT UNSIGNED NOT NULL DEFAULT 0,
        `old_state`  VARCHAR(20)  NOT NULL DEFAULT \'\',
        `new_state`  VARCHAR(20)  NOT NULL DEFAULT \'\',
        `old_status` CHAR(1)      NOT NULL DEFAULT \'\' COMMENT \'Product status before: A, H or D; empty if unchanged\',
        `new_status` CHAR(1)      NOT NULL DEFAULT \'\',
        `reason`     VARCHAR(32)  NOT NULL DEFAULT \'\',
        `user_id`    INT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'0 = the weekly step\',
        `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_hotel` (`provider`, `hotel_id`),
        KEY `idx_created` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';

    private static bool $ensured = false;

    /** Creates both tables when missing; once per request. */
    public static function ensureTables(): void
    {
        if (!self::$ensured) {
            db_query(self::CREATE_RECORDS_SQL);
            db_query(self::CREATE_LOG_SQL);
            self::$ensured = true;
        }
    }
}
