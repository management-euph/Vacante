<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Services;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * What the hotel list card (board 15) says under the place, from the
 * CS-Cart product features the suppliers' syncs fill through Travel Core's
 * feature mapping (FeatureMapper): the meal plans ("Mic dejun ·
 * Demipensiune") and the three facilities a guest picks a hotel by first
 * (pool, spa, the beach, parking, Wi-Fi…). The same features at every
 * supplier, so the card reads the same; one query for the whole page.
 */
final class ListCardFacts
{
    /** Meal plans shown at most. */
    public const int MEALS = 4;

    /** Facilities shown at most. */
    public const int HIGHLIGHTS = 3;

    /**
     * Facilities a guest chooses by, first match first (lower case, any
     * part of the name); the others keep the features' own order.
     */
    private const array RANK = [
        ['piscin', 'pool'],
        ['spa', 'wellness'],
        ['plaj', 'beach'],
        ['aquapark', 'tobogan'],
        ['parcare', 'parking'],
        ['wi-fi', 'wifi', 'internet'],
        ['restaurant'],
        ['fitness', 'sală de sport', 'gym'],
        ['copii', 'kids', 'children'],
    ];

    /** @var \Closure(string): int */
    private readonly \Closure $featureId;

    /** @var \Closure(list<int>, list<int>): list<array<string, mixed>> */
    private readonly \Closure $rows;

    /**
     * @param (\Closure(string): int)|null $featureId feature type ('board', 'hotel_facility') => its CS-Cart feature id, 0 when not set
     * @param (\Closure(list<int>, list<int>): list<array<string, mixed>>)|null $rows product ids + feature ids => rows (product_id, feature_id, variant), in the features' order
     */
    public function __construct(?\Closure $featureId = null, ?\Closure $rows = null)
    {
        $this->featureId = $featureId ?? static fn (string $type): int => FeatureMapper::getFeatureId($type);
        $this->rows = $rows ?? static fn (array $productIds, array $featureIds): array => TypeCoerce::toRowList(db_get_array(
            'SELECT v.product_id, v.feature_id, vd.variant
             FROM ?:product_features_values v
             JOIN ?:product_feature_variant_descriptions vd ON vd.variant_id = v.variant_id AND vd.lang_code = ?s
             LEFT JOIN ?:product_feature_variants fv ON fv.variant_id = v.variant_id
             WHERE v.product_id IN (?n) AND v.feature_id IN (?n) AND v.lang_code = ?s
             ORDER BY fv.position, vd.variant',
            defined('CART_LANGUAGE') ? TypeCoerce::toString(CART_LANGUAGE) : 'en',
            $productIds,
            $featureIds,
            defined('CART_LANGUAGE') ? TypeCoerce::toString(CART_LANGUAGE) : 'en',
        ));
    }

    /**
     * @param list<int> $productIds the page's hotel products
     *
     * @return array<int, array{meals: string, highlights: list<string>}> product id => facts; a product with none is absent
     */
    public function forProducts(array $productIds): array
    {
        $meal = ($this->featureId)('board');
        $facility = ($this->featureId)('hotel_facility');
        $featureIds = array_values(array_unique(array_filter([$meal, $facility], static fn (int $id): bool => $id > 0)));
        if ($productIds === [] || $featureIds === []) {
            return [];
        }

        /** @var array<int, array{meals: list<string>, facilities: list<string>}> $found */
        $found = [];
        foreach (($this->rows)($productIds, $featureIds) as $row) {
            $productId = TypeCoerce::toInt($row['product_id'] ?? 0);
            $variant = trim(TypeCoerce::toString($row['variant'] ?? ''));
            if ($productId <= 0 || $variant === '') {
                continue;
            }
            $slot = TypeCoerce::toInt($row['feature_id'] ?? 0) === $meal ? 'meals' : 'facilities';
            $found[$productId] ??= ['meals' => [], 'facilities' => []];
            if (!in_array($variant, $found[$productId][$slot], true)) {
                $found[$productId][$slot][] = $variant;
            }
        }

        $facts = [];
        foreach ($found as $productId => $lists) {
            $facts[$productId] = [
                'meals' => implode(' · ', array_slice($lists['meals'], 0, self::MEALS)),
                'highlights' => self::highlights($lists['facilities']),
            ];
        }

        return $facts;
    }

    /**
     * The facilities a guest picks a hotel by, at most HIGHLIGHTS: ranked
     * by RANK, the rest in their own order.
     *
     * @param list<string> $facilities the hotel's facilities, in the features' order
     *
     * @return list<string>
     */
    public static function highlights(array $facilities): array
    {
        $ranked = [];
        foreach ($facilities as $index => $name) {
            $lower = mb_strtolower($name, 'UTF-8');
            $rank = count(self::RANK);
            foreach (self::RANK as $position => $words) {
                foreach ($words as $word) {
                    if (str_contains($lower, $word)) {
                        $rank = $position;
                        break 2;
                    }
                }
            }
            $ranked[] = [$rank, $index, $name];
        }
        usort($ranked, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return array_slice(array_column($ranked, 2), 0, self::HIGHLIGHTS);
    }
}
