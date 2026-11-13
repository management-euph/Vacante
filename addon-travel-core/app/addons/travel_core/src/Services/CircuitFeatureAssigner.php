<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Services;

use Tygh\Addons\TravelCore\Dto\Circuit\CircuitFeatureFacts;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Traits\CsCartFeatureAssignment;

/**
 * Writes a circuit's facts to its product's CS-Cart features, as the admin
 * mapped them (Feature Mappings › Circuits, CircuitFeatureConfig).
 *
 * The feature decides the shape, never this class: a Number feature gets
 * the number, a Text feature the text, a feature with a list of values the
 * matching value — so the storefront filters stay CS-Cart's own.
 *
 *  - transport, month: only the values the admin linked; an unlinked one
 *    is left out.
 *  - countries, departure city, tags, included, not included: open lists; a
 *    value the feature does not have yet is created (cut to the 255 letters a
 *    value name holds).
 *  - duration: the nights, in the feature's own style.
 *
 * A fact the provider does not know leaves its feature as it is, except
 * the months: no departure on sale means no month.
 */
final class CircuitFeatureAssigner
{
    use CsCartFeatureAssignment;

    /** CS-Cart feature types holding a list of values (variants). */
    private const array VARIANT_TYPES = ['S', 'N', 'E', 'M'];

    /** The width of a variant's name in the database. */
    private const int MAX_NAME = 255;

    /** @var array<int, string> feature_id => feature_type */
    private array $types = [];

    /** @var array<int, array<int|string, int>> feature_id => variant name => variant_id (a numeric name such as "7" is an int key in PHP) */
    private array $variants = [];

    /**
     * @param array{group_id: int, features: array<string, int>, values: array{transport: array<string, int>, month: array<int, int>}}|null $config the mapping; null reads the saved one
     */
    public function __construct(private ?array $config = null)
    {
    }

    /**
     * @return int how many features were written for the product
     */
    public function assign(int $productId, CircuitFeatureFacts $facts): int
    {
        $config = $this->config ??= CircuitFeatureConfig::load();
        if ($productId <= 0 || CircuitFeatureConfig::isEmpty($config)) {
            return 0;
        }
        $features = $config['features'];
        $written = 0;

        $transportVariant = $config['values']['transport'][$facts->transport] ?? 0;
        if ($features['transport'] > 0 && $transportVariant > 0) {
            $written += $this->setVariants($productId, $features['transport'], [$transportVariant]);
        }

        if ($features['duration'] > 0 && $facts->nights > 0) {
            $written += $this->setDuration($productId, $features['duration'], $facts->nights);
        }

        foreach (['countries' => $facts->countries, 'departure_city' => $facts->departureCities, 'tags' => $facts->tags, 'included' => $facts->included, 'not_included' => $facts->notIncluded] as $field => $names) {
            if ($features[$field] > 0 && $names !== []) {
                $written += $this->setVariants($productId, $features[$field], $this->variantIds($features[$field], $names));
            }
        }

        if ($features['month'] > 0 && array_filter($config['values']['month']) !== []) {
            $written += $this->setVariants($productId, $features['month'], self::monthVariants($facts->months, $config['values']['month']), true);
        }

        return $written;
    }

    /**
     * The feature values of the months a circuit leaves in: the linked ones
     * only, each once, in calendar order.
     *
     * @param list<int> $months 1–12
     * @param array<int, int> $links month => variant_id (0: not linked)
     *
     * @return list<int>
     */
    public static function monthVariants(array $months, array $links): array
    {
        $months = array_values(array_unique(array_filter($months, static fn (int $m): bool => $m >= 1 && $m <= 12)));
        sort($months);
        $ids = [];
        foreach ($months as $month) {
            if (($links[$month] ?? 0) > 0) {
                $ids[] = $links[$month];
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * The value of a list feature that stands for $nights: the one whose
     * name starts with that number ("7", "7 nights", "7 nopți").
     *
     * @param array<int|string, int> $variants variant name => variant_id
     */
    public static function nightsVariant(int $nights, array $variants): int
    {
        foreach ($variants as $name => $id) {
            if (preg_match('/^\s*(\d+)(?!\d)/', (string) $name, $m) === 1 && (int) $m[1] === $nights) {
                return $id;
            }
        }

        return 0;
    }

    /**
     * A value name as a feature value can hold it: one line, trimmed, and at
     * most 255 characters (the width of a variant's name), cut with "…".
     */
    public static function fit(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);

        return mb_strlen($name) > self::MAX_NAME ? rtrim(mb_substr($name, 0, self::MAX_NAME - 1)) . '…' : $name;
    }

    /** "7 nights" / "7 nopți": the value made when a list feature has none for the nights. */
    public static function nightsLabel(int $nights, string $lang): string
    {
        if ($lang === 'ro') {
            return $nights . ($nights === 1 ? ' noapte' : ' nopți');
        }

        return $nights . ($nights === 1 ? ' night' : ' nights');
    }

    private function setDuration(int $productId, int $featureId, int $nights): int
    {
        $type = $this->type($featureId);
        if ($type === 'O') {
            return $this->setPlainValue($productId, $featureId, static fn (string $lang): array => ['value' => '', 'value_int' => $nights]);
        }
        if ($type === 'T') {
            return $this->setPlainValue($productId, $featureId, static fn (string $lang): array => ['value' => self::nightsLabel($nights, $lang), 'value_int' => null]);
        }
        if (!in_array($type, self::VARIANT_TYPES, true)) {
            return 0;
        }

        $variantId = self::nightsVariant($nights, $this->variantNames($featureId));
        if ($variantId === 0) {
            // A Number list holds bare numbers; a text list reads "7 nights".
            $variantId = $type === 'N'
                ? $this->createVariant($featureId, (string) $nights)
                : $this->createVariant($featureId, self::nightsLabel($nights, 'en'), self::nightsLabel($nights, 'ro'));
            if ($variantId > 0) {
                $this->variants[$featureId][(string) $nights] = $variantId;
            }
        }
        if ($variantId === 0) {
            return 0;
        }
        $written = $this->setVariants($productId, $featureId, [$variantId]);
        if ($type === 'N') {
            // A Number list filters on value_int: the number, not the variant id.
            db_query('UPDATE ?:product_features_values SET value_int = ?d WHERE feature_id = ?i AND product_id = ?i', $nights, $featureId, $productId);
        }

        return $written;
    }

    /**
     * A feature with no list of values (Number, Text): one row a language.
     *
     * @param callable(string): array{value: string, value_int: int|null} $row the value for a language
     */
    private function setPlainValue(int $productId, int $featureId, callable $row): int
    {
        db_query('DELETE FROM ?:product_features_values WHERE feature_id = ?i AND product_id = ?i', $featureId, $productId);
        foreach ($this->getActiveLanguages() as $lang) {
            db_query('INSERT INTO ?:product_features_values ?e', ['feature_id' => $featureId, 'product_id' => $productId, 'variant_id' => 0, 'lang_code' => $lang] + $row($lang));
        }

        return 1;
    }

    /**
     * The product's values of a list feature become exactly $variantIds
     * (several for checkboxes; the first one for a single-value feature).
     *
     * @param list<int> $variantIds
     * @param bool $clearWhenEmpty an empty list removes the product's values (the months)
     */
    private function setVariants(int $productId, int $featureId, array $variantIds, bool $clearWhenEmpty = false): int
    {
        $type = $this->type($featureId);
        if (!in_array($type, self::VARIANT_TYPES, true)) {
            return 0;
        }
        if ($variantIds === []) {
            if ($clearWhenEmpty) {
                db_query('DELETE FROM ?:product_features_values WHERE feature_id = ?i AND product_id = ?i', $featureId, $productId);
            }

            return 0;
        }
        if ($type === 'M') {
            $this->syncCheckboxValues($productId, $featureId, $variantIds);
        } else {
            $this->assignSelectBoxValue($productId, $featureId, $variantIds[0]);
        }

        return 1;
    }

    /**
     * The feature's values named $names; one it does not have is created.
     *
     * @param list<string> $names
     *
     * @return list<int>
     */
    private function variantIds(int $featureId, array $names): array
    {
        $known = $this->variantNames($featureId);
        $ids = [];
        foreach ($names as $name) {
            $name = self::fit($name);
            if ($name === '') {
                continue;
            }
            $id = VariantResolver::matchName($name, $known) ?? 0;
            if ($id === 0) {
                $id = $this->createVariant($featureId, $name);
                if ($id > 0) {
                    $known[$name] = $id;
                    $this->variants[$featureId][$name] = $id;
                }
            }
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return array<int|string, int> the feature's values, name => variant_id (every language)
     */
    private function variantNames(int $featureId): array
    {
        if (!isset($this->variants[$featureId])) {
            $names = [];
            foreach (TypeCoerce::toRowList(db_get_array(
                'SELECT v.variant_id, d.variant FROM ?:product_feature_variants v'
                . ' JOIN ?:product_feature_variant_descriptions d ON d.variant_id = v.variant_id'
                . ' WHERE v.feature_id = ?i ORDER BY v.position, v.variant_id',
                $featureId,
            )) as $row) {
                $name = TypeCoerce::toString($row['variant'] ?? '');
                if ($name !== '' && !isset($names[$name])) {
                    $names[$name] = TypeCoerce::toInt($row['variant_id'] ?? 0);
                }
            }
            $this->variants[$featureId] = $names;
        }

        return $this->variants[$featureId];
    }

    private function type(int $featureId): string
    {
        return $this->types[$featureId] ??= TypeCoerce::toString(db_get_field('SELECT feature_type FROM ?:product_features WHERE feature_id = ?i', $featureId));
    }
}
