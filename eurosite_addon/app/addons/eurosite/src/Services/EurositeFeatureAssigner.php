<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Services;

use Tygh\Addons\Eurosite\Api\EurositeNormalizer;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\FeatureMapper;
use Tygh\Addons\TravelCore\Services\TravelGroupResolver;
use Tygh\Addons\TravelCore\Traits\CsCartFeatureAssignment;

/**
 * Gives a Eurosite product the shared travel features (Feature Mappings):
 * star rating, property type, board, resort, facilities and the travel
 * groups derived from them, the same features Novoton and Sphinx products get.
 *
 * Every value goes through FeatureMapper as source "eurosite", so what a
 * value becomes is set on the Feature Mappings pages, never here: a value
 * without an alias is logged to the Unmapped values page (and a new resort
 * is registered), and assigned once it is linked there.
 *
 * Inputs, all from the hotel row + details cache (HotelRepository details):
 *  - category        <ProductCategory>, 1–5 stars
 *  - hotel_class     the offers' <Class> (Hotel, Vila, Apartament…), else the name
 *  - meals           the meal plans the offers sold ("Demipensiune|Mic dejun")
 *  - city_name       the destination = the resort
 *  - payload_json    ['facilities']: the operator's words from <DescriptionDet>
 */
final class EurositeFeatureAssigner
{
    use CsCartFeatureAssignment;

    public const string SOURCE = 'eurosite';

    private readonly EurositeNormalizer $normalizer;

    /** @var array<int, string> feature_id => CS-Cart feature type (S, M, …) */
    private array $featureTypes = [];

    public function __construct(?EurositeNormalizer $normalizer = null)
    {
        $this->normalizer = $normalizer ?? new EurositeNormalizer();
    }

    /**
     * The form facility labels are aliased in: lower case, Romanian
     * diacritics folded, spaces collapsed, trailing punctuation dropped.
     */
    public static function facilityKey(string $label): string
    {
        $key = mb_strtolower(trim($label), 'UTF-8');
        $key = strtr($key, ['ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't']);
        $key = (string) preg_replace('/\s+/u', ' ', $key);

        return trim($key, " \t.,;:!");
    }

    /**
     * The codes each feature type gets for a hotel (pure: the normalizer only).
     *
     * @param array<string, mixed> $hotel
     * @return array{stars: list<string>, property_type: list<string>, board: list<string>, resort: list<string>, facilities: array<string, string>}
     *                                                                                                                                               facilities: facilityKey => the operator's label
     */
    public function codes(array $hotel): array
    {
        $stars = $this->normalizer->normalizeStarRating(TypeCoerce::toString($hotel['category'] ?? ''));
        $class = trim(TypeCoerce::toString($hotel['hotel_class'] ?? ''));
        $property = $this->normalizer->normalizePropertyType($class !== '' ? $class : TypeCoerce::toString($hotel['name'] ?? ''));

        $board = [];
        foreach (explode('|', TypeCoerce::toString($hotel['meals'] ?? '')) as $meal) {
            $code = $this->normalizer->normalizeBoardCode($meal);
            if ($code !== null) {
                $board[$code] = true;
            }
        }

        $resort = $this->normalizer->normalizeResort(TypeCoerce::toString($hotel['city_name'] ?? ''));

        $payload = json_decode(TypeCoerce::toString($hotel['payload_json'] ?? ''), true);
        $facilities = [];
        foreach (is_array($payload) && is_array($payload['facilities'] ?? null) ? $payload['facilities'] : [] as $label) {
            $label = trim(TypeCoerce::toString($label));
            $key = self::facilityKey($label);
            if ($key !== '') {
                $facilities[$key] = $label;
            }
        }

        return [
            'stars' => $stars !== null ? [$stars] : [],
            'property_type' => $property !== null ? [$property] : [],
            'board' => array_map('strval', array_keys($board)),
            'resort' => $resort !== null ? [$resort] : [],
            'facilities' => $facilities,
        ];
    }

    /**
     * Assign every feature the hotel has a value for.
     *
     * @param array<string, mixed> $hotel
     * @return int feature values the product now carries from this run
     */
    public function assign(int $productId, array $hotel): int
    {
        if ($productId <= 0) {
            return 0;
        }
        $codes = $this->codes($hotel);

        $assigned = 0;
        foreach (['stars', 'property_type', 'board', 'resort'] as $type) {
            $assigned += $this->assignType($productId, $type, $codes[$type]);
        }

        // Facilities: each label is looked up across the facility types.
        $wanted = [];
        $resolvedCodes = [];
        foreach ($codes['facilities'] as $key => $label) {
            $mapping = FeatureMapper::resolveWithVariantFacility(self::SOURCE, $key);
            if ($mapping === null || $mapping === []) {
                FeatureMapper::handleUnmapped(self::SOURCE, 'hotel_facility', $key, $label);
                continue;
            }
            $resolvedCodes[] = TypeCoerce::toString($mapping['canonical_code'] ?? '');
            $this->collect($wanted, $mapping);
        }
        $assigned += $this->write($productId, $wanted);

        // Travel groups, derived from the facilities (resolved by code).
        $assigned += $this->assignType($productId, 'travel_group', array_values(TravelGroupResolver::derive(array_values(array_filter($resolvedCodes)))));

        FeatureMapper::clearCache();

        return $assigned;
    }

    /**
     * @param list<string> $codes
     */
    private function assignType(int $productId, string $type, array $codes): int
    {
        $wanted = [];
        foreach ($codes as $code) {
            $mapping = FeatureMapper::resolveWithVariant(self::SOURCE, $type, $code);
            if ($mapping === null || $mapping === []) {
                // Resorts register themselves here (a dynamic type): the
                // next run assigns them. Strict types are logged as unmapped.
                FeatureMapper::handleUnmapped(self::SOURCE, $type, $code);
                $mapping = $type === 'resort' ? FeatureMapper::resolveWithVariant(self::SOURCE, $type, $code) : null;
                if ($mapping === null || $mapping === []) {
                    continue;
                }
            }
            $this->collect($wanted, $mapping);
        }

        return $this->write($productId, $wanted);
    }

    /**
     * @param array<int, list<int>> $wanted feature_id => variant ids
     * @param array<string, mixed> $mapping
     */
    private function collect(array &$wanted, array $mapping): void
    {
        $featureId = TypeCoerce::toInt($mapping['cscart_feature_id'] ?? 0);
        $variantId = TypeCoerce::toInt($mapping['cscart_variant_id'] ?? 0);
        if ($featureId > 0 && $variantId > 0) {
            $wanted[$featureId][] = $variantId;
        }
    }

    /**
     * Select box: the first value. Multiple checkboxes: exactly these values
     * (a value the hotel no longer has is removed).
     *
     * @param array<int, list<int>> $wanted
     */
    private function write(int $productId, array $wanted): int
    {
        $count = 0;
        foreach ($wanted as $featureId => $variantIds) {
            $variantIds = array_values(array_unique($variantIds));
            $this->featureTypes[$featureId] ??= TypeCoerce::toString(db_get_field(
                'SELECT feature_type FROM ?:product_features WHERE feature_id = ?i',
                $featureId,
            ));
            $count += match ($this->featureTypes[$featureId]) {
                'S' => $this->assignSelectBoxValue($productId, $featureId, $variantIds[0]) ? 1 : 0,
                'M' => $this->syncCheckboxValues($productId, $featureId, $variantIds),
                default => 0,
            };
        }

        return $count;
    }
}
