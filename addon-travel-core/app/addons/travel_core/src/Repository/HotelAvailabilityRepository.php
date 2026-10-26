<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Repository;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

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
        `hotel_name`            VARCHAR(255)      NOT NULL DEFAULT \'\' COMMENT \'As the supplier lists it, for the report\',
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

    /**
     * Columns added to ?:travel_hotel_availability after it first shipped:
     * name => definition, placed as in CREATE_RECORDS_SQL. CREATE TABLE IF
     * NOT EXISTS leaves an existing table as it is, so these are added.
     */
    public const array ADDED_COLUMNS = [
        'hotel_name' => "VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'As the supplier lists it, for the report' AFTER `destination_key`",
    ];

    private static bool $ensured = false;

    /** Rows per multi-row statement. */
    private const int CHUNK = 500;

    /**
     * Days a hotel stays in its group's size (listedCount()) after the last
     * list that had it: a group that really shrank is measured by its new
     * size after this long, instead of every later list looking short.
     */
    public const int LIST_MEMORY_DAYS = 30;

    /** Creates both tables, and any column added since, when missing; once per request. */
    public static function ensureTables(): void
    {
        if (!self::$ensured) {
            db_query(self::CREATE_RECORDS_SQL);
            db_query(self::CREATE_LOG_SQL);
            foreach (self::ADDED_COLUMNS as $column => $definition) {
                if (TypeCoerce::toString(db_get_field('SHOW COLUMNS FROM ?:travel_hotel_availability LIKE ?s', $column)) === '') {
                    db_query('ALTER TABLE ?:travel_hotel_availability ADD COLUMN `' . $column . '` ' . $definition);
                }
            }
            self::$ensured = true;
        }
    }

    /** Lets a test run the heal again (it runs once per request). */
    public static function resetEnsured(): void
    {
        self::$ensured = false;
    }

    /**
     * One complete hotel-list answer (a country, a city): its hotels were
     * seen now, and the group's other recorded hotels missed one more list.
     * Call it only for an answer ListCompleteness accepts: an outage must
     * never count as hotels gone.
     *
     * @param list<array{hotel_id: string, product_id: int, destination_key: string, hotel_name: string, known_since: ?string}> $seen
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
                db_quote('?s', $row['hotel_name']),
                self::orNull($row['known_since']),
                db_quote('?s', $now),
            ]) . ', 0)', $chunk);
            db_query(
                'INSERT INTO ?:travel_hotel_availability
                    (provider, hotel_id, product_id, destination_key, hotel_name, known_since, listed_last_seen_at, listed_missing_runs)
                 VALUES ?p
                 ON DUPLICATE KEY UPDATE
                    product_id = VALUES(product_id),
                    destination_key = VALUES(destination_key),
                    hotel_name = VALUES(hotel_name),
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
     * The group's size, which ListCompleteness measures a new list against:
     * its hotels a list had within the last LIST_MEMORY_DAYS and that no
     * complete list has missed since.
     *
     * @param list<string> $hotelIds every hotel of the group (a country, a city)
     */
    public function listedCount(string $provider, array $hotelIds, string $now): int
    {
        self::ensureTables();
        $since = date('Y-m-d H:i:s', (int) strtotime($now . ' -' . self::LIST_MEMORY_DAYS . ' days'));
        $count = 0;
        foreach (array_chunk($hotelIds, self::CHUNK * 2) as $chunk) {
            $count += TypeCoerce::toInt(db_get_field(
                'SELECT COUNT(*) FROM ?:travel_hotel_availability
                 WHERE provider = ?s AND hotel_id IN (?a) AND listed_missing_runs = 0 AND listed_last_seen_at >= ?s',
                $provider,
                $chunk,
                $since,
            ));
        }

        return $count;
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
     * A destination's whole answer to a supplier's check (§4.3, §15.3 point
     * 5): every hotel the destination holds was checked now, and those with
     * an offer were seen, with their stays. Call it only for an answer that
     * came back whole (every date answered, every search polled to its end);
     * a part of an answer goes to recordOffersSeen(), which writes positives
     * only, since a date that did not answer may have held the offer.
     *
     * @param list<array{hotel_id: string, product_id: int, destination_key: string, hotel_name: string}> $hotels every hotel of the destination
     * @param array<array-key, array{stay_date: string, next_date: string, immediate: bool, months: list<string>}> $offers OfferTally::offers()
     */
    public function recordCheck(string $provider, array $hotels, array $offers, string $now): void
    {
        self::ensureTables();
        foreach (array_chunk($hotels, self::CHUNK) as $chunk) {
            $values = [];
            foreach ($chunk as $hotel) {
                $offer = $offers[$hotel['hotel_id']] ?? null;
                $values[] = '(' . implode(', ', [
                    ...self::identityValues($provider, $hotel),
                    db_quote('?s', $now),
                    self::orNull($offer['next_date'] ?? null),
                    ...self::offerValues($offer, $now),
                ]) . ')';
            }
            // offer_seen_at goes last, so the IF() before it reads the old one.
            db_query(
                'INSERT INTO ?:travel_hotel_availability
                    (provider, hotel_id, product_id, destination_key, hotel_name, offer_checked_at, offer_next_date,
                     offer_seen_at, offer_stay_date, immediate_seen_at, offer_months)
                 VALUES ?p
                 ON DUPLICATE KEY UPDATE
                    product_id = VALUES(product_id),
                    destination_key = IF(VALUES(destination_key) <> \'\', VALUES(destination_key), destination_key),
                    hotel_name = IF(VALUES(hotel_name) <> \'\', VALUES(hotel_name), hotel_name),
                    offer_checked_at = VALUES(offer_checked_at),
                    offer_next_date = VALUES(offer_next_date),
                    offer_months = IF(VALUES(offer_months) IS NULL, offer_months, JSON_MERGE_PATCH(COALESCE(offer_months, \'{}\'), VALUES(offer_months))),
                    immediate_seen_at = COALESCE(VALUES(immediate_seen_at), immediate_seen_at),
                    offer_stay_date = IF(VALUES(offer_seen_at) IS NULL, offer_stay_date, VALUES(offer_stay_date)),
                    offer_seen_at = COALESCE(VALUES(offer_seen_at), offer_seen_at)',
                implode(', ', $values),
            );
        }
    }

    /**
     * Offers a search found for several hotels at once (a destination's
     * answer to a customer's search, or the part of a check that answered).
     * Positives only, at most one write per hotel an hour, like
     * recordOfferSeen(). A hotel without an offer in $offers is left alone;
     * the identity fills a new record, or the fields an older one lacks.
     *
     * @param list<array{hotel_id: string, product_id: int, destination_key: string, hotel_name: string}> $hotels the hotels to record, as the supplier stores them
     * @param array<array-key, array{stay_date: string, next_date: string, immediate: bool, months: list<string>}> $offers OfferTally::offers()
     */
    public function recordOffersSeen(string $provider, array $hotels, array $offers, string $now): void
    {
        $values = [];
        foreach ($hotels as $hotel) {
            $offer = $offers[$hotel['hotel_id']] ?? null;
            if ($offer !== null) {
                $values[] = '(' . implode(', ', [...self::identityValues($provider, $hotel), ...self::offerValues($offer, $now)]) . ')';
            }
        }
        if ($values === []) {
            return;
        }
        self::ensureTables();
        // As in recordOfferSeen(): offer_seen_at goes last, so every IF()
        // reads the old one. An immediate offer has its own hour, so an
        // on-request offer seen first does not hide it.
        $due = 'offer_seen_at IS NULL OR offer_seen_at < VALUES(offer_seen_at) - INTERVAL 1 HOUR';
        foreach (array_chunk($values, self::CHUNK) as $chunk) {
            db_query(
                'INSERT INTO ?:travel_hotel_availability
                    (provider, hotel_id, product_id, destination_key, hotel_name,
                     offer_seen_at, offer_stay_date, immediate_seen_at, offer_months)
                 VALUES ?p
                 ON DUPLICATE KEY UPDATE
                    product_id = IF(product_id = 0, VALUES(product_id), product_id),
                    destination_key = IF(destination_key = \'\', VALUES(destination_key), destination_key),
                    hotel_name = IF(hotel_name = \'\', VALUES(hotel_name), hotel_name),
                    immediate_seen_at = IF(VALUES(immediate_seen_at) IS NOT NULL
                        AND (immediate_seen_at IS NULL OR immediate_seen_at < VALUES(immediate_seen_at) - INTERVAL 1 HOUR),
                        VALUES(immediate_seen_at), immediate_seen_at),
                    offer_months = IF(?p, JSON_MERGE_PATCH(COALESCE(offer_months, \'{}\'), VALUES(offer_months)), offer_months),
                    offer_stay_date = IF(?p, VALUES(offer_stay_date), offer_stay_date),
                    offer_seen_at = IF(?p, VALUES(offer_seen_at), offer_seen_at)',
                implode(', ', $chunk),
                $due,
                $due,
                $due,
            );
        }
    }

    /**
     * Hotels whose published prices start after $today but that had no offer
     * in their latest check, or were never checked: the first day of their
     * prices, so a supplier's check can ask about their own season too
     * (docs/novoton-availability-gaps-plan.md §4.3). Keyed by hotel id; a
     * numeric id comes back as an int key.
     *
     * @param list<string> $hotelIds
     *
     * @return array<array-key, string> hotel_id => Y-m-d
     */
    public function awaitingFirstOffer(string $provider, array $hotelIds, string $today): array
    {
        $hotelIds = array_values(array_unique(array_filter($hotelIds, static fn (string $id): bool => $id !== '')));
        if ($hotelIds === []) {
            return [];
        }
        self::ensureTables();
        $out = [];
        foreach (array_chunk($hotelIds, self::CHUNK) as $chunk) {
            foreach (TypeCoerce::toRowList(db_get_array(
                'SELECT hotel_id, contract_from FROM ?:travel_hotel_availability
                 WHERE provider = ?s AND hotel_id IN (?a) AND contract_from > ?s AND offer_next_date IS NULL',
                $provider,
                $chunk,
                $today,
            )) as $row) {
                $from = substr(TypeCoerce::toString($row['contract_from'] ?? ''), 0, 10);
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) === 1) {
                    $out[TypeCoerce::toString($row['hotel_id'] ?? '')] = $from;
                }
            }
        }

        return $out;
    }

    /**
     * provider, hotel_id, product_id, destination_key, hotel_name, quoted.
     *
     * @param array{hotel_id: string, product_id: int, destination_key: string, hotel_name: string} $hotel
     *
     * @return list<string>
     */
    private static function identityValues(string $provider, array $hotel): array
    {
        return [
            db_quote('?s', $provider),
            db_quote('?s', $hotel['hotel_id']),
            db_quote('?i', max(0, $hotel['product_id'])),
            db_quote('?s', mb_substr($hotel['destination_key'], 0, 128)),
            db_quote('?s', mb_substr($hotel['hotel_name'], 0, 255)),
        ];
    }

    /**
     * offer_seen_at, offer_stay_date, immediate_seen_at, offer_months (stay
     * month => the day it was seen), quoted; four NULLs without an offer.
     *
     * @param array{stay_date: string, next_date: string, immediate: bool, months: list<string>}|null $offer
     *
     * @return list<string>
     */
    private static function offerValues(?array $offer, string $now): array
    {
        if ($offer === null) {
            return ['NULL', 'NULL', 'NULL', 'NULL'];
        }
        $months = (string) json_encode(array_fill_keys($offer['months'], substr($now, 0, 10)), JSON_FORCE_OBJECT);

        return [
            db_quote('?s', $now),
            self::orNull($offer['stay_date']),
            $offer['immediate'] ? db_quote('?s', $now) : 'NULL',
            db_quote('?s', $months),
        ];
    }

    /**
     * Past seasons from the supplier's stored data, once per hotel, so the
     * rules can judge "no availability for N months" from the first run.
     * Never moves a value back: a later date already recorded wins.
     *
     * @param list<array{hotel_id: string, product_id: int, destination_key: string, hotel_name: string, known_since: ?string, listed_last_seen_at: ?string, contract_last_date: ?string, last_booking_check_in: ?string}> $rows
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
                db_quote('?s', $row['hotel_name']),
                self::orNull($row['known_since']),
                self::orNull($row['listed_last_seen_at']),
                self::orNull($row['contract_last_date']),
                self::orNull($row['last_booking_check_in']),
                db_quote('?s', $now),
            ]) . ')', $chunk);
            db_query(
                'INSERT INTO ?:travel_hotel_availability
                    (provider, hotel_id, product_id, destination_key, hotel_name, known_since, listed_last_seen_at,
                     contract_last_date, last_booking_check_in, backfilled_at)
                 VALUES ?p
                 ON DUPLICATE KEY UPDATE
                    product_id = IF(VALUES(product_id) > 0, VALUES(product_id), product_id),
                    destination_key = IF(VALUES(destination_key) <> \'\', VALUES(destination_key), destination_key),
                    hotel_name = IF(VALUES(hotel_name) <> \'\', VALUES(hotel_name), hotel_name),
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
