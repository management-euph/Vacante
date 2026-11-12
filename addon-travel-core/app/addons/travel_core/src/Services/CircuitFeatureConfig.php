<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Services;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * The admin's mapping of a circuit's facts to CS-Cart product features
 * (Feature Mappings › Circuits): the feature group, one feature per fact,
 * and for the facts with a closed list (transport, month) the feature
 * value each one is linked to. Kept as one JSON document in storage_data,
 * like the SEO templates: no table, nothing to migrate.
 */
final class CircuitFeatureConfig
{
    public const string STORAGE_KEY = 'travel_core_circuit_features';

    /** The facts that can be mapped, in the order the page lists them. */
    public const array FIELDS = ['transport', 'duration', 'countries', 'month', 'departure_city', 'tags'];

    /** The transport codes a provider sends. */
    public const array TRANSPORTS = ['flight', 'bus'];

    /**
     * The saved mapping; every key present, zero meaning "not mapped".
     *
     * @return array{group_id: int, features: array<string, int>, values: array{transport: array<string, int>, month: array<int, int>}}
     */
    public static function load(): array
    {
        $raw = function_exists('fn_get_storage_data') ? fn_get_storage_data(self::STORAGE_KEY) : '';
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

        return self::normalize(is_array($decoded) ? $decoded : []);
    }

    /**
     * @param array<array-key, mixed> $config what the page posted (or a decoded document)
     */
    public static function save(array $config): void
    {
        fn_set_storage_data(self::STORAGE_KEY, (string) json_encode(self::normalize($config)));
    }

    /**
     * Any input brought to the stored shape: unknown keys dropped, ids as
     * non-negative integers, months 1–12, the known transports only.
     *
     * @param array<array-key, mixed> $raw
     *
     * @return array{group_id: int, features: array<string, int>, values: array{transport: array<string, int>, month: array<int, int>}}
     */
    public static function normalize(array $raw): array
    {
        $rawFeatures = is_array($raw['features'] ?? null) ? $raw['features'] : [];
        $features = [];
        foreach (self::FIELDS as $field) {
            $features[$field] = max(0, TypeCoerce::toInt($rawFeatures[$field] ?? 0));
        }

        $rawValues = is_array($raw['values'] ?? null) ? $raw['values'] : [];
        $rawTransport = is_array($rawValues['transport'] ?? null) ? $rawValues['transport'] : [];
        $transport = [];
        foreach (self::TRANSPORTS as $code) {
            $transport[$code] = max(0, TypeCoerce::toInt($rawTransport[$code] ?? 0));
        }
        $rawMonth = is_array($rawValues['month'] ?? null) ? $rawValues['month'] : [];
        $month = [];
        for ($m = 1; $m <= 12; $m++) {
            $month[$m] = max(0, TypeCoerce::toInt($rawMonth[$m] ?? $rawMonth[(string) $m] ?? 0));
        }

        return [
            'group_id' => max(0, TypeCoerce::toInt($raw['group_id'] ?? 0)),
            'features' => $features,
            'values' => ['transport' => $transport, 'month' => $month],
        ];
    }

    /**
     * Whether anything is mapped at all (no feature chosen: circuits get no
     * feature, and the provider need not gather the facts).
     *
     * @param array{features: array<string, int>} $config
     */
    public static function isEmpty(array $config): bool
    {
        return array_filter($config['features']) === [];
    }
}
