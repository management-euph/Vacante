<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Repository;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * The circuits' departures (docs/circuit-page-plan.md §3).
 *
 * ?:travel_circuit_departures holds one row per supplier circuit, city it
 * leaves from and date: the supplier's price for 2 adults that day and,
 * when the supplier says, whether it books at once and the seats left. Each
 * supplier's sync writes what it read (Sphinx: circuits/rates); the
 * circuit's page, its date picker and the admin read the departures here,
 * with no call to the supplier.
 *
 * A shared table, keyed as ?:travel_hotel_availability is: the supplier's
 * registry name and its own ids, as text (Sphinx's are numbers, Eurosite's
 * are "196|EU" and "ROBCH1"). The price is the supplier's: the store's
 * commission is added where a price is shown, so a commission change needs
 * no new sync.
 */
final class CircuitDepartureRepository
{
    /** Same statement as addon.xml's install/upgrade items (CircuitDeparturesTableTest pins it). */
    public const string CREATE_SQL = 'CREATE TABLE IF NOT EXISTS `?:travel_circuit_departures` (
        `provider`       VARCHAR(32)       NOT NULL COMMENT \'Registry name: sphinx, eurosite\',
        `circuit_id`     VARCHAR(64)       NOT NULL COMMENT \'Supplier circuit id\',
        `departure_code` VARCHAR(64)       NOT NULL DEFAULT \'\' COMMENT \'Supplier id of the city it leaves from; empty when the supplier names none\',
        `departure_date` DATE              NOT NULL,
        `departure_name` VARCHAR(128)      NOT NULL DEFAULT \'\' COMMENT \'The city, as the supplier names it\',
        `price`          DECIMAL(12,2)     NOT NULL DEFAULT 0 COMMENT \'Supplier price for 2 adults, before the store commission: the lowest of that date\',
        `currency`       CHAR(3)           NOT NULL DEFAULT \'\',
        `availability`   VARCHAR(12)       NOT NULL DEFAULT \'\' COMMENT \'immediate or on_request; empty when the supplier does not say\',
        `seats`          SMALLINT UNSIGNED DEFAULT NULL COMMENT \'Seats left, when the supplier says\',
        `updated_at`     DATETIME          NOT NULL COMMENT \'The reading that last had it\',
        PRIMARY KEY (`provider`, `circuit_id`, `departure_code`, `departure_date`),
        KEY `idx_date` (`provider`, `departure_date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';

    /** Rows per multi-row statement. */
    private const int CHUNK = 500;

    private static bool $ensured = false;

    /** Creates the table when missing; once per request. */
    public static function ensureTable(): void
    {
        if (!self::$ensured) {
            db_query(self::CREATE_SQL);
            self::$ensured = true;
        }
    }

    /** Lets a test run the heal again (it runs once per request). */
    public static function resetEnsured(): void
    {
        self::$ensured = false;
    }

    /**
     * One complete reading of a supplier's departures: its rows are written
     * (a departure already stored takes the new price), and the stored ones
     * the reading no longer has are removed, so a date that passed, sold out
     * or was withdrawn leaves with it.
     *
     * Call it only with a reading that answered in full: what it lacks is
     * deleted. An empty reading writes and removes nothing, so an outage
     * that looks like "no departures" never empties the table.
     *
     * $circuitIds narrows what may be removed to those circuits, for a
     * supplier that reads its circuits a few at a time (a search by city);
     * null means the reading covers every circuit of the supplier.
     *
     * A row needs its circuit and a Y-m-d date; the same circuit, city and
     * date twice keeps the lowest price.
     *
     * @param list<array{circuit_id: string, departure_code: string, departure_name: string, departure_date: string, price: float, currency: string, availability?: string, seats?: int|null}> $rows
     * @param list<string>|null $circuitIds the circuits the reading covers; null for all of the supplier's
     *
     * @return array{written: int, removed: int}
     */
    public function replace(string $provider, array $rows, string $now, ?array $circuitIds = null): array
    {
        $rows = self::distinct($rows);
        if ($provider === '' || $rows === []) {
            return ['written' => 0, 'removed' => 0];
        }
        self::ensureTable();

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            $values = array_map(static fn (array $row): string => '(' . implode(', ', [
                db_quote('?s', $provider),
                db_quote('?s', $row['circuit_id']),
                db_quote('?s', $row['departure_code']),
                db_quote('?s', $row['departure_date']),
                db_quote('?s', $row['departure_name']),
                db_quote('?d', $row['price']),
                db_quote('?s', $row['currency']),
                db_quote('?s', $row['availability'] ?? ''),
                isset($row['seats']) ? db_quote('?i', $row['seats']) : 'NULL',
                db_quote('?s', $now),
            ]) . ')', $chunk);
            db_query(
                'INSERT INTO ?:travel_circuit_departures
                    (provider, circuit_id, departure_code, departure_date, departure_name, price, currency, availability, seats, updated_at)
                 VALUES ?p
                 ON DUPLICATE KEY UPDATE
                    departure_name = VALUES(departure_name),
                    price = VALUES(price),
                    currency = VALUES(currency),
                    availability = VALUES(availability),
                    seats = VALUES(seats),
                    updated_at = VALUES(updated_at)',
                implode(', ', $values),
            );
        }

        // What this reading did not have: every row it wrote carries $now.
        $removed = 0;
        if ($circuitIds === null) {
            $removed = TypeCoerce::toInt(db_query(
                'DELETE FROM ?:travel_circuit_departures WHERE provider = ?s AND updated_at < ?s',
                $provider,
                $now,
            ));
        } else {
            foreach (array_chunk($circuitIds, self::CHUNK * 2) as $ids) {
                $removed += TypeCoerce::toInt(db_query(
                    'DELETE FROM ?:travel_circuit_departures WHERE provider = ?s AND circuit_id IN (?a) AND updated_at < ?s',
                    $provider,
                    $ids,
                    $now,
                ));
            }
        }

        return ['written' => count($rows), 'removed' => $removed];
    }

    /**
     * A circuit's departures from $from on (Y-m-d), by date, then by city.
     *
     * @return list<array{departure_code: string, departure_name: string, departure_date: string, price: float, currency: string, availability: string, seats: int|null}>
     */
    public function forCircuit(string $provider, string $circuitId, string $from): array
    {
        if ($provider === '' || $circuitId === '') {
            return [];
        }
        self::ensureTable();

        $departures = [];
        foreach (TypeCoerce::toRowList(db_get_array(
            'SELECT departure_code, departure_name, departure_date, price, currency, availability, seats
             FROM ?:travel_circuit_departures
             WHERE provider = ?s AND circuit_id = ?s AND departure_date >= ?s
             ORDER BY departure_date, departure_name, departure_code',
            $provider,
            $circuitId,
            $from,
        )) as $row) {
            $departures[] = [
                'departure_code' => TypeCoerce::toString($row['departure_code'] ?? ''),
                'departure_name' => TypeCoerce::toString($row['departure_name'] ?? ''),
                'departure_date' => TypeCoerce::toString($row['departure_date'] ?? ''),
                'price' => TypeCoerce::toFloat($row['price'] ?? 0),
                'currency' => TypeCoerce::toString($row['currency'] ?? ''),
                'availability' => TypeCoerce::toString($row['availability'] ?? ''),
                'seats' => isset($row['seats']) ? TypeCoerce::toInt($row['seats']) : null,
            ];
        }

        return $departures;
    }

    /**
     * The rows that can be stored, each circuit, city and date once: one
     * without its circuit or a Y-m-d date is left out, and of two for the
     * same departure the cheaper stays.
     *
     * @param list<array{circuit_id: string, departure_code: string, departure_name: string, departure_date: string, price: float, currency: string, availability?: string, seats?: int|null}> $rows
     *
     * @return list<array{circuit_id: string, departure_code: string, departure_name: string, departure_date: string, price: float, currency: string, availability?: string, seats?: int|null}>
     */
    private static function distinct(array $rows): array
    {
        $byKey = [];
        foreach ($rows as $row) {
            if ($row['circuit_id'] === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $row['departure_date']) !== 1) {
                continue;
            }
            $key = $row['circuit_id'] . "\n" . $row['departure_code'] . "\n" . $row['departure_date'];
            if (!isset($byKey[$key]) || $row['price'] < $byKey[$key]['price']) {
                $byKey[$key] = $row;
            }
        }

        return array_values($byKey);
    }
}
