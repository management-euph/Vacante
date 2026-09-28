<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Services;

use Tygh\Addons\TravelCore\Contracts\FeatureMapRepositoryInterface;
use Tygh\Addons\TravelCore\Contracts\VariantResolverInterface;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Traits\CsCartFeatureAssignment;

/**
 * Resolves and auto-creates CS-Cart product feature variants for feature mappings.
 *
 * Extracted from FeatureMapper to follow Single Responsibility Principle.
 * Handles:
 * - Checking if a stored variant still exists in CS-Cart
 * - 3-pass name matching (exact → case-insensitive → normalized)
 * - Auto-creating variants when no match found
 * - Respecting manual locks (variant_source='manual')
 *
 * @package TravelCore
 * @since   1.3.0
 */
class VariantResolver implements VariantResolverInterface
{
    use CsCartFeatureAssignment;

    private FeatureMapRepositoryInterface $repo;

    public function __construct(FeatureMapRepositoryInterface $repo)
    {
        $this->repo = $repo;
    }

    /**
     * Ensure a CS-Cart variant exists for a mapping row.
     *
     * 1. If stored variant_id exists in CS-Cart, use it.
     * 2. If variant_source='manual', never auto-resolve (admin locked).
     * 3. Try 3-pass name matching against existing CS-Cart variants.
     * 4. Auto-create the variant if no match found.
     *
     * @param array<string, mixed> $mapping
     * @return int variant_id or 0
     */
    #[\Override]
    public function ensureVariantExists(array $mapping): int
    {
        $variantId = TypeCoerce::toInt($mapping['cscart_variant_id'] ?? 0);
        $featureId = TypeCoerce::toInt($mapping['cscart_feature_id'] ?? 0);
        $mapId = TypeCoerce::toInt($mapping['map_id'] ?? 0);
        $variantSource = $mapping['variant_source'] ?? 'auto';

        if ($mapId <= 0) {
            return 0;
        }

        if ($featureId <= 0) {
            $featureType = $mapping['feature_type'] ?? '';
            if ($featureType !== '') {
                $featureId = FeatureMapper::getFeatureId(TypeCoerce::toString($featureType));
                if ($featureId > 0) {
                    $this->repo->updateFeatureId($mapId, $featureId);
                }
            }
            if ($featureId <= 0) {
                return 0;
            }
        }

        // Check if stored variant still exists in CS-Cart
        if ($variantId > 0) {
            $exists = db_get_field(
                'SELECT variant_id FROM ?:product_feature_variants WHERE variant_id = ?i AND feature_id = ?i',
                $variantId,
                $featureId,
            );
            if ($exists) {
                return $variantId;
            }
            if ($variantSource === 'manual') {
                return 0;
            }
        }

        // Never auto-resolve manually locked mappings
        if ($variantSource === 'manual') {
            return $variantId;
        }

        // Try 3-pass name match against existing CS-Cart variants
        $matched = $this->findVariantByName($mapping, $featureId);
        if ($matched > 0) {
            $this->repo->updateVariantId($mapId, $matched, 'auto');
            return $matched;
        }

        // Auto-create the variant
        $nameEn = trim(TypeCoerce::toString($mapping['display_name_en'] ?? ''));
        $nameRo = trim(TypeCoerce::toString($mapping['display_name_ro'] ?? ''));
        if ($nameEn === '') {
            return 0;
        }

        $variantId = $this->createVariant($featureId, $nameEn, $nameRo);
        if ($variantId > 0) {
            $this->repo->updateVariantId($mapId, $variantId, 'auto');
        }

        return $variantId;
    }

    /**
     * 3-pass variant name matching.
     *
     * Per language (EN first, then RO fallback):
     *   1. Exact match
     *   2. Case-insensitive match via LOWER()
     *   3. Normalized match — strips non-alphanumeric chars and collapses whitespace
     *
     * @param array<string, mixed> $mapping
     * @return int Matched variant_id or 0
     */
    #[\Override]
    public function findVariantByName(array $mapping, int $featureId): int
    {
        $nameEn = trim(TypeCoerce::toString($mapping['display_name_en'] ?? ''));
        $nameRo = trim(TypeCoerce::toString($mapping['display_name_ro'] ?? ''));

        if ($featureId <= 0 || $nameEn === '') {
            return 0;
        }

        $candidates = [['en', $nameEn]];
        if ($nameRo !== '' && $nameRo !== $nameEn) {
            $candidates[] = ['ro', $nameRo];
        }

        foreach ($candidates as [$lang, $name]) {
            // Pass 1: Exact match
            $variantId = db_get_field(
                'SELECT v.variant_id
                 FROM ?:product_feature_variants v
                 JOIN ?:product_feature_variant_descriptions vd ON v.variant_id = vd.variant_id
                 WHERE v.feature_id = ?i AND vd.lang_code = ?s AND vd.variant = ?s
                 LIMIT 1',
                $featureId,
                $lang,
                $name,
            );
            if (TypeCoerce::toInt($variantId) > 0) {
                return TypeCoerce::toInt($variantId);
            }

            // Pass 2: Case-insensitive match
            $variantId = db_get_field(
                'SELECT v.variant_id
                 FROM ?:product_feature_variants v
                 JOIN ?:product_feature_variant_descriptions vd ON v.variant_id = vd.variant_id
                 WHERE v.feature_id = ?i AND vd.lang_code = ?s AND LOWER(vd.variant) = LOWER(?s)
                 LIMIT 1',
                $featureId,
                $lang,
                $name,
            );
            if (TypeCoerce::toInt($variantId) > 0) {
                return TypeCoerce::toInt($variantId);
            }

            // Pass 3: Normalized match — strips punctuation, collapses whitespace
            $normalized = self::normalizeName($name);
            $rows = db_get_array(
                'SELECT v.variant_id, vd.variant
                 FROM ?:product_feature_variants v
                 JOIN ?:product_feature_variant_descriptions vd ON v.variant_id = vd.variant_id
                 WHERE v.feature_id = ?i AND vd.lang_code = ?s',
                $featureId,
                $lang,
            );
            foreach (TypeCoerce::toList($rows) as $row) {
                $rowArr = TypeCoerce::toStringMap($row);
                if (self::normalizeName(TypeCoerce::toString($rowArr['variant'] ?? '')) === $normalized) {
                    return TypeCoerce::toInt($rowArr['variant_id'] ?? 0);
                }
            }
        }

        return 0;
    }

    /**
     * The same 3-pass match over an already-loaded name => id list (the admin
     * "Auto-resolve variants" batch): exact, case-insensitive, normalized.
     *
     * @param array<string, int> $variantNameToId
     */
    public static function matchName(string $name, array $variantNameToId): ?int
    {
        if (isset($variantNameToId[$name])) {
            return $variantNameToId[$name];
        }
        $lower = mb_strtolower($name);
        foreach ($variantNameToId as $vName => $vId) {
            if (mb_strtolower((string) $vName) === $lower) {
                return $vId;
            }
        }
        $normalized = self::normalizeName($name);
        foreach ($variantNameToId as $vName => $vId) {
            if (self::normalizeName((string) $vName) === $normalized) {
                return $vId;
            }
        }

        return null;
    }

    /**
     * Normalize a variant name for fuzzy comparison.
     *
     * Lowercases, strips non-alphanumeric/non-space chars, and collapses whitespace.
     * e.g. "All-Inclusive (Premium)" => "all inclusive premium"
     */
    public static function normalizeName(string $name): string
    {
        $name = mb_strtolower($name, 'UTF-8');
        $name = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $name) ?? $name;
        return trim(preg_replace('/\s+/', ' ', $name) ?? $name);
    }
}
