<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\ViewModels;

use Tygh\Addons\TravelCore\Services\AliasCoverage;

/**
 * View data for the Feature Mappings admin pages (dashboard, per-type list,
 * edit), kept out of the controller god file.
 *
 * The pages are organised around the question an admin actually has: "does
 * each provider's value reach this feature?". FeatureMapper resolves a
 * provider value only through an alias of THAT provider, so every page shows
 * the aliases per provider, and flags a provider that uses aliases but has
 * none where it needs them (see AliasCoverage).
 *
 * Coverage states:
 *  - ok       the provider has aliases here
 *  - missing  the provider maps through aliases but has none here, so its
 *             values never become this feature
 *  - unused   the provider does not map this through aliases
 */
final class FeatureMappingsView
{
    /** Human-readable feature type names (admin UI, English as before). */
    public const array TYPE_LABELS = [
        'hotel_facility' => 'Hotel Facilities',
        'room_facility' => 'Room Facilities',
        'beach_access' => 'Beach Access',
        'board' => 'Board / Meals',
        'resort' => 'Resorts & Cities',
        'stars' => 'Star Rating',
        'property_type' => 'Property Type',
        'travel_group' => 'Travel Group',
        'room_type' => 'Room Type',
        'region' => 'Region',
        'city' => 'City',
    ];

    /** Derived at runtime from facilities (TravelGroupResolver), never aliased. */
    public const string DERIVED_TYPE = 'travel_group';

    /**
     * Every provider worth a column: the registered ones plus any alias
     * source (an add-on that is disabled still has its aliases).
     *
     * @param array<string, string> $registered name => label
     * @param array<string, array<string, int>> $aliasCounts api_source => feature_type => count
     * @return array<string, string> name => label, by name
     */
    public static function providers(array $registered, array $aliasCounts): array
    {
        $providers = [];
        foreach ($registered as $name => $label) {
            if ($name !== '') {
                $providers[$name] = $label !== '' ? $label : ucfirst($name);
            }
        }
        foreach (array_keys($aliasCounts) as $name) {
            $name = (string) $name;
            if ($name !== '' && !isset($providers[$name])) {
                $providers[$name] = ucfirst($name);
            }
        }
        ksort($providers);

        return $providers;
    }

    /**
     * One chip per provider for a feature type. Empty for the derived type.
     *
     * @param array<string, string> $providers
     * @param array<string, array<string, int>> $aliasCounts
     * @return list<array{provider: string, label: string, state: string, count: int}>
     */
    public static function coverage(array $providers, array $aliasCounts, string $featureType): array
    {
        if ($featureType === self::DERIVED_TYPE && self::typeTotal($aliasCounts, $featureType) === 0) {
            return [];
        }

        $chips = [];
        foreach ($providers as $name => $label) {
            $count = $aliasCounts[$name][$featureType] ?? 0;
            $chips[] = [
                'provider' => $name,
                'label' => $label,
                'state' => self::state($aliasCounts[$name] ?? [], $featureType, $count > 0),
                'count' => $count,
            ];
        }

        return $chips;
    }

    /**
     * The providers whose values never reach a feature type (list banner).
     *
     * @param list<array{provider: string, label: string, state: string, count: int}> $coverage
     * @return list<array{provider: string, label: string, state: string, count: int}>
     */
    public static function missing(array $coverage): array
    {
        return array_values(array_filter($coverage, static fn (array $c): bool => $c['state'] === 'missing'));
    }

    /**
     * Alias rows grouped for the list page's "values each provider sends".
     *
     * @param list<array<string, mixed>> $rows {map_id, api_source, api_value}
     * @return array<int, array<string, list<string>>> map_id => provider => values
     */
    public static function groupAliases(array $rows): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $mapId = is_numeric($row['map_id'] ?? null) ? (int) $row['map_id'] : 0;
            $source = is_scalar($row['api_source'] ?? null) ? (string) $row['api_source'] : '';
            $value = is_scalar($row['api_value'] ?? null) ? (string) $row['api_value'] : '';
            if ($mapId > 0 && $source !== '' && $value !== '') {
                $grouped[$mapId][$source][] = $value;
            }
        }

        return $grouped;
    }

    /**
     * The list page's provider filter: "novoton" = has a Novoton alias,
     * "!novoton" = has none (the rows Novoton can never reach).
     *
     * @return array{provider: string, negate: bool}|null
     */
    public static function parseProviderFilter(string $filter): ?array
    {
        $negate = str_starts_with($filter, '!');
        $provider = (string) preg_replace('/[^a-z0-9_]/', '', strtolower($negate ? substr($filter, 1) : $filter));

        return $provider === '' ? null : ['provider' => $provider, 'negate' => $negate];
    }

    /**
     * One card per provider on the edit page: its aliases for this mapping,
     * or why it has none.
     *
     * @param array<string, string> $providers
     * @param array<string, array<string, int>> $aliasCounts
     * @param list<array<string, mixed>> $aliases this mapping's alias rows
     * @return list<array{provider: string, label: string, state: string, aliases: list<array<string, mixed>>}>
     */
    public static function aliasCards(array $providers, array $aliasCounts, string $featureType, array $aliases): array
    {
        $bySource = [];
        foreach ($aliases as $alias) {
            $source = is_scalar($alias['api_source'] ?? null) ? (string) $alias['api_source'] : '';
            if ($source !== '') {
                $bySource[$source][] = $alias;
                $providers[$source] ??= ucfirst($source);
            }
        }

        $cards = [];
        foreach ($providers as $name => $label) {
            $own = $bySource[$name] ?? [];
            $typeCounts = $aliasCounts[$name] ?? [];
            $state = $own !== [] ? 'ok' : self::state($typeCounts, $featureType, false);
            // A provider that maps this type for OTHER codes but not this one
            // is missing here, whatever the type.
            if ($own === [] && ($typeCounts[$featureType] ?? 0) > 0) {
                $state = 'missing';
            }
            $cards[] = ['provider' => $name, 'label' => $label, 'state' => $state, 'aliases' => $own];
        }

        // The providers that need attention first, then the rest.
        $rank = ['missing' => 0, 'ok' => 1, 'unused' => 2];
        usort($cards, static fn (array $a, array $b): int => [$rank[$a['state']] ?? 3, $a['provider']] <=> [$rank[$b['state']] ?? 3, $b['provider']]);

        return $cards;
    }

    /** Language key describing who created a mapping. */
    public static function createdByKey(string $mappingSource): string
    {
        return match ($mappingSource) {
            'auto' => 'travel_core.fm_created_by_auto',
            'manual' => 'travel_core.fm_created_by_manual',
            default => 'travel_core.fm_created_by_seed',
        };
    }

    /**
     * @param array<string, int> $typeCounts the provider's alias counts per type
     */
    private static function state(array $typeCounts, string $featureType, bool $hasAliases): string
    {
        if ($hasAliases) {
            return 'ok';
        }
        $usesAliases = array_sum($typeCounts) > 0;

        return $usesAliases && in_array($featureType, AliasCoverage::CHECKED_TYPES, true) ? 'missing' : 'unused';
    }

    /**
     * @param array<string, array<string, int>> $aliasCounts
     */
    private static function typeTotal(array $aliasCounts, string $featureType): int
    {
        $total = 0;
        foreach ($aliasCounts as $byType) {
            $total += $byType[$featureType] ?? 0;
        }

        return $total;
    }
}
