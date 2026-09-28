<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Repository;

use Tygh\Addons\TravelCore\Contracts\FeatureMapRepositoryInterface;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Database-backed repository for travel_feature_map + travel_api_alias.
 *
 * All raw db_*() calls for the feature mapping system live here,
 * extracted from FeatureMapper and the admin controller.
 */
class FeatureMapRepository implements FeatureMapRepositoryInterface
{
    use RowNarrowingTrait;

    // ── Resolve / lookup ──

    #[\Override]
    public function findByAlias(string $apiSource, string $featureType, string $apiValue): ?array
    {
        $result = self::asRow(db_get_row(
            "SELECT m.map_id, m.feature_type, m.canonical_code, m.display_name_en, m.display_name_ro,
                    m.cscart_feature_id, m.cscart_variant_id, m.variant_source
             FROM ?:travel_api_alias a
             JOIN ?:travel_feature_map m ON m.map_id = a.map_id
             WHERE a.api_source = ?s AND m.feature_type = ?s AND m.status = 'A'
               AND (
                   a.api_value = ?s
                   OR (a.match_type = 'prefix' AND ?s LIKE CONCAT(a.api_value, '%'))
                   OR (a.match_type = 'contains' AND ?s LIKE CONCAT('%', a.api_value, '%'))
               )
             ORDER BY FIELD(
                 CASE WHEN a.api_value = ?s THEN 'exact' ELSE a.match_type END,
                 'exact', 'prefix', 'contains'
             ), LENGTH(a.api_value) DESC
             LIMIT 1",
            $apiSource,
            $featureType,
            $apiValue,
            $apiValue,
            $apiValue,
            $apiValue,
        ));

        return $result === [] ? null : $result;
    }

    #[\Override]
    public function getDisplayName(string $featureType, string $canonicalCode, string $lang = 'en'): string
    {
        $column = $lang === 'ro' ? 'display_name_ro' : 'display_name_en';

        return TypeCoerce::toString(db_get_field(
            "SELECT `{$column}` FROM ?:travel_feature_map WHERE feature_type = ?s AND canonical_code = ?s AND status = 'A'",
            $featureType,
            $canonicalCode,
        ));
    }

    /** @return array<string, array<string, mixed>> */
    #[\Override]
    public function allCodes(string $featureType): array
    {
        return self::asRowMap(db_get_hash_array(
            "SELECT canonical_code, map_id, display_name_en, display_name_ro, cscart_variant_id
             FROM ?:travel_feature_map
             WHERE feature_type = ?s AND status = 'A'
             ORDER BY position, canonical_code",
            'canonical_code',
            $featureType,
        ));
    }

    // ── Mutations ──

    #[\Override]
    public function insertMapping(string $featureType, string $canonicalCode, string $nameEn, string $nameRo, ?int $featureId): int
    {
        return TypeCoerce::toInt(db_query(
            "INSERT IGNORE INTO ?:travel_feature_map
             (feature_type, canonical_code, display_name_en, display_name_ro,
              cscart_feature_id, mapping_source, status)
             VALUES (?s, ?s, ?s, ?s, ?i, 'auto', 'A')",
            $featureType,
            $canonicalCode,
            $nameEn,
            $nameRo,
            $featureId ?: null,
        ));
    }

    #[\Override]
    public function findMapId(string $featureType, string $canonicalCode): int
    {
        return TypeCoerce::toInt(db_get_field(
            'SELECT map_id FROM ?:travel_feature_map WHERE feature_type = ?s AND canonical_code = ?s',
            $featureType,
            $canonicalCode,
        ));
    }

    #[\Override]
    public function updateVariantId(int $mapId, int $variantId, string $source = 'auto'): void
    {
        db_query(
            'UPDATE ?:travel_feature_map SET cscart_variant_id = ?i, variant_source = ?s WHERE map_id = ?i',
            $variantId,
            $source,
            $mapId,
        );
    }

    #[\Override]
    public function updateFeatureId(int $mapId, int $featureId): void
    {
        db_query(
            'UPDATE ?:travel_feature_map SET cscart_feature_id = ?i WHERE map_id = ?i',
            $featureId,
            $mapId,
        );
    }

    #[\Override]
    /**
     * @param array<string, mixed> $data
     */
    public function updateMapping(int $mapId, array $data): void
    {
        if (!empty($data)) {
            db_query('UPDATE ?:travel_feature_map SET ?u WHERE map_id = ?i', $data, $mapId);
        }
    }

    #[\Override]
    /**
     * @param array<string, mixed> $mapIds
     */
    public function bulkUpdateStatus(array $mapIds, string $status): void
    {
        if (!empty($mapIds)) {
            db_query('UPDATE ?:travel_feature_map SET status = ?s WHERE map_id IN (?n)', $status, $mapIds);
        }
    }

    #[\Override]
    /**
     * @param array<string, mixed> $mapIds
     */
    public function deleteMappings(array $mapIds): void
    {
        if (!empty($mapIds)) {
            db_query('DELETE FROM ?:travel_api_alias WHERE map_id IN (?n)', $mapIds);
            db_query('DELETE FROM ?:travel_feature_map WHERE map_id IN (?n)', $mapIds);
        }
    }

    // ── Aliases ──

    #[\Override]
    public function upsertAlias(string $apiSource, string $apiValue, int $mapId, string $matchType = 'exact'): void
    {
        db_query(
            'INSERT INTO ?:travel_api_alias (map_id, api_source, api_value, match_type)
             VALUES (?i, ?s, ?s, ?s)
             ON DUPLICATE KEY UPDATE match_type = ?s',
            $mapId,
            $apiSource,
            $apiValue,
            $matchType,
            $matchType,
        );
    }

    #[\Override]
    public function deleteAlias(int $aliasId): void
    {
        db_query('DELETE FROM ?:travel_api_alias WHERE alias_id = ?i', $aliasId);
    }

    // ── Unmapped values ──

    #[\Override]
    public function trackUnmapped(string $apiSource, string $featureType, string $apiValue, string $apiLabel = ''): void
    {
        db_query(
            "INSERT INTO ?:travel_unmapped_values (api_source, feature_type, api_value, api_label, hotel_count)
             VALUES (?s, ?s, ?s, ?s, 1)
             ON DUPLICATE KEY UPDATE
                hotel_count = hotel_count + 1,
                api_label = IF(?s != '', ?s, api_label)",
            $apiSource,
            $featureType,
            $apiValue,
            $apiLabel,
            $apiLabel,
            $apiLabel,
        );
    }

    #[\Override]
    public function deleteUnmapped(string $apiSource, string $featureType, string $apiValue): void
    {
        db_query(
            'DELETE FROM ?:travel_unmapped_values WHERE api_source = ?s AND feature_type = ?s AND api_value = ?s',
            $apiSource,
            $featureType,
            $apiValue,
        );
    }

    /** @return array<string, mixed>|null */
    #[\Override]
    public function getUnmappedById(int $unmappedId): ?array
    {
        $row = self::asRow(db_get_row('SELECT * FROM ?:travel_unmapped_values WHERE unmapped_id = ?i', $unmappedId));
        return $row === [] ? null : $row;
    }

    // ── Batch operations ──

    #[\Override]
    /**
     * @param array<string, mixed> $mapIds
     */
    public function batchUpdateLastUsed(array $mapIds): void
    {
        if (!empty($mapIds)) {
            db_query('UPDATE ?:travel_feature_map SET last_used_at = NOW() WHERE map_id IN (?n)', $mapIds);
        }
    }

    #[\Override]
    public function getFeatureTypesWithoutFeatureId(): array
    {
        return self::asStringList(db_get_fields(
            'SELECT DISTINCT feature_type FROM ?:travel_feature_map WHERE cscart_feature_id = 0 OR cscart_feature_id IS NULL',
        ));
    }

    #[\Override]
    public function bulkSetFeatureId(string $featureType, int $featureId): void
    {
        db_query(
            'UPDATE ?:travel_feature_map SET cscart_feature_id = ?i WHERE feature_type = ?s AND (cscart_feature_id = 0 OR cscart_feature_id IS NULL)',
            $featureId,
            $featureType,
        );
    }

    /** @return list<array<string, mixed>> */
    #[\Override]
    public function getUnresolvedMappings(): array
    {
        return self::asRowList(db_get_array(
            "SELECT * FROM ?:travel_feature_map
             WHERE (cscart_variant_id IS NULL OR cscart_variant_id = 0)
             AND cscart_feature_id > 0 AND status = 'A'
             AND (variant_source IS NULL OR variant_source != 'manual')",
        ));
    }

    // ── Stats / listing ──

    /**
     * Providers come from a subquery: joining the aliases directly counted
     * each mapping once per alias, inflating every total.
     *
     * @return array<string, array<string, mixed>>
     */
    #[\Override]
    public function getTypeStats(): array
    {
        return self::asRowMap(db_get_hash_array(
            "SELECT m.feature_type,
                    COUNT(*) AS total,
                    SUM(m.status = 'A') AS active,
                    SUM(m.cscart_variant_id IS NULL OR m.cscart_variant_id = 0) AS unmapped,
                    SUM(m.mapping_source = 'auto') AS auto_registered,
                    (SELECT GROUP_CONCAT(DISTINCT a.api_source ORDER BY a.api_source)
                     FROM ?:travel_api_alias a
                     JOIN ?:travel_feature_map m2 ON m2.map_id = a.map_id
                     WHERE m2.feature_type = m.feature_type AND a.api_source != '') AS providers
             FROM ?:travel_feature_map m
             GROUP BY m.feature_type
             ORDER BY FIELD(m.feature_type, 'hotel_facility', 'room_facility', 'beach_access', 'board', 'resort', 'stars', 'property_type', 'travel_group', 'room_type', 'region', 'city')",
            'feature_type',
        ));
    }

    /** @return array<string, array<string, int>> */
    #[\Override]
    public function getAliasCountsBySource(): array
    {
        $rows = self::asRowList(db_get_array(
            'SELECT a.api_source, m.feature_type, COUNT(*) AS aliases
             FROM ?:travel_api_alias a
             JOIN ?:travel_feature_map m ON m.map_id = a.map_id
             GROUP BY a.api_source, m.feature_type',
        ));

        $counts = [];
        foreach ($rows as $row) {
            $counts[TypeCoerce::toString($row['api_source'] ?? '')][TypeCoerce::toString($row['feature_type'] ?? '')]
                = TypeCoerce::toInt($row['aliases'] ?? 0);
        }

        return $counts;
    }

    /** @return array<string, mixed> */
    #[\Override]
    public function getGlobalStats(): array
    {
        $row = self::asRow(db_get_row(
            "SELECT COUNT(*) AS total,
                    SUM(status = 'A') AS active,
                    SUM(cscart_variant_id IS NULL OR cscart_variant_id = 0) AS unmapped
             FROM ?:travel_feature_map",
        ));

        return [
            'total' => TypeCoerce::toInt($row['total'] ?? 0),
            'active' => TypeCoerce::toInt($row['active'] ?? 0),
            'unmapped' => TypeCoerce::toInt($row['unmapped'] ?? 0),
            'aliases' => TypeCoerce::toInt(db_get_field('SELECT COUNT(*) FROM ?:travel_api_alias')),
        ];
    }

    #[\Override]
    public function getUnmappedCount(): int
    {
        return TypeCoerce::toInt(db_get_field('SELECT COUNT(*) FROM ?:travel_unmapped_values'));
    }

    /** @return array{items: list<array<string, mixed>>, total: int} */
    #[\Override]
    public function getPaginatedMappings(string $condition, int $offset, int $limit): array
    {
        $total = TypeCoerce::toInt(db_get_field(
            'SELECT COUNT(*) FROM ?:travel_feature_map m WHERE 1 ?p',
            $condition,
        ));

        $items = self::asRowList(db_get_array(
            "SELECT m.*, COUNT(a.alias_id) as alias_count,
                    GROUP_CONCAT(DISTINCT a.api_source ORDER BY a.api_source) as api_sources
             FROM ?:travel_feature_map m
             LEFT JOIN ?:travel_api_alias a ON a.map_id = m.map_id AND a.api_source != ''
             WHERE 1 ?p
             GROUP BY m.map_id
             ORDER BY m.position, m.canonical_code
             LIMIT ?i, ?i",
            $condition,
            $offset,
            $limit,
        ));

        return ['items' => $items, 'total' => $total];
    }

    /** @return list<array<string, mixed>> */
    #[\Override]
    public function getAliasesForMappings(array $mapIds): array
    {
        if ($mapIds === []) {
            return [];
        }

        return self::asRowList(db_get_array(
            "SELECT map_id, api_source, api_value, match_type FROM ?:travel_api_alias
             WHERE map_id IN (?n) AND api_source != ''
             ORDER BY api_source, api_value",
            $mapIds,
        ));
    }

    /** @return array<int, string> */
    #[\Override]
    public function getFeatureNames(array $featureIds, string $langCode): array
    {
        if ($featureIds === []) {
            return [];
        }

        return self::asIntStringMap(db_get_hash_single_array(
            'SELECT feature_id, description FROM ?:product_features_descriptions WHERE feature_id IN (?n) AND lang_code = ?s',
            ['feature_id', 'description'],
            $featureIds,
            $langCode,
        ));
    }

    /** @return array<int, string> */
    #[\Override]
    public function getVariantNames(array $variantIds, string $langCode): array
    {
        if ($variantIds === []) {
            return [];
        }

        return self::asIntStringMap(db_get_hash_single_array(
            'SELECT variant_id, variant FROM ?:product_feature_variant_descriptions WHERE variant_id IN (?n) AND lang_code = ?s',
            ['variant_id', 'variant'],
            $variantIds,
            $langCode,
        ));
    }

    /** @return array<int, string> */
    private static function asIntStringMap(mixed $rows): array
    {
        // Not TypeCoerce::toStringMap(): it keeps string keys only, and
        // these ids are integer keys.
        $map = [];
        foreach (is_array($rows) ? $rows : [] as $id => $name) {
            $map[(int) $id] = TypeCoerce::toString($name);
        }

        return $map;
    }

    /** @return array<string, mixed> */
    #[\Override]
    public function getTypeStatsSingle(string $featureType): array
    {
        $row = self::asRow(db_get_row(
            "SELECT COUNT(*) AS total,
                    SUM(status = 'A') AS active,
                    SUM(cscart_variant_id IS NULL OR cscart_variant_id = 0) AS unmapped
             FROM ?:travel_feature_map WHERE feature_type = ?s",
            $featureType,
        ));

        return [
            'total' => TypeCoerce::toInt($row['total'] ?? 0),
            'active' => TypeCoerce::toInt($row['active'] ?? 0),
            'unmapped' => TypeCoerce::toInt($row['unmapped'] ?? 0),
        ];
    }

    /** @return array{items: list<array<string, mixed>>, total: int} */
    #[\Override]
    public function getPaginatedUnmapped(string $condition, int $offset, int $limit): array
    {
        $total = TypeCoerce::toInt(db_get_field(
            'SELECT COUNT(*) FROM ?:travel_unmapped_values WHERE 1 ?p',
            $condition,
        ));

        $items = self::asRowList(db_get_array(
            'SELECT * FROM ?:travel_unmapped_values WHERE 1 ?p ORDER BY hotel_count DESC, last_seen_at DESC LIMIT ?i, ?i',
            $condition,
            $offset,
            $limit,
        ));

        return ['items' => $items, 'total' => $total];
    }

    /** @return array<string, mixed>|null */
    #[\Override]
    public function getMappingById(int $mapId): ?array
    {
        $row = self::asRow(db_get_row('SELECT * FROM ?:travel_feature_map WHERE map_id = ?i', $mapId));
        return $row === [] ? null : $row;
    }

    /** @return list<array<string, mixed>> */
    #[\Override]
    public function getAliasesForMapping(int $mapId): array
    {
        return self::asRowList(db_get_array(
            'SELECT * FROM ?:travel_api_alias WHERE map_id = ?i ORDER BY api_source, api_value',
            $mapId,
        ));
    }

    // ── CS-Cart feature/variant operations (used by admin controller) ──

    /** @return list<string> */
    #[\Override]
    public function getActiveLanguageCodes(): array
    {
        return self::asStringList(db_get_fields("SELECT lang_code FROM ?:languages WHERE status = 'A'"));
    }

    #[\Override]
    public function createFeatureVariant(int $featureId, int $position = 0): int
    {
        return TypeCoerce::toInt(db_query(
            'INSERT INTO ?:product_feature_variants ?e',
            ['feature_id' => $featureId, 'position' => $position],
        ));
    }

    #[\Override]
    /**
     * @param array<string, mixed> $nameByLang
     */
    public function insertFeatureVariantDescriptions(int $variantId, array $nameByLang): void
    {
        foreach ($nameByLang as $langCode => $variantName) {
            db_query(
                'INSERT INTO ?:product_feature_variant_descriptions (variant_id, lang_code, variant)
                 VALUES (?i, ?s, ?s) ON DUPLICATE KEY UPDATE variant = ?s',
                $variantId,
                $langCode,
                $variantName,
                $variantName,
            );
        }
    }

    #[\Override]
    public function countHotelsWithJsonFacilities(string $table, string $jsonCol): int
    {
        return TypeCoerce::toInt(db_get_field(
            "SELECT COUNT(*) FROM ?:{$table} WHERE {$jsonCol} IS NOT NULL AND {$jsonCol} != '[]'",
        ));
    }

    /** @return list<array<string, mixed>> */
    #[\Override]
    public function findHotelsBatchForScan(string $table, string $idCol, string $jsonCol, int $offset, int $limit): array
    {
        return self::asRowList(db_get_array(
            "SELECT {$idCol}, {$jsonCol} FROM ?:{$table} " .
            "WHERE {$jsonCol} IS NOT NULL AND {$jsonCol} != '[]' " .
            "ORDER BY {$idCol} LIMIT ?i, ?i",
            $offset,
            $limit,
        ));
    }

    /** @return list<array<string, mixed>> */
    #[\Override]
    public function findAllCsCartFeatures(string $langCode): array
    {
        return self::asRowList(db_get_array(
            'SELECT f.feature_id, f.feature_type, fd.description
             FROM ?:product_features f
             LEFT JOIN ?:product_features_descriptions fd ON f.feature_id = fd.feature_id AND fd.lang_code = ?s
             ORDER BY fd.description',
            $langCode,
        ));
    }

    /** @return list<array{variant_id: int|string, name: string|null}> */
    #[\Override]
    public function findVariantsForFeature(int $featureId, string $langCode): array
    {
        /** @var list<array{variant_id: int|string, name: string|null}> $rows */
        $rows = self::asRowList(db_get_array(
            'SELECT v.variant_id, vd.variant as name
             FROM ?:product_feature_variants v
             LEFT JOIN ?:product_feature_variant_descriptions vd ON v.variant_id = vd.variant_id AND vd.lang_code = ?s
             WHERE v.feature_id = ?i
             ORDER BY v.position, vd.variant',
            $langCode,
            $featureId,
        ));
        return $rows;
    }
}
