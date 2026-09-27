<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Services;

use Tygh\Addons\SphinxHolidays\Api\SphinxNormalizer;
use Tygh\Addons\SphinxHolidays\Contracts\SphinxFeatureAssignerInterface;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\FacilityLabelResolver;
use Tygh\Addons\TravelCore\Services\FeatureMapper;
use Tygh\Addons\TravelCore\Services\TravelGroupResolver;
use Tygh\Addons\TravelCore\Traits\CsCartFeatureAssignment;

/**
 * Assigns CS-Cart product features to Sphinx hotel products.
 *
 * Uses travel_core/FeatureMapper for alias resolution and direct DB writes
 * for CS-Cart product_features_values assignment.
 *
 * Template method patterns eliminate duplication across feature types:
 * - assignMappedSelectBox(): stars, property_type, resort
 * - assignLocationFeature(): region, city
 * - collectAndSyncCheckboxFeature(): boards, travel_group
 */
class SphinxFeatureAssigner implements SphinxFeatureAssignerInterface
{
    use CsCartFeatureAssignment;

    private const string API_SOURCE = 'sphinx';

    /** @var array<string, int> featureId:variantName → variant_id cache */
    private array $locationVariantCache = [];

    /** @var list<array{id: string, name: string, mapping: array<string, mixed>|null}>|null Cached resolved facility data for current hotel */
    private ?array $resolvedFacilitiesCache = null;

    /** @var string|null Hash of the facilities_json that was cached */
    private ?string $resolvedFacilitiesCacheKey = null;

    public function __construct(
        private readonly SphinxNormalizer $normalizer,
    ) {
    }

    /**
     * Assign all features from a sphinx_hotels row to a CS-Cart product.
     * @param array<string, mixed> $hotel
     */
    #[\Override]
    public function assignAll(int $productId, array $hotel): void
    {
        // Reset per-hotel facility cache
        $this->resolvedFacilitiesCache = null;
        $this->resolvedFacilitiesCacheKey = null;

        $this->assignStarRating($productId, $hotel);
        $this->assignPropertyType($productId, $hotel);
        $this->assignResort($productId, $hotel);
        $this->assignFacilities($productId, $hotel);
        $this->assignBoards($productId, $hotel);
        $this->assignRegion($productId, $hotel);
        $this->assignCity($productId, $hotel);
        $this->assignTravelGroup($productId, $hotel);
    }

    // ── Template methods ──

    /**
     * Assign a single selectbox feature via FeatureMapper resolution.
     *
     * Shared pattern for stars, property_type, resort.
     *
     * @param int $productId CS-Cart product ID
     * @param string $featureType Feature type key
     * @param ?string $rawValue Raw value from hotel data
     * @param bool $autoCreate Auto-create variant if mapping exists but no variant
     */
    private function assignMappedSelectBox(
        int $productId,
        string $featureType,
        ?string $rawValue,
        bool $autoCreate = true,
    ): void {
        if ($rawValue === null || $rawValue === '') {
            return;
        }

        $featureId = $this->getFeatureId($featureType);
        if ($featureId <= 0) {
            return;
        }

        $mapping = FeatureMapper::resolve(self::API_SOURCE, $featureType, $rawValue);
        $variantId = $mapping !== null ? TypeCoerce::toInt($mapping['cscart_variant_id'] ?? 0) : 0;

        if ($variantId <= 0 && $mapping !== null && $autoCreate) {
            $variantId = $this->autoCreateVariant($featureId, $mapping);
        }

        if ($variantId > 0) {
            $this->assignSelectBoxValue($productId, $featureId, $variantId);
        }
    }

    /**
     * Assign a location-based feature (dynamic variant by name, no mapping).
     *
     * Shared pattern for region, city.
     */
    private function assignLocationFeature(int $productId, string $featureType, ?string $locationName): void
    {
        if ($locationName === null || trim($locationName) === '') {
            return;
        }

        $featureId = $this->getFeatureId($featureType);
        if ($featureId <= 0) {
            return;
        }

        $variantId = $this->getOrCreateLocationVariant($featureId, trim($locationName));
        if ($variantId > 0) {
            $this->assignSelectBoxValue($productId, $featureId, $variantId);
        }
    }

    /**
     * Resolve multiple codes via FeatureMapper and sync as checkbox values.
     *
     * Shared pattern for boards, travel_group.
     *
     * @param int $productId CS-Cart product ID
     * @param string $featureType Feature type key
     * @param string[] $codes Canonical codes to resolve
     * @param bool $autoCreate Auto-create variants if mapping exists but no variant
     */
    private function collectAndSyncCheckboxFeature(
        int $productId,
        string $featureType,
        array $codes,
        bool $autoCreate = true,
    ): void {
        $featureId = $this->getFeatureId($featureType);
        if ($featureId <= 0) {
            return;
        }

        $wantedVariants = [];
        foreach ($codes as $code) {
            $mapping = FeatureMapper::resolve(self::API_SOURCE, $featureType, $code);
            if ($mapping === null) {
                continue;
            }

            $variantId = TypeCoerce::toInt($mapping['cscart_variant_id'] ?? 0);
            if ($variantId <= 0 && $autoCreate) {
                $variantId = $this->autoCreateVariant($featureId, $mapping);
            }
            if ($variantId > 0) {
                $wantedVariants[$variantId] = true;
            }
        }

        $this->syncCheckboxValues($productId, $featureId, array_keys($wantedVariants));
    }

    /**
     * Resolve all facility IDs from hotel JSON to mappings (cached per hotel).
     *
     * Used by assignFacilities(), getHotelFacilityCodes(), and detectTravelGroups()
     * to avoid parsing and resolving the same facilities_json multiple times.
     *
     * @return array<int, array{id: string, name: string, mapping: array<string, mixed>|null}>
     * @param array<string, mixed> $hotel
     */
    private function resolveHotelFacilities(array $hotel): array
    {
        $facilitiesJson = $hotel['facilities_json'] ?? null;
        if (empty($facilitiesJson)) {
            return [];
        }

        // Cache check — avoid re-resolving for same hotel
        $cacheKey = md5(is_string($facilitiesJson) ? $facilitiesJson : (string) json_encode($facilitiesJson));
        if ($this->resolvedFacilitiesCacheKey === $cacheKey && $this->resolvedFacilitiesCache !== null) {
            return $this->resolvedFacilitiesCache;
        }

        $facilities = is_string($facilitiesJson) ? json_decode($facilitiesJson, true) : $facilitiesJson;
        if (!is_array($facilities)) {
            return [];
        }

        $resolved = [];
        foreach (TypeCoerce::toRowList($facilities) as $facility) {
            $facilityId = TypeCoerce::toString($facility['id'] ?? '');
            if ($facilityId === '') {
                continue;
            }

            $mapping = FeatureMapper::resolveFacility(self::API_SOURCE, $facilityId);
            $resolved[] = [
                'id' => $facilityId,
                'name' => TypeCoerce::toString($facility['name'] ?? ''),
                'mapping' => $mapping,
            ];
        }

        $this->resolvedFacilitiesCache = $resolved;
        $this->resolvedFacilitiesCacheKey = $cacheKey;

        return $resolved;
    }

    /**
     * Localized facility labels for one hotel, for the booking-form summary
     * sidebar.
     *
     * Prefers the canonical mapping's display name (so "pool" reads
     * "Piscină" in Romanian) and falls back to the provider's own label when
     * a facility has no mapping yet — an unmapped facility should still be
     * visible to the guest, not silently dropped.
     *
     * Capped: the sidebar shows an at-a-glance strip, not the full inventory.
     *
     * @param array<string, mixed> $hotel A ?:sphinx_hotels row.
     * @return list<string>
     */
    public function getHotelFacilityLabels(array $hotel, string $lang = 'en', int $limit = 6): array
    {
        $labels = [];
        $column = FacilityLabelResolver::column($lang);

        foreach ($this->resolveHotelFacilities($hotel) as $facility) {
            // resolveHotelFacilities() already carries the mapping row, so use
            // it rather than re-resolving through FacilityLabelResolver — the
            // language rules are shared, the lookup is not.
            $mapping = TypeCoerce::toStringMap($facility['mapping'] ?? []);
            $id = trim($facility['id']);
            $label = trim(TypeCoerce::toString($mapping[$column] ?? ''));
            if ($label === '' || $label === $id) {
                $label = trim(TypeCoerce::toString($mapping['display_name_en'] ?? ''));
            }
            // Same rules as travel_core FacilityLabelResolver: a label that
            // is only the facility's code yields to the provider's name, and
            // a facility nobody named still keeps its chip.
            if ($label === '' || $label === $id) {
                $label = trim(TypeCoerce::toString($facility['name']));
            }
            if (($label === '' || $label === $id) && $id !== '') {
                $label = strtr(
                    function_exists('__') ? TypeCoerce::toString(__('travel_core.facility_unnamed')) : 'Facility #[code]',
                    ['[code]' => $id],
                );
            }
            if ($label !== '' && !in_array($label, $labels, true)) {
                $labels[] = $label;
            }
            if (count($labels) >= $limit) {
                break;
            }
        }

        return $labels;
    }

    // ── Feature-specific methods (delegate to templates) ──

    /**
     * @param array<string, mixed> $hotel
     */
    private function assignStarRating(int $productId, array $hotel): void
    {
        $code = $this->normalizer->normalizeStarRating($hotel['classification'] ?? null);
        $this->assignMappedSelectBox($productId, 'stars', $code);
    }

    /**
     * @param array<string, mixed> $hotel
     */
    private function assignPropertyType(int $productId, array $hotel): void
    {
        $code = $this->normalizer->normalizePropertyType($hotel['property_type'] ?? null);
        $this->assignMappedSelectBox($productId, 'property_type', $code);
    }

    /**
     * @param array<string, mixed> $hotel
     */
    private function assignResort(int $productId, array $hotel): void
    {
        $code = $this->normalizer->normalizeResort($hotel['destination_name'] ?? null);
        $this->assignMappedSelectBox($productId, 'resort', $code, false);
    }

    /**
     * Primary path: resolve region_id through FeatureMapper (seeded from the
     * Destination Whitelist) so curated canonical variants win when mapped.
     * Every dead end of that path (no region_id, unresolved mapping, no
     * usable feature/variant) falls back to a plain name-based variant from
     * region_name — the sphinx_hotels row carries the name even when the id
     * was never whitelisted, and Regiune must not stay empty because of it.
     *
     * @param array<string, mixed> $hotel
     */
    private function assignRegion(int $productId, array $hotel): void
    {
        $regionName = TypeCoerce::toString($hotel['region_name'] ?? '');
        $regionId = TypeCoerce::toString($hotel['region_id'] ?? '');

        if ($regionId !== '' && $regionId !== '0') {
            $mapping = FeatureMapper::resolve(self::API_SOURCE, 'region', $regionId);
            if ($mapping === null) {
                // Keep the gap visible on the Feature Mappings screen.
                FeatureMapper::handleUnmapped(self::API_SOURCE, 'region', $regionId, $regionName);
            } else {
                $featureId = TypeCoerce::toInt($mapping['cscart_feature_id'] ?? 0);
                if ($featureId <= 0) {
                    $featureId = $this->getFeatureId('region');
                }

                $variantId = TypeCoerce::toInt($mapping['cscart_variant_id'] ?? 0);
                if ($featureId > 0 && $variantId <= 0) {
                    $variantId = $this->autoCreateVariant($featureId, $mapping);
                }

                if ($featureId > 0 && $variantId > 0) {
                    $this->assignSelectBoxValue($productId, $featureId, $variantId);
                    return;
                }
            }
        }

        $this->assignLocationFeature($productId, 'region', $regionName);
    }

    /**
     * @param array<string, mixed> $hotel
     */
    private function assignCity(int $productId, array $hotel): void
    {
        $destinationName = $hotel['destination_name'] ?? null;
        $this->assignLocationFeature($productId, 'city', $destinationName === null ? null : TypeCoerce::toString($destinationName));
    }

    /**
     * @param array<string, mixed> $hotel
     */
    private function assignBoards(int $productId, array $hotel): void
    {
        $boardsJson = $hotel['boards_json'] ?? null;
        if (empty($boardsJson)) {
            return;
        }

        $boards = is_string($boardsJson) ? json_decode($boardsJson, true) : $boardsJson;
        if (!is_array($boards) || empty($boards)) {
            return;
        }

        $this->collectAndSyncCheckboxFeature($productId, 'board', TypeCoerce::toStringList($boards));
    }

    /**
     * Assign facility features from facilities_json (diff-based sync).
     *
     * Facilities are special: they can map to multiple CS-Cart features
     * (e.g., "Hotel Facilities", "Room Amenities"), so they're grouped
     * by cscart_feature_id before syncing. This is too complex for the
     * generic collectAndSyncCheckboxFeature() template.
     * @param array<string, mixed> $hotel
     */
    private function assignFacilities(int $productId, array $hotel): void
    {
        $resolved = $this->resolveHotelFacilities($hotel);
        if (empty($resolved)) {
            return;
        }

        $wantedByFeature = [];
        $unmapped = [];

        foreach ($resolved as $entry) {
            $mapping = $entry['mapping'];

            if ($mapping === null) {
                FeatureMapper::handleUnmapped(self::API_SOURCE, 'hotel_facility', $entry['id'], $entry['name']);
                $unmapped[] = $entry['id'] . ':' . $entry['name'];
                continue;
            }

            $featureId = TypeCoerce::toInt($mapping['cscart_feature_id'] ?? 0);
            $variantId = TypeCoerce::toInt($mapping['cscart_variant_id'] ?? 0);

            if ($featureId <= 0) {
                $unmapped[] = $entry['id'] . ':' . $entry['name'];
                continue;
            }

            if ($variantId <= 0) {
                // Only auto-create if at least one sibling has a variant
                $hasMappedSibling = TypeCoerce::toInt(db_get_field(
                    "SELECT COUNT(*) FROM ?:travel_feature_map
                     WHERE feature_type IN ('hotel_facility', 'room_facility', 'beach_access')
                       AND cscart_feature_id = ?i
                       AND cscart_variant_id IS NOT NULL AND cscart_variant_id > 0
                     LIMIT 1",
                    $featureId,
                ));
                if ($hasMappedSibling > 0) {
                    $variantId = $this->autoCreateVariant($featureId, $mapping);
                }
                if ($variantId <= 0) {
                    $unmapped[] = $entry['id'] . ':' . $entry['name'];
                    continue;
                }
            }

            $wantedByFeature[$featureId][] = $variantId;
        }

        // Diff-based sync per feature_id
        foreach ($wantedByFeature as $featureId => $wantedVariants) {
            $this->syncCheckboxValues($productId, $featureId, $wantedVariants);
        }

        if (!empty($unmapped)) {
            fn_log_event('general', 'runtime', [
                'message' => "Sphinx: product {$productId} has " . count($unmapped) . ' unmapped facilities: ' . implode(', ', array_slice($unmapped, 0, 10)),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $hotel
     */
    private function assignTravelGroup(int $productId, array $hotel): void
    {
        $featureId = $this->getFeatureId('travel_group');
        if ($featureId <= 0) {
            return;
        }

        $facilityCodes = $this->getHotelFacilityCodes($hotel);
        $groupCodes = TravelGroupResolver::derive(
            $facilityCodes,
            ($hotel['is_adults_only'] ?? 'N') === 'Y',
        );

        if (empty($groupCodes)) {
            $this->syncCheckboxValues($productId, $featureId, []);
            return;
        }

        $this->collectAndSyncCheckboxFeature($productId, 'travel_group', $groupCodes);
    }

    /**
     * Get all canonical facility codes present in a hotel's facilities_json.
     *
     * @return string[] Canonical codes (e.g. ['pool', 'pets_allowed', 'family_rooms'])
     * @param array<string, mixed> $hotel
     */
    private function getHotelFacilityCodes(array $hotel): array
    {
        $resolved = $this->resolveHotelFacilities($hotel);
        $codes = [];

        foreach ($resolved as $entry) {
            $mapping = $entry['mapping'];
            if ($mapping !== null && !empty($mapping['canonical_code'])) {
                $codes[] = TypeCoerce::toString($mapping['canonical_code']);
            }
        }

        return $codes;
    }

    // ── Helpers ──

    /**
     * Get or create a feature variant by name for location-type features.
     * Caches lookups to avoid repeated DB queries within a batch.
     */
    private function getOrCreateLocationVariant(int $featureId, string $name): int
    {
        $cacheKey = $featureId . ':' . $name;
        if (isset($this->locationVariantCache[$cacheKey])) {
            return $this->locationVariantCache[$cacheKey];
        }

        $variantId = TypeCoerce::toInt(db_get_field(
            'SELECT pf.variant_id FROM ?:product_feature_variant_descriptions pf
             WHERE pf.variant = ?s AND pf.lang_code = ?s
             AND pf.variant_id IN (SELECT variant_id FROM ?:product_feature_variants WHERE feature_id = ?i)
             LIMIT 1',
            $name,
            CART_LANGUAGE,
            $featureId,
        ));

        if ($variantId <= 0) {
            $variantId = $this->createVariant($featureId, $name, $name);
        }

        if ($variantId > 0) {
            $this->locationVariantCache[$cacheKey] = $variantId;
        }

        return $variantId;
    }

    private function getFeatureId(string $featureType): int
    {
        return FeatureMapper::getFeatureId($featureType);
    }

    /**
     * Auto-create a CS-Cart feature variant from mapping display names.
     * @param array<string, mixed> $mapping
     */
    private function autoCreateVariant(int $featureId, array $mapping): int
    {
        $nameEn = TypeCoerce::toString($mapping['display_name_en'] ?? $mapping['canonical_code'] ?? '');
        $nameRo = TypeCoerce::toString($mapping['display_name_ro'] ?? $nameEn);

        $variantId = $this->createVariant($featureId, $nameEn, $nameRo);

        if ($variantId > 0 && !empty($mapping['map_id'])) {
            db_query(
                'UPDATE ?:travel_feature_map SET cscart_variant_id = ?i WHERE map_id = ?i',
                $variantId,
                TypeCoerce::toInt($mapping['map_id']),
            );
            FeatureMapper::clearCache();
        }

        return $variantId;
    }

    // assignSelectBoxValue(), syncCheckboxValues(), getActiveLanguages(),
    // and createVariant() are provided by CsCartFeatureAssignment trait.
}
