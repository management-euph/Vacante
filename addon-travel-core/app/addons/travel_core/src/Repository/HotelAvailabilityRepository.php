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
        `destination_key`       VARCHAR(128)      NOT NULL DEFAULT \'\' COMMENT \'Resort, city code or destination id: the peer group\',
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

    /** Rows per multi-row statement. */
    private const int CHUNK = 500;

    /** Creates both tables when missing; once per request. */
    public static function ensureTables(): void
    {
        if (!self::$ensured) {
            db_query(self::CREATE_RECORDS_SQL);
            db_query(self::CREATE_LOG_SQL);
            self::$ensured = true;
        }
    }

    /**
     * One complete hotel-list answer (a country, a city): its hotels were
     * seen now, and the group's other recorded hotels missed one more list.
     * Call it only for an answer ListCompleteness accepts: an outage must
     * never count as hotels gone.
     *
     * @param list<array{hotel_id: string, product_id: int, destination_key: string, known_since: ?string}> $seen
     * @param list<string> $missing hotel ids of the group that this answer left out
     */
    public function recordList(string $provider, array $seen, array $missing, string $now): void
    {
        self::ensureTables();
        foreach (array_chunk($seen, self::CHUNK) as $chunk) {
            $values = array_map(static fn (array $row): string => '(' . implode(', ', [
                db_quote('?s', $provider),
                db_quote('?s', $row['hotel_id']),
                db_quote('?i', $row['product_id']),
                db_quote('?s', $row['destination_key']),
                self::orNull($row['known_since']),
                db_quote('?s', $now),
            ]) . ', 0)', $chunk);
            db_query(
                'INSERT INTO ?:travel_hotel_availability
                    (provider, hotel_id, product_id, destination_key, known_since, listed_last_seen_at, listed_missing_runs)
                 VALUES ?p
                 ON DUPLICATE KEY UPDATE
                    product_id = VALUES(product_id),
                    destination_key = VALUES(destination_key),
                    known_since = COALESCE(known_since, VALUES(known_since)),
                    listed_last_seen_at = VALUES(listed_last_seen_at),
                    listed_missing_runs = 0',
                implode(', ', $values),
            );
        }
        foreach (array_chunk($missing, self::CHUNK * 2) as $chunk) {
            db_query(
                'UPDATE ?:travel_hotel_availability SET listed_missing_runs = listed_missing_runs + 1
                 WHERE provider = ?s AND hotel_id IN (?a)',
                $provider,
                $chunk,
            );
        }
    }

    /**
     * The hotel's published price window, rewritten whenever its prices are:
     * the first and last priced future date (both null when none is left).
     * The latest priced date ever seen only moves forward, so past seasons
     * stay on record.
     */
    public function recordPriceWindow(string $provider, string $hotelId, ?string $from, ?string $to): void
    {
        self::ensureTables();
        db_query(
            'INSERT INTO ?:travel_hotel_availability (provider, hotel_id, contract_from, contract_to, contract_last_date)
             VALUES (?s, ?s, ?p, ?p, ?p)
             ON DUPLICATE KEY UPDATE
                contract_last_date = GREATEST(COALESCE(contract_last_date, VALUES(contract_last_date)), COALESCE(VALUES(contract_last_date), contract_last_date)),
                contract_from = VALUES(contract_from),
                contract_to = VALUES(contract_to)',
            $provider,
            $hotelId,
            self::orNull($from),
            self::orNull($to),
            self::orNull($to),
        );
    }

    /**
     * An offer a search found for a stay. Positives only: an empty answer for
     * one occupancy proves nothing. At most one write per hotel an hour, so a
     * busy hotel page does not rewrite the row on every search.
     *
     * @param bool $immediate the offer could be booked at once (not on request)
     */
    public function recordOfferSeen(string $provider, string $hotelId, string $stayDate, bool $immediate, string $now): void
    {
        self::ensureTables();
        $month = substr($stayDate, 0, 7);
        // MySQL assigns left to right, each later expression seeing the new
        // values: offer_seen_at goes last, so every IF() reads the old one.
        $due = 'offer_seen_at IS NULL OR offer_seen_at < VALUES(offer_seen_at) - INTERVAL 1 HOUR';
        db_query(
            'INSERT INTO ?:travel_hotel_availability
                (provider, hotel_id, offer_seen_at, offer_stay_date, immediate_seen_at, offer_months)
             VALUES (?s, ?s, ?s, ?s, ?p, JSON_OBJECT(?s, ?s))
             ON DUPLICATE KEY UPDATE
                offer_months = IF(?p, JSON_SET(COALESCE(offer_months, \'{}\'), ?s, ?s), offer_months),
                immediate_seen_at = IF(?p, COALESCE(VALUES(immediate_seen_at), immediate_seen_at), immediate_seen_at),
                offer_stay_date = IF(?p, VALUES(offer_stay_date), offer_stay_date),
                offer_seen_at = IF(?p, VALUES(offer_seen_at), offer_seen_at)',
            $provider,
            $hotelId,
            $now,
            $stayDate,
            $immediate ? db_quote('?s', $now) : 'NULL',
            $month,
            substr($now, 0, 10),
            $due,
            '$."' . $month . '"',
            substr($now, 0, 10),
            $due,
            $due,
            $due,
        );
    }

    /**
     * Past seasons from the supplier's stored data, once per hotel, so the
     * rules can judge "no availability for N months" from the first run.
     * Never moves a value back: a later date already recorded wins.
     *
     * @param list<array{hotel_id: string, product_id: int, destination_key: string, known_since: ?string, listed_last_seen_at: ?string, contract_last_date: ?string, last_booking_check_in: ?string}> $rows
     */
    public function recordBackfill(string $provider, array $rows, string $now): void
    {
        self::ensureTables();
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            $values = array_map(static fn (array $row): string => '(' . implode(', ', [
                db_quote('?s', $provider),
                db_quote('?s', $row['hotel_id']),
                db_quote('?i', $row['product_id']),
                db_quote('?s', $row['destination_key']),
                self::orNull($row['known_since']),
                self::orNull($row['listed_last_seen_at']),
                self::orNull($row['contract_last_date']),
                self::orNull($row['last_booking_check_in']),
                db_quote('?s', $now),
            ]) . ')', $chunk);
            db_query(
                'INSERT INTO ?:travel_hotel_availability
                    (provider, hotel_id, product_id, destination_key, known_since, listed_last_seen_at,
                     contract_last_date, last_booking_check_in, backfilled_at)
                 VALUES ?p
                 ON DUPLICATE KEY UPDATE
                    product_id = IF(VALUES(product_id) > 0, VALUES(product_id), product_id),
                    destination_key = IF(VALUES(destination_key) <> \'\', VALUES(destination_key), destination_key),
                    known_since = COALESCE(known_since, VALUES(known_since)),
                    listed_last_seen_at = COALESCE(listed_last_seen_at, VALUES(listed_last_seen_at)),
                    contract_last_date = GREATEST(COALESCE(contract_last_date, VALUES(contract_last_date)), COALESCE(VALUES(contract_last_date), contract_last_date)),
                    last_booking_check_in = GREATEST(COALESCE(last_booking_check_in, VALUES(last_booking_check_in)), COALESCE(VALUES(last_booking_check_in), last_booking_check_in)),
                    backfilled_at = VALUES(backfilled_at)',
                implode(', ', $values),
            );
        }
    }

    /** A quoted value, or SQL NULL: CS-Cart's placeholders have no NULL of their own. */
    private static function orNull(?string $value): string
    {
        return $value === null || $value === '' ? 'NULL' : db_quote('?s', $value);
    }
}
