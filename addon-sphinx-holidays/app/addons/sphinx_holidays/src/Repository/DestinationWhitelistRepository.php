<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Repository;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Repository\RowNarrowingTrait;

/**
 * Repository for sphinx_destination_whitelist table.
 *
 * Manages which destinations are enabled for sync.
 *
 * @since 1.2.0
 */
class DestinationWhitelistRepository
{
    use RowNarrowingTrait;

    /**
     * Get distinct country codes from whitelisted destinations.
     *
     * @return list<string>
     */
    public function getCountryCodes(): array
    {
        return self::asStringList(db_get_fields(
            "SELECT DISTINCT d.country_code FROM ?:sphinx_destination_whitelist w
             JOIN ?:sphinx_destinations d ON w.destination_id = d.destination_id
             WHERE d.country_code != ''
             ORDER BY d.country_code",
        ));
    }

    /**
     * Get all whitelist entries.
     *
     * @return list<array<string, mixed>>
     */
    public function findAll(): array
    {
        return self::asRowList(db_get_array('SELECT destination_id, selection_type FROM ?:sphinx_destination_whitelist'));
    }

    /**
     * Every whitelist row with its destination's type and country, for the
     * Destination whitelist page (Services\DestinationsPicker::scope()).
     *
     * @return list<array{destination_id: int, selection_type: string, type: string, country_code: string}>
     */
    public function findAllWithPlace(): array
    {
        $out = [];
        foreach (self::asRowList(db_get_array(
            "SELECT w.destination_id, w.selection_type, COALESCE(d.type, '') AS type, COALESCE(d.country_code, '') AS country_code
             FROM ?:sphinx_destination_whitelist w
             LEFT JOIN ?:sphinx_destinations d ON d.destination_id = w.destination_id",
        )) as $row) {
            $out[] = [
                'destination_id' => TypeCoerce::toInt($row['destination_id'] ?? 0),
                'selection_type' => TypeCoerce::toString($row['selection_type'] ?? ''),
                'type' => TypeCoerce::toString($row['type'] ?? ''),
                'country_code' => strtoupper(TypeCoerce::toString($row['country_code'] ?? '')),
            ];
        }

        return $out;
    }

    /**
     * Get country codes for given destination IDs.
     *
     * @param int[] $destinationIds
     * @return array<int, string> destination_id => country_code
     */
    public function getCountryCodesForDestinations(array $destinationIds): array
    {
        if (empty($destinationIds)) {
            return [];
        }
        $raw = db_get_hash_single_array(
            'SELECT destination_id, country_code FROM ?:sphinx_destinations WHERE destination_id IN (?n)',
            ['destination_id', 'country_code'],
            $destinationIds,
        );
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $k => $v) {
            $out[(int) $k] = TypeCoerce::toString($v);
        }
        return $out;
    }

    /**
     * The type of each destination (country, region, city, destination).
     *
     * @param list<int> $destinationIds
     * @return array<int, string> destination_id => type
     */
    public function getTypesForDestinations(array $destinationIds): array
    {
        if ($destinationIds === []) {
            return [];
        }
        $out = [];
        foreach (self::asRowList(db_get_array(
            'SELECT destination_id, type FROM ?:sphinx_destinations WHERE destination_id IN (?n)',
            $destinationIds,
        )) as $row) {
            $out[TypeCoerce::toInt($row['destination_id'] ?? 0)] = TypeCoerce::toString($row['type'] ?? '');
        }

        return $out;
    }

    /**
     * Every destination under the given ones, down the whole tree (region >
     * city > destination): one query per level, at most five levels.
     *
     * @param list<int> $parentIds
     * @return list<int>
     */
    public function getDescendantIds(array $parentIds): array
    {
        $found = [];
        $level = array_values(array_unique(array_filter($parentIds, static fn (int $id): bool => $id > 0)));
        for ($depth = 0; $depth < 5 && $level !== []; $depth++) {
            $children = self::asIntList(db_get_fields(
                'SELECT destination_id FROM ?:sphinx_destinations WHERE parent_id IN (?n)',
                $level,
            ));
            $level = [];
            foreach ($children as $id) {
                if (!isset($found[$id])) {
                    $found[$id] = true;
                    $level[] = $id;
                }
            }
        }

        return array_map('intval', array_keys($found));
    }

    /** When the whitelist was last saved ('' = never): destinations first seen after it are new. */
    public function lastSavedAt(): string
    {
        return TypeCoerce::toString(db_get_field('SELECT MAX(created_at) FROM ?:sphinx_destination_whitelist'));
    }

    /**
     * Get all destination IDs for given country codes.
     *
     * @param string[] $countryCodes
     * @return list<int>
     */
    public function getDestinationIdsByCountry(array $countryCodes): array
    {
        if (empty($countryCodes)) {
            return [];
        }
        return self::asIntList(db_get_fields(
            'SELECT destination_id FROM ?:sphinx_destinations WHERE country_code IN (?a)',
            $countryCodes,
        ));
    }

    /**
     * Count whitelist entries.
     */
    public function count(): int
    {
        return TypeCoerce::toInt(db_get_field('SELECT COUNT(*) FROM ?:sphinx_destination_whitelist'));
    }

    /**
     * Find country destination by country code.
     */
    public function findCountryDestination(string $countryCode): ?int
    {
        $id = TypeCoerce::toInt(db_get_field(
            "SELECT destination_id FROM ?:sphinx_destinations WHERE country_code = ?s AND type = 'country' LIMIT 1",
            $countryCode,
        ));
        return $id > 0 ? $id : null;
    }

    /**
     * Insert a whitelist entry (ignore if exists).
     */
    public function insertIgnore(int $destinationId, string $selectionType = 'all'): void
    {
        db_query(
            'INSERT IGNORE INTO ?:sphinx_destination_whitelist (destination_id, selection_type) VALUES (?i, ?s)',
            $destinationId,
            $selectionType,
        );
    }

    /**
     * Replace the entire whitelist within a transaction.
     *
     * Clears all existing entries and inserts the new list atomically.
     *
     * @param array<array{destination_id: int, selection_type: string}> $entries
     * @throws \Exception On failure (transaction is rolled back)
     */
    public function replaceAll(array $entries): void
    {
        db_query('START TRANSACTION');
        try {
            db_query('DELETE FROM ?:sphinx_destination_whitelist');

            foreach ($entries as $entry) {
                $destId = $entry['destination_id'];
                $selType = $entry['selection_type'] === 'all' ? 'all' : 'specific';
                if ($destId > 0) {
                    db_query(
                        'INSERT INTO ?:sphinx_destination_whitelist (destination_id, selection_type) VALUES (?i, ?s)
                         ON DUPLICATE KEY UPDATE selection_type = ?s',
                        $destId,
                        $selType,
                        $selType,
                    );
                }
            }

            db_query('COMMIT');
        } catch (\Exception $e) {
            db_query('ROLLBACK');
            throw $e;
        }
    }

    /**
     * Get non-country child destination IDs whitelisted for a given country code.
     *
     * @param string $countryCode ISO country code
     * @return list<int>
     */
    public function getWhitelistedChildIdsByCountry(string $countryCode): array
    {
        return self::asIntList(db_get_fields(
            "SELECT w.destination_id FROM ?:sphinx_destination_whitelist w
             JOIN ?:sphinx_destinations d ON w.destination_id = d.destination_id
             WHERE d.country_code = ?s AND d.type != 'country'",
            $countryCode,
        ));
    }

    /**
     * Whitelisted non-country destination ids grouped by their country's
     * DESTINATION id (the key the whitelist admin page keys its state by).
     * ONE query replacing the per-country getWhitelistedChildIdsByCountry()
     * fan-out (server loop + one AJAX call per whitelisted country).
     *
     * @return array<int, list<int>> country destination_id => whitelisted child ids
     */
    public function getWhitelistedChildIdsGroupedByCountryId(): array
    {
        $rows = self::asRowList(db_get_array(
            "SELECT c.destination_id AS country_id, w.destination_id AS child_id
             FROM ?:sphinx_destination_whitelist w
             JOIN ?:sphinx_destinations d ON d.destination_id = w.destination_id AND d.type != 'country'
             JOIN ?:sphinx_destinations c ON c.country_code = d.country_code AND c.type = 'country'
             WHERE d.country_code != ''",
        ));

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[TypeCoerce::toInt($row['country_id'])][] = TypeCoerce::toInt($row['child_id']);
        }
        return $grouped;
    }

    /**
     * Count of whitelisted destinations per PARENT node — for a region this
     * is how many of its direct children ("cities") are whitelisted. One
     * query for the whole whitelist; the admin summary previously walked
     * every region calling getCitiesByParent() and testing each city.
     *
     * @return array<int, int> parent destination_id => whitelisted child count
     */
    public function getWhitelistedCountsByParent(): array
    {
        $raw = db_get_hash_single_array(
            'SELECT d.parent_id, COUNT(*) AS cnt
             FROM ?:sphinx_destination_whitelist w
             JOIN ?:sphinx_destinations d ON d.destination_id = w.destination_id
             GROUP BY d.parent_id',
            ['parent_id', 'cnt'],
        );
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $k => $v) {
            $out[(int) $k] = TypeCoerce::toInt($v);
        }
        return $out;
    }

    /**
     * Get whitelist type counts grouped by destination type.
     *
     * @return array<string, int> type => count (e.g. ['country' => 3, 'region' => 12])
     */
    public function getCountsByDestinationType(): array
    {
        $raw = db_get_hash_single_array(
            'SELECT d.type, COUNT(*) as cnt FROM ?:sphinx_destination_whitelist w
             JOIN ?:sphinx_destinations d ON w.destination_id = d.destination_id
             GROUP BY d.type',
            ['type', 'cnt'],
        );
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $k => $v) {
            if (is_string($k)) {
                $out[$k] = TypeCoerce::toInt($v);
            }
        }
        return $out;
    }

    /**
     * Get sample non-country destination names from the whitelist.
     *
     * @param int $limit Max number of names to return
     * @return list<string>
     */
    public function getSampleNonCountryNames(int $limit = 5): array
    {
        return self::asStringList(db_get_fields(
            "SELECT d.name FROM ?:sphinx_destination_whitelist w
             JOIN ?:sphinx_destinations d ON w.destination_id = d.destination_id
             WHERE d.type != 'country'
             ORDER BY d.name LIMIT ?i",
            $limit,
        ));
    }
}
