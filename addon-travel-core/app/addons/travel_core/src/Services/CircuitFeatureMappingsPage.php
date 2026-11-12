<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Services;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * What the Feature Mappings › Circuits tab shows: the feature groups, the
 * features of the chosen group, and the values of the two features whose
 * values are linked by hand (transport, month).
 */
final class CircuitFeatureMappingsPage
{
    /** CS-Cart feature types a circuit fact can be written to. */
    private const array USABLE_TYPES = ['S', 'N', 'E', 'M', 'O', 'T'];

    /**
     * @return array<int, string> group feature_id => name
     */
    public static function groups(string $lang): array
    {
        return self::names("f.feature_type = 'G'", $lang);
    }

    /**
     * The features a fact can be mapped to: those of the group when one is
     * chosen, every feature otherwise.
     *
     * @return array<int, array{name: string, type: string}>
     */
    public static function features(int $groupId, string $lang): array
    {
        $rows = TypeCoerce::toRowList(db_get_array(
            'SELECT f.feature_id, f.feature_type, d.description FROM ?:product_features f'
            . ' LEFT JOIN ?:product_features_descriptions d ON d.feature_id = f.feature_id AND d.lang_code = ?s'
            . ' WHERE f.feature_type IN (?a) ?p ORDER BY d.description',
            $lang,
            self::USABLE_TYPES,
            $groupId > 0 ? db_quote('AND f.parent_id = ?i', $groupId) : '',
        ));
        $features = [];
        foreach ($rows as $row) {
            $features[TypeCoerce::toInt($row['feature_id'] ?? 0)] = [
                'name' => TypeCoerce::toString($row['description'] ?? ''),
                'type' => TypeCoerce::toString($row['feature_type'] ?? ''),
            ];
        }

        return $features;
    }

    /**
     * @return array<int, string> variant_id => name, of one feature
     */
    public static function variants(int $featureId, string $lang): array
    {
        if ($featureId <= 0) {
            return [];
        }
        $variants = [];
        foreach (TypeCoerce::toRowList(db_get_array(
            'SELECT v.variant_id, d.variant FROM ?:product_feature_variants v'
            . ' LEFT JOIN ?:product_feature_variant_descriptions d ON d.variant_id = v.variant_id AND d.lang_code = ?s'
            . ' WHERE v.feature_id = ?i ORDER BY v.position, d.variant',
            $lang,
            $featureId,
        )) as $row) {
            $variants[TypeCoerce::toInt($row['variant_id'] ?? 0)] = TypeCoerce::toString($row['variant'] ?? '');
        }

        return $variants;
    }

    /**
     * @return array<int, string>
     */
    private static function names(string $where, string $lang): array
    {
        $names = [];
        foreach (TypeCoerce::toRowList(db_get_array(
            'SELECT f.feature_id, d.description FROM ?:product_features f'
            . ' LEFT JOIN ?:product_features_descriptions d ON d.feature_id = f.feature_id AND d.lang_code = ?s'
            . ' WHERE ?p ORDER BY d.description',
            $lang,
            $where,
        )) as $row) {
            $names[TypeCoerce::toInt($row['feature_id'] ?? 0)] = TypeCoerce::toString($row['description'] ?? '');
        }

        return $names;
    }
}
