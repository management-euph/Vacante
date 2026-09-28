<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Services;

use Tygh\Addons\TravelCore\Contracts\FeatureMapperInterface;
use Tygh\Addons\TravelCore\Contracts\FeatureMapRepositoryInterface;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Repository\FeatureMapRepository;

/**
 * Feature mapper service.
 *
 * Resolves API-specific values (e.g., "Mic dejun", "AI", "Twin Room with Sea View")
 * to canonical codes using the travel_feature_map + travel_api_alias tables.
 *
 * Static facade pattern: all public methods remain static for backward compatibility.
 * DB operations are delegated to FeatureMapRepository for testability.
 *
 * Supports:
 * - Multi-pass fuzzy matching (exact > prefix > contains)
 * - Auto-registration of unmapped values for dynamic feature types
 * - 3-pass variant name matching (exact → case-insensitive → normalized)
 * - Auto-creation of CS-Cart variants
 * - Manual lock (variant_source='manual') to prevent auto-overwrite
 */
class FeatureMapper implements FeatureMapperInterface
{
    /** @var array<string, array<string, mixed>|null> Per-request in-memory cache for resolve() results */
    private static array $cache = [];

    /** @var array<string, true> Per-request deduplication for trackUnmapped() */
    private static array $trackedUnmapped = [];

    /** @var VariantResolver|null Lazy-initialized variant resolver */
    private static ?VariantResolver $variantResolver = null;

    /** @var FeatureMapRepositoryInterface|null Injectable repository (defaults to FeatureMapRepository) */
    private static ?FeatureMapRepositoryInterface $repository = null;

    /**
     * Strict feature types: unknown codes are logged + skipped, never auto-registered.
     * These have well-defined value sets that shouldn't grow automatically.
     */
    public const array STRICT_FEATURE_TYPES = [
        'board', 'room_type', 'stars', 'property_type', 'travel_group',
    ];

    /**
     * Dynamic feature types: unknown codes are auto-registered as new mappings.
     * These grow organically as new hotels/facilities appear in API data.
     */
    public const array DYNAMIC_FEATURE_TYPES = [
        'hotel_facility', 'room_facility', 'resort', 'region', 'city', 'beach_access',
    ];

    /** Feature type → travel_core addon setting key */
    private const array FEATURE_SETTING_KEYS = [
        'stars' => 'feature_id_property_rating',
        'property_type' => 'feature_id_property_type',
        'resort' => 'feature_id_location',
        'board' => 'feature_id_meals',
        'region' => 'feature_id_region',
        'city' => 'feature_id_city',
        'travel_group' => 'feature_id_travel_group',
        'hotel_facility' => 'feature_id_hotel_facility',
        'room_facility' => 'feature_id_room_facility',
        'beach_access' => 'feature_id_beach_access',
    ];

    // ── Repository access ──

    /**
     * Get the repository instance (lazy-initialized).
     */
    public static function getRepository(): FeatureMapRepositoryInterface
    {
        if (self::$repository === null) {
            self::$repository = new FeatureMapRepository();
        }
        return self::$repository;
    }

    /**
     * Inject a custom repository (for testing).
     */
    public static function setRepository(?FeatureMapRepositoryInterface $repository): void
    {
        self::$repository = $repository;
    }

    // ── Core resolve ──

    /**
     * Resolve an API value to a canonical mapping.
     *
     * Uses a single combined query with priority ordering (exact > prefix > contains)
     * and caches results in memory for the duration of the request.
     *
     * @param string $apiSource Provider name ('novoton', 'sphinx')
     * @param string $featureType Feature type ('board', 'room_type', 'stars', 'hotel_facility', etc.)
     * @param string $apiValue Raw value from the API
     * @return array<string, mixed>|null {map_id, feature_type, canonical_code, display_name_en, display_name_ro, cscart_feature_id, cscart_variant_id, variant_source}
     */
    #[\Override]
    public static function resolve(string $apiSource, string $featureType, string $apiValue): ?array
    {
        $cacheKey = $apiSource . "\0" . $featureType . "\0" . $apiValue;

        if (array_key_exists($cacheKey, self::$cache)) {
            return self::$cache[$cacheKey];
        }

        $result = self::getRepository()->findByAlias($apiSource, $featureType, $apiValue);
        if (($result === null || $result === []) && in_array($featureType, self::CODE_RESOLVED_TYPES, true)) {
            $result = self::getRepository()->findByCode($featureType, $apiValue);
        }

        if ($result !== null && $result !== []) {
            // Batch last_used_at updates — collect map_ids and flush at clearCache()
            self::$usedMapIds[TypeCoerce::toInt($result['map_id'])] = true;
        }

        self::$cache[$cacheKey] = $result;

        return self::$cache[$cacheKey];
    }

    /**
     * Types whose values are travel_core's own canonical codes, not provider
     * values: travel groups are DERIVED from facilities (TravelGroupResolver
     * returns adults_only / family_friendly / pets_friendly), so no provider
     * seeds aliases for them. Without an alias they resolve by code; before
     * this every provider's travel group was logged as unmapped instead.
     */
    public const array CODE_RESOLVED_TYPES = ['travel_group'];

    /** All facility sub-types */
    public const array FACILITY_TYPES = ['hotel_facility', 'room_facility', 'beach_access'];

    /**
     * Resolve a facility API value across all facility sub-types.
     *
     * Facilities are split into hotel_facility, room_facility, and beach_access,
     * but callers (API syncs, scans) don't know which sub-type a given facility
     * belongs to. This tries each sub-type and returns the first match.
     *
     * @return array<string, mixed>|null
     */
    public static function resolveFacility(string $apiSource, string $apiValue): ?array
    {
        foreach (self::FACILITY_TYPES as $type) {
            $result = self::resolve($apiSource, $type, $apiValue);
            if ($result !== null && $result !== []) {
                return $result;
            }
        }
        return null;
    }

    /** @var array<int, true> Map IDs used in this request, flushed by clearCache() */
    private static array $usedMapIds = [];

    // ── Resolve with variant auto-creation ──

    /**
     * Resolve an API value and ensure a CS-Cart variant exists.
     *
     * If the mapping has no variant yet (or the variant was deleted from CS-Cart),
     * uses 3-pass name matching (exact → case-insensitive → normalized) to find
     * an existing variant, or auto-creates one.
     *
     * Respects variant_source='manual' — never auto-overwrites admin-locked mappings.
     *
     * @return array<string, mixed>|null The mapping with guaranteed cscart_variant_id (if resolvable)
     */
    #[\Override]
    public static function resolveWithVariant(string $apiSource, string $featureType, string $apiValue): ?array
    {
        $mapping = self::resolve($apiSource, $featureType, $apiValue);
        if ($mapping === null || $mapping === []) {
            return null;
        }

        $variantId = self::getVariantResolver()->ensureVariantExists($mapping);
        if ($variantId > 0) {
            $mapping['cscart_variant_id'] = $variantId;
        }

        return $mapping;
    }

    /**
     * resolveWithVariant across all facility sub-types.
     *
     * @return array<string, mixed>|null
     */
    public static function resolveWithVariantFacility(string $apiSource, string $apiValue): ?array
    {
        foreach (self::FACILITY_TYPES as $type) {
            $result = self::resolveWithVariant($apiSource, $type, $apiValue);
            if ($result !== null && $result !== []) {
                return $result;
            }
        }
        return null;
    }

    // ── Unmapped value handling ──

    /**
     * Handle an unmapped API value based on feature type classification.
     *
     * - Strict types: log a warning + track in travel_unmapped_values
     * - Dynamic types: auto-register as a new mapping row + alias
     *
     * @return int|null map_id of the auto-registered row (dynamic), or null (strict/failed)
     */
    #[\Override]
    public static function handleUnmapped(string $apiSource, string $featureType, string $apiValue, string $apiLabel = ''): ?int
    {
        // Always track in unmapped_values for visibility
        self::trackUnmapped($apiSource, $featureType, $apiValue, $apiLabel);

        if (in_array($featureType, self::STRICT_FEATURE_TYPES, true)) {
            // Strict: log + skip
            if (function_exists('fn_log_event')) {
                fn_log_event('general', 'runtime', [
                    'message' => 'FeatureMapper: Unmapped strict value skipped',
                    'api_source' => $apiSource,
                    'feature_type' => $featureType,
                    'api_value' => $apiValue,
                ]);
            }
            return null;
        }

        if (in_array($featureType, self::DYNAMIC_FEATURE_TYPES, true)) {
            return self::registerUnmapped($apiSource, $featureType, $apiValue, $apiLabel);
        }

        return null;
    }

    /**
     * Auto-register an unmapped API value as a real mapping + alias.
     *
     * Creates a travel_feature_map row (mapping_source='auto', status='A')
     * and a travel_api_alias row linking the API value.
     *
     * @return int|null map_id of the new row, or null on failure
     */
    public static function registerUnmapped(string $apiSource, string $featureType, string $apiValue, string $apiLabel = ''): ?int
    {
        $effectiveLabel = $apiLabel !== ''
            ? $apiLabel
            : mb_convert_case($apiValue, MB_CASE_TITLE, 'UTF-8');

        // Use api_value as canonical code (sanitized: lowercase, non-alnum → underscore, collapse)
        $canonicalCode = preg_replace('/[^a-z0-9_]/', '_', strtolower(trim($apiValue)));
        if ($canonicalCode === null || $canonicalCode === '') {
            return null;
        }
        // Collapse multiple underscores and trim trailing ones
        $canonicalCode = trim(preg_replace('/_+/', '_', $canonicalCode) ?? '', '_');
        if ($canonicalCode === '') {
            return null;
        }

        // Determine cscart_feature_id from addon settings
        $featureId = self::getFeatureId($featureType);
        $repo = self::getRepository();

        // Insert mapping row
        $mapId = $repo->insertMapping($featureType, $canonicalCode, $effectiveLabel, $effectiveLabel, $featureId ?: null);

        if ($mapId <= 0) {
            // Row may already exist (INSERT IGNORE), fetch existing map_id
            $mapId = $repo->findMapId($featureType, $canonicalCode);
        }

        if ($mapId <= 0) {
            return null;
        }

        // Create alias linking this api_value to the canonical row
        self::addAlias($apiSource, $apiValue, $mapId, 'exact');

        // Remove from unmapped_values since it's now mapped
        $repo->deleteUnmapped($apiSource, $featureType, $apiValue);

        self::$cache = [];

        return $mapId;
    }

    /**
     * Track an unmapped API value for admin visibility.
     *
     * Uses INSERT ... ON DUPLICATE KEY UPDATE to increment hotel_count
     * and refresh last_seen_at. Lightweight — one query per unique miss per request.
     */
    #[\Override]
    public static function trackUnmapped(string $apiSource, string $featureType, string $apiValue, string $apiLabel = ''): void
    {
        $dedupeKey = $apiSource . "\0" . $featureType . "\0" . $apiValue;
        if (isset(self::$trackedUnmapped[$dedupeKey])) {
            return;
        }
        self::$trackedUnmapped[$dedupeKey] = true;

        self::getRepository()->trackUnmapped($apiSource, $featureType, $apiValue, $apiLabel);
    }

    // ── Variant ID management ──

    /**
     * Update the variant_id for a mapping row.
     */
    #[\Override]
    public static function updateVariantId(int $mapId, int $variantId, string $source = 'auto'): void
    {
        self::getRepository()->updateVariantId($mapId, $variantId, $source);
        self::$cache = [];
    }

    // ── Cache management ──

    /**
     * Clear the in-memory resolve cache.
     * Call after import/sync operations to free memory.
     */
    #[\Override]
    public static function clearCache(): void
    {
        // Batch-flush last_used_at updates (one query instead of N)
        if (!empty(self::$usedMapIds)) {
            self::getRepository()->batchUpdateLastUsed(array_keys(self::$usedMapIds));
            self::$usedMapIds = [];
        }

        self::$cache = [];
        self::$trackedUnmapped = [];
        self::$featureIdCache = [];
    }

    // ── Convenience methods ──

    /**
     * Get CS-Cart variant_id directly (for product feature assignment).
     */
    #[\Override]
    public static function toVariantId(string $apiSource, string $featureType, string $apiValue): ?int
    {
        $mapping = self::resolve($apiSource, $featureType, $apiValue);
        if ($mapping === null) {
            return null;
        }
        $vid = TypeCoerce::toInt($mapping['cscart_variant_id'] ?? 0);
        return $vid > 0 ? $vid : null;
    }

    /**
     * Bulk resolve for import performance (keyed result).
     *
     * @return array<string, array<string, mixed>> Keyed by api_value
     */
    #[\Override]
    public static function resolveMany(string $apiSource, string $featureType, array $apiValues): array
    {
        if (empty($apiValues)) {
            return [];
        }

        $result = [];
        foreach ($apiValues as $apiValue) {
            $mapping = self::resolve($apiSource, $featureType, $apiValue);
            if ($mapping !== null) {
                $result[$apiValue] = $mapping;
            }
        }

        return $result;
    }

    /**
     * Get display name for a canonical code (language-aware).
     */
    #[\Override]
    public static function getDisplayName(string $featureType, string $canonicalCode, string $lang = 'en'): string
    {
        return self::getRepository()->getDisplayName($featureType, $canonicalCode, $lang);
    }

    /**
     * Register an alias (called by each API addon during install/sync).
     */
    #[\Override]
    public static function addAlias(string $apiSource, string $apiValue, int $mapId, string $matchType = 'exact'): void
    {
        self::getRepository()->upsertAlias($apiSource, $apiValue, $mapId, $matchType);
        self::$cache = [];
    }

    /**
     * Known display names of the OPEN-ENDED location features. City/region
     * values are assigned by NAME (no canonical mapping), so when the
     * feature_id_* setting was never configured we can still bind to the
     * store's existing feature by its description — and persist the id so
     * the lookup runs once, not per call.
     */
    private const array LOCATION_FEATURE_NAMES = [
        'city' => ['City', 'Oraș', 'Oras'],
        'region' => ['Region', 'Regiune'],
    ];

    /** @var array<string, int> In-request feature-id cache */
    private static array $featureIdCache = [];

    /**
     * Get CS-Cart feature_id for a travel feature type from addon settings.
     *
     * For the location types (city/region) an unset setting self-heals from
     * the feature's display name — without it, every location assignment
     * silently no-ops on stores where those settings were never filled.
     *
     * @return int feature_id or 0 if not configured
     */
    #[\Override]
    public static function getFeatureId(string $featureType): int
    {
        if (isset(self::$featureIdCache[$featureType])) {
            return self::$featureIdCache[$featureType];
        }

        $settingKey = self::FEATURE_SETTING_KEYS[$featureType]
                      ?? ('feature_id_' . $featureType);
        $value = TravelCoreConfig::getSetting($settingKey);
        $featureId = is_numeric($value) ? (int) $value : 0;

        if ($featureId <= 0 && isset(self::LOCATION_FEATURE_NAMES[$featureType])) {
            $featureId = TypeCoerce::toInt(db_get_field(
                'SELECT fd.feature_id FROM ?:product_features_descriptions fd
                 JOIN ?:product_features f ON f.feature_id = fd.feature_id
                 WHERE fd.description IN (?a)
                 ORDER BY fd.feature_id ASC LIMIT 1',
                self::LOCATION_FEATURE_NAMES[$featureType],
            ));
            if ($featureId > 0) {
                // Persist for future requests; $featureIdCache covers the
                // rest of this one (Registry stays untouched — src/ boundary).
                $settings = \Tygh\Settings::instance();
                if (is_object($settings) && method_exists($settings, 'updateValue')) {
                    $settings->updateValue($settingKey, (string) $featureId, 'travel_core', true);
                }
            }
        }

        return self::$featureIdCache[$featureType] = $featureId;
    }

    /**
     * Get all canonical codes for a feature type.
     *
     * @return array<string, array<string, mixed>>
     */
    #[\Override]
    public static function allCodes(string $featureType): array
    {
        return self::getRepository()->allCodes($featureType);
    }

    /**
     * Get the VariantResolver instance (lazy-initialized).
     */
    public static function getVariantResolver(): VariantResolver
    {
        if (self::$variantResolver === null) {
            self::$variantResolver = new VariantResolver(self::getRepository());
        }
        return self::$variantResolver;
    }

    /**
     * Inject a custom VariantResolver (for testing).
     */
    public static function setVariantResolver(?VariantResolver $resolver): void
    {
        self::$variantResolver = $resolver;
    }
}
