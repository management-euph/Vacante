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
 *  - duration, difficulty, group size, stars: numbers, in the feature's own
 *    style: a Number feature gets the number (the lowest of the stars), a
 *    Text feature its words ("7 nights", "4 stars"), a list the value that
 *    starts with the number ("7", "4 stars"), made when missing. A
 *    difficulty value may also be named by its word alone ("Moderate").
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

    /** @var array<string, string> field => what became of it, for the last assign() (REPORT_* values) */
    private array $report = [];

    public const string REPORT_WRITTEN = 'written';
    public const string REPORT_NO_DATA = 'no data stored for the circuit (run a full circuits sync)';
    public const string REPORT_NOT_LINKED = 'value not linked';
    public const string REPORT_STYLE = 'feature style cannot hold values';
    public const string REPORT_NO_MONTHS = 'no departure on sale in a month you linked';

    /**
     * What became of each MAPPED fact in the last assign(): REPORT_WRITTEN,
     * or why nothing was written. A fact with no feature chosen is not
     * listed: it is not a problem.
     *
     * @return array<string, string> field => REPORT_*
     */
    public function report(): array
    {
        return $this->report;
    }

    /**
     * @return int how many features were written for the product
     */
    public function assign(int $productId, CircuitFeatureFacts $facts): int
    {
        $this->report = [];
        $config = $this->config ??= CircuitFeatureConfig::load();
        if ($productId <= 0 || CircuitFeatureConfig::isEmpty($config)) {
            return 0;
        }
        $features = $config['features'];
        $written = 0;

        if ($features['transport'] > 0) {
            $transportVariant = $config['values']['transport'][$facts->transport] ?? 0;
            if ($facts->transport === '') {
                $this->report['transport'] = self::REPORT_NO_DATA;
            } elseif ($transportVariant <= 0) {
                $this->report['transport'] = self::REPORT_NOT_LINKED;
            } else {
                $written += $this->note('transport', $this->setVariants($productId, $features['transport'], [$transportVariant]));
            }
        }

        if ($features['duration'] > 0) {
            if ($facts->nights <= 0) {
                $this->report['duration'] = self::REPORT_NO_DATA;
            } else {
                $written += $this->note('duration', $this->setNumbers($productId, $features['duration'], [$facts->nights], self::nightsLabel(...)));
            }
        }

        $numbers = [
            'difficulty' => $facts->difficulty >= 1 && $facts->difficulty <= 5 ? [$facts->difficulty] : [],
            'group_size' => $facts->groupSize > 0 ? [$facts->groupSize] : [],
            'stars' => array_values(array_filter($facts->stars, static fn (int $n): bool => $n >= 1 && $n <= 5)),
        ];
        $labels = ['difficulty' => self::difficultyLabel(...), 'group_size' => self::groupLabel(...), 'stars' => self::starsLabel(...)];
        foreach ($numbers as $field => $values) {
            if ($features[$field] <= 0) {
                continue;
            }
            if ($values === []) {
                $this->report[$field] = self::REPORT_NO_DATA;
            } else {
                $written += $this->note($field, $this->setNumbers($productId, $features[$field], $values, $labels[$field], $field === 'difficulty' ? self::difficultyWords() : []));
            }
        }

        foreach (['countries' => $facts->countries, 'departure_city' => $facts->departureCities, 'tags' => $facts->tags, 'included' => $facts->included, 'not_included' => $facts->notIncluded] as $field => $names) {
            if ($features[$field] <= 0) {
                continue;
            }
            if ($names === []) {
                $this->report[$field] = self::REPORT_NO_DATA;
            } elseif (!$this->holdsValues($features[$field])) {
                // Checked before the values are made: no stray values on a feature that cannot hold them.
                $this->report[$field] = self::REPORT_STYLE;
            } else {
                $written += $this->note($field, $this->setVariants($productId, $features[$field], $this->variantIds($features[$field], $names)));
            }
        }

        if ($features['month'] > 0) {
            if (array_filter($config['values']['month']) === []) {
                $this->report['month'] = self::REPORT_NOT_LINKED;
            } elseif (!$this->holdsValues($features['month'])) {
                $this->report['month'] = self::REPORT_STYLE;
            } else {
                $variants = self::monthVariants($facts->months, $config['values']['month']);
                if ($variants === []) {
                    // None on sale, or none in a linked month: the product keeps no month.
                    $this->setVariants($productId, $features['month'], [], true);
                    $this->report['month'] = self::REPORT_NO_MONTHS;
                } else {
                    $written += $this->note('month', $this->setVariants($productId, $features['month'], $variants, true));
                }
            }
        }

        return $written;
    }

    /** Records REPORT_WRITTEN (or REPORT_STYLE when nothing could be written) for a field; returns $written. */
    private function note(string $field, int $written): int
    {
        $this->report[$field] = $written > 0 ? self::REPORT_WRITTEN : self::REPORT_STYLE;

        return $written;
    }

    /** Whether the feature has a list of values (S, N, E, M). */
    private function holdsValues(int $featureId): bool
    {
        return in_array($this->type($featureId), self::VARIANT_TYPES, true);
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
        return self::numberVariant($nights, $variants);
    }

    /**
     * The value of a list feature whose name starts with $number ("4",
     * "4 stars", "4 stele"); 0 when none does.
     *
     * @param array<int|string, int> $variants variant name => variant_id
     */
    public static function numberVariant(int $number, array $variants): int
    {
        foreach ($variants as $name => $id) {
            if (preg_match('/^\s*(\d+)(?!\d)/', (string) $name, $m) === 1 && (int) $m[1] === $number) {
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

    /** The difficulty's words, by level 1–5 (the page's own: travel_core.circuit_difficulty_N). */
    private const array DIFFICULTY_WORDS = [
        'en' => [1 => 'Easy', 2 => 'Light', 3 => 'Moderate', 4 => 'Demanding', 5 => 'Challenging'],
        'ro' => [1 => 'Ușor', 2 => 'Lejer', 3 => 'Moderat', 4 => 'Solicitant', 5 => 'Dificil'],
    ];

    /** "3 – Moderate" / "3 – Moderat": the value made for a difficulty level. */
    public static function difficultyLabel(int $level, string $lang): string
    {
        $words = self::DIFFICULTY_WORDS[$lang === 'ro' ? 'ro' : 'en'];

        return isset($words[$level]) ? $level . ' – ' . $words[$level] : (string) $level;
    }

    /**
     * Each level's words in every language ("Moderate", "Moderat"): a list
     * value named so stands for the level.
     *
     * @return array<int, list<string>>
     */
    private static function difficultyWords(): array
    {
        $words = [];
        foreach (self::DIFFICULTY_WORDS as $byLevel) {
            foreach ($byLevel as $level => $word) {
                $words[$level][] = $word;
            }
        }

        return $words;
    }

    /** "20 people" / "20 persoane": the value made for a group size. */
    public static function groupLabel(int $people, string $lang): string
    {
        if ($lang === 'ro') {
            return $people . ($people === 1 ? ' persoană' : ' persoane');
        }

        return $people . ($people === 1 ? ' person' : ' people');
    }

    /** "4 stars" / "4 stele": the value made for the hotels' stars. */
    public static function starsLabel(int $stars, string $lang): string
    {
        if ($lang === 'ro') {
            return $stars . ($stars === 1 ? ' stea' : ' stele');
        }

        return $stars . ($stars === 1 ? ' star' : ' stars');
    }

    /**
     * Numbers (the nights, a difficulty, a group size, the stars) in the
     * feature's own style: a Number feature holds the lowest, a Text
     * feature their words, a list the values that start with each number
     * (several for checkboxes, the lowest otherwise), made when missing.
     *
     * @param list<int> $numbers at least one, each above 0
     * @param \Closure(int, string): string $label a number's words in a language
     * @param array<int, list<string>> $words number => other names a list value may have for it
     */
    private function setNumbers(int $productId, int $featureId, array $numbers, \Closure $label, array $words = []): int
    {
        $numbers = array_values(array_unique($numbers));
        sort($numbers);
        $lowest = $numbers[0] ?? 0;
        if ($lowest <= 0) {
            return 0;
        }
        $type = $this->type($featureId);
        if ($type === 'O') {
            return $this->setPlainValue($productId, $featureId, static fn (string $lang): array => ['value' => '', 'value_int' => $lowest]);
        }
        if ($type === 'T') {
            return $this->setPlainValue($productId, $featureId, static fn (string $lang): array => [
                'value' => implode(', ', array_map(static fn (int $n): string => $label($n, $lang), $numbers)),
                'value_int' => null,
            ]);
        }
        if (!in_array($type, self::VARIANT_TYPES, true)) {
            return 0;
        }

        $variantIds = [];
        foreach ($type === 'M' ? $numbers : [$lowest] as $number) {
            $known = $this->variantNames($featureId);
            $variantId = self::numberVariant($number, $known);
            foreach ($variantId === 0 ? ($words[$number] ?? []) : [] as $word) {
                // A value named by its words alone ("Moderate") stands for the number too.
                $variantId = VariantResolver::matchName($word, $known) ?? 0;
                if ($variantId > 0) {
                    break;
                }
            }
            if ($variantId === 0) {
                // A Number list holds bare numbers; a text list reads "7 nights".
                $variantId = $type === 'N'
                    ? $this->createVariant($featureId, (string) $number)
                    : $this->createVariant($featureId, $label($number, 'en'), $label($number, 'ro'));
                if ($variantId > 0) {
                    $this->variants[$featureId][(string) $number] = $variantId;
                }
            }
            if ($variantId > 0) {
                $variantIds[] = $variantId;
            }
        }
        if ($variantIds === []) {
            return 0;
        }
        $written = $this->setVariants($productId, $featureId, $variantIds);
        if ($type === 'N') {
            // A Number list filters on value_int: the number, not the variant id.
            db_query('UPDATE ?:product_features_values SET value_int = ?d WHERE feature_id = ?i AND product_id = ?i', $lowest, $featureId, $productId);
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
