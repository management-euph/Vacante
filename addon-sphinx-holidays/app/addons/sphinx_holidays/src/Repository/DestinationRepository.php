<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Repository;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Repository\RowNarrowingTrait;

/**
 * Repository for sphinx_destinations table operations.
 *
 * Provides type-safe read/write access with batch upsert support.
 */
class DestinationRepository
{
    use RowNarrowingTrait;

    /** Destination types that map to the "city" level in the hierarchy. */
    private const array CITY_LEVEL_TYPES = ['city', 'destination', 'resort'];

    /**
     * Lightweight parent lookup cache: id → [name, type, parent_id].
     * Loaded once via loadParentLookup(), reused across resolveHierarchies() calls.
     *
     * @var array<int, array<string, mixed>>|null
     */
    private ?array $parentLookup = null;

    /**
     * Upsert a batch of destinations (INSERT ... ON DUPLICATE KEY UPDATE).
     *
     * @param list<array<string, mixed>> $destinations Array of destination rows, each with keys matching DB columns
     * @return int Number of rows affected
     */
    public function upsertBatch(array $destinations): int
    {
        if (empty($destinations)) {
            return 0;
        }

        $now = date('Y-m-d H:i:s');
        $submitted = 0;

        // Multi-row upsert: one statement per chunk instead of one per
        // destination — a full destination sync (~200k rows) was 200k
        // round-trips in the per-row form. Rows are small (10 scalar
        // columns), so 500 per statement stays far below max_allowed_packet.
        foreach (array_chunk($destinations, 500) as $chunk) {
            $tuples = [];
            $params = [];

            foreach ($chunk as $dest) {
                $id = TypeCoerce::toInt($dest['destination_id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }

                // lat/lng bound as ?s decimal strings — Tygh's ?d placeholder
                // truncates DECIMAL(10,8) to ~2 dp (see HotelRepository).
                $tuples[] = '(?i, ?s, ?s, ?i, ?s, ?i, ?s, ?s, ?i, ?s, ?s)';
                array_push(
                    $params,
                    $id,
                    TypeCoerce::toString($dest['name'] ?? ''),
                    TypeCoerce::toString($dest['type'] ?? 'destination'),
                    TypeCoerce::toInt($dest['parent_id'] ?? 0),
                    TypeCoerce::toString($dest['country_code'] ?? ''),
                    TypeCoerce::toInt($dest['geoname_id'] ?? 0),
                    TypeCoerce::toDecimalString($dest['latitude'] ?? 0, 8),
                    TypeCoerce::toDecimalString($dest['longitude'] ?? 0, 8),
                    TypeCoerce::toInt($dest['hotel_count'] ?? 0),
                    $now,
                    $now,
                );
            }

            if ($tuples === []) {
                continue;
            }

            // first_seen_at is written once, on the insert: the whitelist
            // page calls a destination new when it appeared after the last Save.
            db_query(
                'INSERT INTO ?:sphinx_destinations
                    (destination_id, name, type, parent_id, country_code, geoname_id, latitude, longitude, hotel_count, first_seen_at, last_synced_at)
                 VALUES ' . implode(', ', $tuples) . '
                 ON DUPLICATE KEY UPDATE
                    name = VALUES(name),
                    type = VALUES(type),
                    parent_id = VALUES(parent_id),
                    country_code = VALUES(country_code),
                    geoname_id = VALUES(geoname_id),
                    latitude = VALUES(latitude),
                    longitude = VALUES(longitude),
                    hotel_count = VALUES(hotel_count),
                    last_synced_at = VALUES(last_synced_at)',
                ...$params,
            );

            $submitted += count($tuples);
        }

        return $submitted;
    }

    /**
     * Get all destinations, optionally filtered by type.
     *
     * @param string $type Filter by type (continent, country, region, city, destination)
     * @param int $parentId Filter by parent_id
     * @param int $page Page number (1-based)
     * @param int $perPage Items per page
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function getFiltered(string $type = '', int $parentId = 0, int $page = 1, int $perPage = 50): array
    {
        $condition = '';

        if (!empty($type)) {
            $condition .= db_quote(' AND d.type = ?s', $type);
        }

        if ($parentId > 0) {
            $condition .= db_quote(' AND d.parent_id = ?i', $parentId);
        }

        $total = TypeCoerce::toInt(db_get_field(
            'SELECT COUNT(*) FROM ?:sphinx_destinations d WHERE 1 ?p',
            $condition,
        ));

        $offset = ($page - 1) * $perPage;

        $items = self::asRowList(db_get_array(
            'SELECT d.* FROM ?:sphinx_destinations d
             WHERE 1 ?p
             ORDER BY d.type ASC, d.name ASC
             LIMIT ?i, ?i',
            $condition,
            $offset,
            $perPage,
        ));

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Get a single destination by ID.
     * @return array<string, mixed>|null
     */
    public function getById(int $id): ?array
    {
        $row = self::asRow(db_get_row(
            'SELECT * FROM ?:sphinx_destinations WHERE destination_id = ?i',
            $id,
        ));

        return $row === [] ? null : $row;
    }

    /**
     * Get children of a parent destination.
     * @return list<array<string, mixed>>
     */
    public function getChildren(int $parentId): array
    {
        return self::asRowList(db_get_array(
            'SELECT * FROM ?:sphinx_destinations WHERE parent_id = ?i ORDER BY name ASC',
            $parentId,
        ));
    }

    /**
     * Get all countries (type = 'country'), ordered by name.
     * @return list<array<string, mixed>>
     */
    public function getCountries(): array
    {
        return self::asRowList(db_get_array(
            "SELECT * FROM ?:sphinx_destinations WHERE type = 'country' ORDER BY name ASC",
        ));
    }

    /**
     * Get destination counts by type.
     *
     * @return array<string, int> e.g. ['continent' => 5, 'country' => 120, ...]
     */
    public function getCountsByType(): array
    {
        $rows = self::asRowList(db_get_array(
            'SELECT type, COUNT(*) as cnt FROM ?:sphinx_destinations GROUP BY type ORDER BY type',
        ));

        $counts = [];
        foreach ($rows as $row) {
            $counts[TypeCoerce::toString($row['type'] ?? '')] = TypeCoerce::toInt($row['cnt'] ?? 0);
        }

        return $counts;
    }

    /**
     * Get total number of destinations.
     */
    public function getTotal(): int
    {
        return TypeCoerce::toInt(db_get_field('SELECT COUNT(*) FROM ?:sphinx_destinations'));
    }

    /**
     * Get last sync timestamp.
     */
    public function getLastSyncedAt(): ?string
    {
        $val = TypeCoerce::toString(db_get_field(
            'SELECT MAX(last_synced_at) FROM ?:sphinx_destinations',
        ));

        return $val === '' ? null : $val;
    }

    /**
     * Delete all destinations (used before full re-sync if needed).
     */
    public function truncate(): void
    {
        db_query('DELETE FROM ?:sphinx_destinations');
    }

    /**
     * Get regions for a country (direct children of the country destination).
     *
     * @param string $countryCode ISO country code (e.g. 'GR')
     * @return list<array<string, mixed>> Regions with destination_id, name, type, hotel_count
     */
    public function getRegionsByCountry(string $countryCode): array
    {
        $countryId = TypeCoerce::toInt(db_get_field(
            "SELECT destination_id FROM ?:sphinx_destinations WHERE country_code = ?s AND type = 'country' LIMIT 1",
            $countryCode,
        ));

        if ($countryId <= 0) {
            return [];
        }

        return self::asRowList(db_get_array(
            'SELECT destination_id, name, type, hotel_count FROM ?:sphinx_destinations
             WHERE parent_id = ?i ORDER BY name',
            $countryId,
        ));
    }

    /**
     * Get cities/resorts under a parent destination (region or country).
     *
     * @param int $parentId Parent destination ID
     * @return list<array<string, mixed>> Cities with destination_id, name, type, hotel_count
     */
    public function getCitiesByParent(int $parentId): array
    {
        return self::asRowList(db_get_array(
            'SELECT destination_id, name, type, hotel_count FROM ?:sphinx_destinations
             WHERE parent_id = ?i ORDER BY name',
            $parentId,
        ));
    }

    /**
     * Find destinations by exact name (case-insensitive).
     *
     * Returns all matches ordered by hierarchy (region > city > destination)
     * so the caller can pick the most relevant one for disambiguation.
     *
     * @param string $name Destination name (e.g. "Crete", "Athens")
     * @return list<array<string, mixed>> All matching destination rows
     */
    public function findByExactName(string $name): array
    {
        return self::asRowList(db_get_array(
            "SELECT * FROM ?:sphinx_destinations
             WHERE LOWER(name) = ?s
             ORDER BY FIELD(type, 'continent', 'country', 'region', 'city', 'destination') ASC",
            mb_strtolower($name),
        ));
    }

    /**
     * Search destinations by name — or, for an all-digits query, by exact
     * destination_id as well (the id shown in the admin grid / used by the
     * hotels sync). Backs both the destinations admin grid and the whitelist
     * autocomplete. The LIKE stays in the numeric branch so destinations
     * whose NAME contains the digits remain findable.
     *
     * @param string $query Search term (name fragment or numeric destination id)
     * @param int $limit Max results
     * @return list<array<string, mixed>>
     */
    public function search(string $query, int $limit = 20): array
    {
        $trimmed = trim($query);
        $escaped = addcslashes($trimmed, '%_\\');

        if (ctype_digit($trimmed)) {
            return self::asRowList(db_get_array(
                'SELECT * FROM ?:sphinx_destinations WHERE name LIKE ?l OR destination_id = ?i ORDER BY type ASC, name ASC LIMIT ?i',
                '%' . $escaped . '%',
                (int) $trimmed,
                $limit,
            ));
        }

        return self::asRowList(db_get_array(
            'SELECT * FROM ?:sphinx_destinations WHERE name LIKE ?l ORDER BY type ASC, name ASC LIMIT ?i',
            '%' . $escaped . '%',
            $limit,
        ));
    }

    /**
     * Load destination rows in chunks, keyed by destination_id.
     *
     * Generic chunk-loader for building in-memory lookups without loading
     * all 200k+ rows in a single query. ~80 bytes/row × 200k ≈ 16 MB.
     *
     * @param array<string> $columns Columns to select (must include destination_id)
     * @param callable(array<string, mixed>): array<string, mixed> $mapper transforms each row into the stored value
     * @return array<int, array<string, mixed>> Keyed by destination_id
     */
    private function loadChunked(array $columns, callable $mapper): array
    {
        $lookup = [];
        $chunkSize = 10000;
        $offset = 0;
        $cols = implode(', ', $columns);

        while (true) {
            $chunk = self::asRowList(db_get_array(
                "SELECT {$cols} FROM ?:sphinx_destinations ORDER BY destination_id LIMIT ?i, ?i",
                $offset,
                $chunkSize,
            ));

            if (empty($chunk)) {
                break;
            }

            foreach ($chunk as $row) {
                $lookup[TypeCoerce::toInt($row['destination_id'] ?? 0)] = $mapper($row);
            }

            if (count($chunk) < $chunkSize) {
                break;
            }
            $offset += $chunkSize;
        }

        return $lookup;
    }

    /**
     * Build full_path breadcrumbs for all destinations by walking parent_id chains.
     *
     * Generates paths like "Athens, Attica, Greece, Europe" for disambiguation.
     * Called after destination sync completes.
     *
     * @return int Number of paths updated
     */
    public function buildFullPaths(): int
    {
        $parentLookup = $this->loadChunked(
            ['destination_id', 'name', 'parent_id'],
            static fn (array $row): array => [
                'name' => TypeCoerce::toString($row['name'] ?? ''),
                'parent_id' => TypeCoerce::toInt($row['parent_id'] ?? 0),
            ],
        );

        if (empty($parentLookup)) {
            return 0;
        }

        // Process updates in chunks: walk chains and update full_path
        $updated = 0;
        $ids = array_keys($parentLookup);
        $idChunks = array_chunk($ids, 500);

        foreach ($idChunks as $idBatch) {
            foreach ($idBatch as $id) {
                $row = $parentLookup[$id];
                $segments = [TypeCoerce::toString($row['name'] ?? '')];
                $currentId = TypeCoerce::toInt($row['parent_id'] ?? 0);
                $visited = [$id => true]; // cycle protection

                // Walk up the parent chain (max depth 5)
                while ($currentId > 0 && isset($parentLookup[$currentId]) && !isset($visited[$currentId])) {
                    $visited[$currentId] = true;
                    $segments[] = TypeCoerce::toString($parentLookup[$currentId]['name'] ?? '');
                    $currentId = TypeCoerce::toInt($parentLookup[$currentId]['parent_id'] ?? 0);
                }

                $fullPath = implode(', ', $segments);
                db_query(
                    'UPDATE ?:sphinx_destinations SET full_path = ?s WHERE destination_id = ?i',
                    $fullPath,
                    $id,
                );
                $updated++;
            }
        }

        return $updated;
    }

    /**
     * Get all continents with country count.
     *
     * @return list<array<string, mixed>> Continents with destination_id, name, type, country_count
     */
    public function getContinents(): array
    {
        return self::asRowList(db_get_array(
            "SELECT d.destination_id, d.name, d.type,
                    (SELECT COUNT(*) FROM ?:sphinx_destinations c WHERE c.parent_id = d.destination_id) AS country_count
             FROM ?:sphinx_destinations d
             WHERE d.type = 'continent'
             ORDER BY d.name ASC",
        ));
    }

    /**
     * Load the full destination parent lookup into memory.
     *
     * Call once before processing batches; reused by resolveHierarchies().
     *
     * @return bool True if lookup has data, false if sphinx_destinations is empty
     */
    public function loadParentLookup(): bool
    {
        if ($this->parentLookup !== null) {
            return !empty($this->parentLookup);
        }

        $this->parentLookup = $this->loadChunked(
            ['destination_id', 'name', 'type', 'parent_id', 'country_code'],
            static fn (array $row): array => [
                'name' => TypeCoerce::toString($row['name'] ?? ''),
                'type' => TypeCoerce::toString($row['type'] ?? ''),
                'parent_id' => TypeCoerce::toInt($row['parent_id'] ?? 0),
                'country_code' => TypeCoerce::toString($row['country_code'] ?? ''),
            ],
        );

        return !empty($this->parentLookup);
    }

    /**
     * Resolve destination hierarchies for a set of destination IDs.
     *
     * Walks each destination's parent_id chain to extract city, region, and country names.
     * Requires loadParentLookup() to have been called first.
     *
     * own_id/own_type/own_name describe the hotel's OWN node (walk depth 0).
     * HotelLocationResolver needs them to tell "the hotel points at a region"
     * from "the hotel points at a destination inside that region" — the tree
     * alone cannot express that difference once the walk has climbed.
     *
     * @param array<int> $destinationIds List of destination IDs to resolve
     * @return array<int, array{city: string, region: string, region_id: int, country: string, country_code: string, own_id: int, own_type: string, own_name: string}> Keyed by destination_id
     */
    public function resolveHierarchies(array $destinationIds): array
    {
        if (empty($destinationIds) || empty($this->parentLookup)) {
            return [];
        }

        $result = [];
        foreach ($destinationIds as $destId) {
            $destId = (int) $destId;
            $hierarchy = [
                'city' => '', 'region' => '', 'region_id' => 0, 'country' => '', 'country_code' => '',
                'own_id' => 0, 'own_type' => '', 'own_name' => '',
            ];

            $currentId = $destId;
            $visited = [];

            // Walk up parent chain (max depth ~5: destination → city → region → country → continent)
            while ($currentId > 0 && isset($this->parentLookup[$currentId]) && !isset($visited[$currentId])) {
                $visited[$currentId] = true;
                $node = $this->parentLookup[$currentId];
                $type = TypeCoerce::toString($node['type'] ?? '');
                $name = TypeCoerce::toString($node['name'] ?? '');

                if ($currentId === $destId) {
                    $hierarchy['own_id'] = $currentId;
                    $hierarchy['own_type'] = $type;
                    $hierarchy['own_name'] = $name;
                }

                if (in_array($type, self::CITY_LEVEL_TYPES, true)) {
                    // First city-level node wins (the hotel's own destination)
                    if ($hierarchy['city'] === '') {
                        $hierarchy['city'] = $name;
                    }
                } elseif ($type === 'region') {
                    if ($hierarchy['region'] === '') {
                        $hierarchy['region'] = $name;
                        $hierarchy['region_id'] = $currentId;
                    }
                } elseif ($type === 'country') {
                    $hierarchy['country'] = $name;
                    $hierarchy['country_code'] = TypeCoerce::toString($node['country_code'] ?? '');
                }

                $currentId = TypeCoerce::toInt($node['parent_id'] ?? 0);
            }

            $result[$destId] = $hierarchy;
        }

        return $result;
    }

    /**
     * Get the country_code for a destination by its ID.
     *
     * @return string|null Country code or null if not found
     */
    public function getCountryCodeById(int $destinationId): ?string
    {
        $code = TypeCoerce::toString(db_get_field(
            'SELECT country_code FROM ?:sphinx_destinations WHERE destination_id = ?i',
            $destinationId,
        ));
        return $code === '' ? null : $code;
    }

    /**
     * Count city-level destinations (city, destination) for a given country code.
     *
     * @param string $countryCode ISO country code
     */
    public function countCitiesByCountry(string $countryCode): int
    {
        return TypeCoerce::toInt(db_get_field(
            "SELECT COUNT(*) FROM ?:sphinx_destinations WHERE country_code = ?s AND type IN ('city','destination')",
            $countryCode,
        ));
    }

    /**
     * countCitiesByCountry() for many countries in ONE query.
     *
     * @param list<string> $countryCodes
     * @return array<string, int> country_code => city-level destination count
     */
    public function countCitiesByCountries(array $countryCodes): array
    {
        if ($countryCodes === []) {
            return [];
        }
        $raw = db_get_hash_single_array(
            "SELECT country_code, COUNT(*) AS cnt FROM ?:sphinx_destinations
             WHERE country_code IN (?a) AND type IN ('city','destination')
             GROUP BY country_code",
            ['country_code', 'cnt'],
            $countryCodes,
        );
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $k => $v) {
            $out[(string) $k] = TypeCoerce::toInt($v);
        }
        return $out;
    }

    /**
     * Region rows (direct children of each country node) with the count of
     * their OWN direct children ("cities"), for many countries in ONE query.
     * Hierarchy semantics mirror getRegionsByCountry() + getCitiesByParent(),
     * which the whitelist admin summary previously called per country and
     * per region (N+1 at whitelist scale).
     *
     * @param list<string> $countryCodes
     * @return array<string, list<array{region_id: int, total_cities: int}>> country_code => region stats
     */
    public function getRegionChildStatsByCountry(array $countryCodes): array
    {
        if ($countryCodes === []) {
            return [];
        }
        $rows = self::asRowList(db_get_array(
            "SELECT c.country_code, r.destination_id AS region_id,
                    COUNT(ct.destination_id) AS total_cities
             FROM ?:sphinx_destinations c
             JOIN ?:sphinx_destinations r ON r.parent_id = c.destination_id
             LEFT JOIN ?:sphinx_destinations ct ON ct.parent_id = r.destination_id
             WHERE c.type = 'country' AND c.country_code IN (?a)
             GROUP BY c.country_code, r.destination_id",
            $countryCodes,
        ));

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[TypeCoerce::toString($row['country_code'])][] = [
                'region_id' => TypeCoerce::toInt($row['region_id']),
                'total_cities' => TypeCoerce::toInt($row['total_cities']),
            ];
        }
        return $grouped;
    }

    /**
     * Find destinations by name or full_path prefix.
     *
     * "Athens" matches by name. "Athens, Greece" matches via full_path prefix,
     * enabling unambiguous disambiguation (the "Athens Problem").
     *
     * @param string $query Destination name or partial full_path
     * @return list<array<string, mixed>> Matching destination rows, ordered by hierarchy level
     */
    public function findByNameOrPath(string $query): array
    {
        $lower = mb_strtolower(trim($query));

        return self::asRowList(db_get_array(
            "SELECT * FROM ?:sphinx_destinations
             WHERE LOWER(name) = ?s OR LOWER(full_path) LIKE ?l
             ORDER BY FIELD(type, 'continent', 'country', 'region', 'city', 'destination') ASC",
            $lower,
            $lower . '%',
        ));
    }
}
