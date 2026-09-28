<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Contracts;

/**
 * Repository contract for travel_feature_map + travel_api_alias DB operations.
 *
 * Extracted from FeatureMapper to enable unit testing without DB,
 * and to centralize query logic used by both the service and admin controller.
 */
interface FeatureMapRepositoryInterface
{
    // ── Resolve / lookup ──

    /**
     * Find a mapping by alias match (exact > prefix > contains priority).
     *
     * @return array<string, mixed>|null Mapping row with map_id, feature_type, canonical_code, display names, variant info
     */
    public function findByAlias(string $apiSource, string $featureType, string $apiValue): ?array;

    /**
     * Get display name for a canonical code.
     */
    public function getDisplayName(string $featureType, string $canonicalCode, string $lang = 'en'): string;

    /**
     * Get all active canonical codes for a feature type.
     *
     * @return array<string, array<string, mixed>> Keyed by canonical_code
     */
    public function allCodes(string $featureType): array;

    // ── Mutations ──

    /**
     * Insert a new mapping row (INSERT IGNORE).
     *
     * @return int map_id of inserted row, or 0 if ignored
     */
    public function insertMapping(string $featureType, string $canonicalCode, string $nameEn, string $nameRo, ?int $featureId): int;

    /**
     * Find map_id by feature_type + canonical_code.
     */
    public function findMapId(string $featureType, string $canonicalCode): int;

    /**
     * Update variant_id and variant_source for a mapping.
     */
    public function updateVariantId(int $mapId, int $variantId, string $source = 'auto'): void;

    /**
     * Update cscart_feature_id for a mapping.
     */
    public function updateFeatureId(int $mapId, int $featureId): void;

    /**
     * Update a mapping row with arbitrary data (admin controller).
     *
     * @param array<string, mixed> $data
     */
    public function updateMapping(int $mapId, array $data): void;

    /**
     * Bulk-update status for multiple map_ids.
     *
     * @param int[] $mapIds
     */
    public function bulkUpdateStatus(array $mapIds, string $status): void;

    /**
     * Delete mappings and their aliases by map_ids.
     *
     * @param int[] $mapIds
     */
    public function deleteMappings(array $mapIds): void;

    // ── Aliases ──

    /**
     * Upsert an alias row.
     */
    public function upsertAlias(string $apiSource, string $apiValue, int $mapId, string $matchType = 'exact'): void;

    /**
     * Delete an alias by alias_id.
     */
    public function deleteAlias(int $aliasId): void;

    // ── Unmapped values ──

    /**
     * Track an unmapped API value (INSERT ... ON DUPLICATE KEY UPDATE).
     */
    public function trackUnmapped(string $apiSource, string $featureType, string $apiValue, string $apiLabel = ''): void;

    /**
     * Remove a value from the unmapped table.
     */
    public function deleteUnmapped(string $apiSource, string $featureType, string $apiValue): void;

    /**
     * Get a single unmapped row by ID.
     *
     * @return array<string, mixed>|null
     */
    public function getUnmappedById(int $unmappedId): ?array;

    // ── Batch operations ──

    /**
     * Batch-update last_used_at for a set of map_ids.
     *
     * @param int[] $mapIds
     */
    public function batchUpdateLastUsed(array $mapIds): void;

    /**
     * Get feature types that have no cscart_feature_id set.
     *
     * @return string[]
     */
    public function getFeatureTypesWithoutFeatureId(): array;

    /**
     * Bulk-set cscart_feature_id for all entries of a feature type that lack one.
     */
    public function bulkSetFeatureId(string $featureType, int $featureId): void;

    /**
     * Get active mappings that need variant resolution.
     * (No variant, has feature_id, not manually locked.)
     *
     * @return list<array<string, mixed>>
     */
    public function getUnresolvedMappings(): array;

    // ── Stats / listing (admin controller) ──

    /**
     * Get per-feature-type stats for the dashboard.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getTypeStats(): array;

    /**
     * Alias counts per provider and feature type (the dashboard's coverage
     * check: a provider with no alias for a type never gets that feature).
     *
     * @return array<string, array<string, int>> api_source => feature_type => count
     */
    public function getAliasCountsBySource(): array;

    /**
     * Aliases of several mappings (the list page's per-provider values).
     *
     * @param list<int> $mapIds
     * @return list<array<string, mixed>> {map_id, api_source, api_value, match_type}
     */
    public function getAliasesForMappings(array $mapIds): array;

    /**
     * @param list<int> $featureIds
     * @return array<int, string> feature_id => name
     */
    public function getFeatureNames(array $featureIds, string $langCode): array;

    /**
     * @param list<int> $variantIds
     * @return array<int, string> variant_id => name
     */
    public function getVariantNames(array $variantIds, string $langCode): array;

    /**
     * Raw values waiting for an alias, counted per provider and type.
     *
     * @return list<array<string, mixed>> {api_source, feature_type, values, hotels}
     */
    public function getUnmappedSummary(): array;

    /**
     * Drop logged values that resolve now: through an alias of their
     * provider, or (for $codeTypes) because they are a canonical code.
     *
     * @param list<string> $codeTypes
     */
    public function purgeResolvedUnmapped(array $codeTypes): void;

    /**
     * An active mapping by its canonical code (the types whose values are
     * travel_core's own codes, see FeatureMapper::CODE_RESOLVED_TYPES).
     *
     * @return array<string, mixed>|null
     */
    public function findByCode(string $featureType, string $canonicalCode): ?array;

    /**
     * @param list<int> $unmappedIds
     */
    public function deleteUnmappedByIds(array $unmappedIds): void;

    /**
     * The mappings a raw value can be linked to (the unmapped page's picker).
     *
     * @param list<string> $featureTypes
     * @return list<array<string, mixed>> {map_id, feature_type, canonical_code, display_name_en}
     */
    public function findMappingsOfTypes(array $featureTypes): array;

    /**
     * Get global mapping stats (total, active, unmapped, alias count).
     *
     * @return array<string, mixed>
     */
    public function getGlobalStats(): array;

    /**
     * Get total unmapped values count.
     */
    public function getUnmappedCount(): int;

    /**
     * Get paginated mappings with alias counts.
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function getPaginatedMappings(string $condition, int $offset, int $limit): array;

    /**
     * Get stats for a single feature type.
     *
     * @return array<string, mixed>
     */
    public function getTypeStatsSingle(string $featureType): array;

    /**
     * Get paginated unmapped values.
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function getPaginatedUnmapped(string $condition, int $offset, int $limit): array;

    /**
     * Get a single mapping by map_id.
     *
     * @return array<string, mixed>|null
     */
    public function getMappingById(int $mapId): ?array;

    /**
     * Get aliases for a mapping.
     *
     * @return list<array<string, mixed>>
     */
    public function getAliasesForMapping(int $mapId): array;

    // ── CS-Cart feature/variant operations (used by admin controller) ──

    /**
     * Get all active CS-Cart language codes.
     *
     * @return list<string>
     */
    public function getActiveLanguageCodes(): array;

    /**
     * Create a new CS-Cart product feature variant.
     *
     * @return int Inserted variant_id (0 on failure)
     */
    public function createFeatureVariant(int $featureId, int $position = 0): int;

    /**
     * Insert/upsert variant description rows for every provided language.
     *
     * @param array<string, string> $nameByLang Language code → variant display name
     */
    public function insertFeatureVariantDescriptions(int $variantId, array $nameByLang): void;

    /**
     * Count hotels matching a raw WHERE fragment on a provider-specific table.
     *
     * The JSON column must be non-NULL and non-empty-array ('[]').
     */
    public function countHotelsWithJsonFacilities(string $table, string $jsonCol): int;

    /**
     * Fetch a page of hotels with non-empty JSON facilities from a provider-specific table.
     *
     * @return list<array<string, mixed>>
     */
    public function findHotelsBatchForScan(string $table, string $idCol, string $jsonCol, int $offset, int $limit): array;

    /**
     * Get all CS-Cart product features with localized description.
     *
     * @return list<array<string, mixed>>
     */
    public function findAllCsCartFeatures(string $langCode): array;

    /**
     * Get variants for a CS-Cart feature with localized name.
     *
     * @return list<array{variant_id: int|string, name: string|null}>
     */
    public function findVariantsForFeature(int $featureId, string $langCode): array;
}
