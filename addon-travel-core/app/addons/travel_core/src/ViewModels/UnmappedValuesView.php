<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\ViewModels;

use Tygh\Addons\TravelCore\Contracts\FeatureMapRepositoryInterface;
use Tygh\Addons\TravelCore\Services\FeatureMapper;

/**
 * View data for the "Unmapped values" admin page: the raw values providers
 * sent that match no alias of theirs, so their hotels get nothing for them.
 *
 * The page's job is to turn each value into an alias. Usually the value
 * means a mapping that already exists (Novoton's "Wi-Fi" = the "wifi"
 * facility), so each row links it to one in a single step. Creating a new
 * mapping and dismissing stay as the other two ways out.
 */
final class UnmappedValuesView
{
    /** Type filter covering every facility type (the facility scan's redirect). */
    public const string ANY_FACILITY = 'facility';

    /**
     * The feature types a type filter stands for; [] = every type.
     *
     * @return list<string>
     */
    public static function typesFor(string $filter): array
    {
        if ($filter === '') {
            return [];
        }

        return $filter === self::ANY_FACILITY ? FeatureMapper::FACILITY_TYPES : [$filter];
    }

    /**
     * The mapping types a raw value may be linked to. Facility values are
     * tracked as hotel_facility whatever they are, and FeatureMapper resolves
     * facilities across all three facility types, so any of them fits.
     *
     * @return list<string>
     */
    public static function linkTargetTypes(string $featureType): array
    {
        return in_array($featureType, FeatureMapper::FACILITY_TYPES, true) ? FeatureMapper::FACILITY_TYPES : [$featureType];
    }

    /**
     * The link picker's options, per mapping type.
     *
     * @param list<array<string, mixed>> $rows {map_id, feature_type, canonical_code, display_name_en}
     * @return array<string, list<array{map_id: int, label: string}>>
     */
    public static function linkOptions(array $rows): array
    {
        $options = [];
        foreach ($rows as $row) {
            $mapId = is_numeric($row['map_id'] ?? null) ? (int) $row['map_id'] : 0;
            $type = self::str($row['feature_type'] ?? '');
            $code = self::str($row['canonical_code'] ?? '');
            $name = self::str($row['display_name_en'] ?? '');
            if ($mapId > 0 && $type !== '') {
                $options[$type][] = ['map_id' => $mapId, 'label' => $name !== '' && $name !== $code ? $name . ' (' . $code . ')' : $code];
            }
        }

        return $options;
    }

    /**
     * What is waiting, per provider and type: the page's summary chips.
     *
     * @param list<array<string, mixed>> $rows {api_source, feature_type, values, hotels}
     * @param array<string, string> $providers name => label
     * @param array<string, string> $typeLabels
     * @return list<array{provider: string, label: string, feature_type: string, type_label: string, values: int, hotels: int}>
     */
    public static function summary(array $rows, array $providers, array $typeLabels): array
    {
        $chips = [];
        foreach ($rows as $row) {
            $provider = self::str($row['api_source'] ?? '');
            $type = self::str($row['feature_type'] ?? '');
            if ($provider === '' || $type === '') {
                continue;
            }
            $chips[] = [
                'provider' => $provider,
                'label' => $providers[$provider] ?? ucfirst($provider),
                'feature_type' => $type,
                'type_label' => $typeLabels[$type] ?? $type,
                'values' => is_numeric($row['values'] ?? null) ? (int) $row['values'] : 0,
                'hotels' => is_numeric($row['hotels'] ?? null) ? (int) $row['hotels'] : 0,
            ];
        }
        usort($chips, static fn (array $a, array $b): int => [$b['values'], $a['provider'], $a['feature_type']] <=> [$a['values'], $b['provider'], $b['feature_type']]);

        return $chips;
    }

    /**
     * The page's filters from the request, sanitised.
     *
     * @param array<array-key, mixed> $request
     * @return array{api_source: string, feature_type: string, q: string, page: int}
     */
    public static function filters(array $request): array
    {
        return [
            'api_source' => (string) preg_replace('/[^a-z0-9_]/', '', strtolower(self::str($request['api_source'] ?? ''))),
            'feature_type' => (string) preg_replace('/[^a-z_]/', '', strtolower(self::str($request['feature_type'] ?? ''))),
            'q' => trim(self::str($request['q'] ?? '')),
            'page' => max(1, is_numeric($request['page'] ?? null) ? (int) $request['page'] : 1),
        ];
    }

    /**
     * Back to the list as it was filtered, after an action.
     *
     * @param array{api_source: string, feature_type: string, q: string, page: int} $filters
     */
    public static function returnUrl(array $filters): string
    {
        $query = array_filter(
            ['api_source' => $filters['api_source'], 'feature_type' => $filters['feature_type'], 'q' => $filters['q'], 'page' => $filters['page'] > 1 ? (string) $filters['page'] : ''],
            static fn (string $v): bool => $v !== '',
        );

        return 'travel_feature_mappings.unmapped' . ($query === [] ? '' : '&' . http_build_query($query));
    }

    /**
     * Everything the page shows.
     *
     * @param array{api_source: string, feature_type: string, q: string, page: int} $filters
     * @param array<string, string> $providers name => label
     * @return array<string, mixed> template variables
     */
    public static function build(FeatureMapRepositoryInterface $repo, array $filters, int $itemsPerPage, array $providers): array
    {
        $repo->purgeResolvedUnmapped(FeatureMapper::CODE_RESOLVED_TYPES);

        $condition = '';
        if ($filters['api_source'] !== '') {
            $condition .= db_quote(' AND api_source = ?s', $filters['api_source']);
        }
        $types = self::typesFor($filters['feature_type']);
        if ($types !== []) {
            $condition .= db_quote(' AND feature_type IN (?a)', $types);
        }
        if ($filters['q'] !== '') {
            $like = '%' . addcslashes($filters['q'], '%_\\') . '%';
            $condition .= db_quote(' AND (api_value LIKE ?l OR api_label LIKE ?l)', $like, $like);
        }

        $page = $filters['page'];
        $result = $repo->getPaginatedUnmapped($condition, ($page - 1) * $itemsPerPage, $itemsPerPage);
        if ($result['items'] === [] && $result['total'] > 0 && $page > 1) {
            $page = 1;
            $result = $repo->getPaginatedUnmapped($condition, 0, $itemsPerPage);
        }

        $items = [];
        $linkTypes = [];
        foreach ($result['items'] as $item) {
            $item['link_types'] = self::linkTargetTypes(self::str($item['feature_type'] ?? ''));
            $linkTypes = array_merge($linkTypes, $item['link_types']);
            $items[] = $item;
        }

        $summaryRows = $repo->getUnmappedSummary();

        return [
            'unmapped_values' => $items,
            'link_options' => self::linkOptions($repo->findMappingsOfTypes(array_values(array_unique($linkTypes)))),
            'unmapped_summary' => self::summary($summaryRows, $providers, FeatureMappingsView::TYPE_LABELS),
            'unmapped_providers' => self::filterProviders($summaryRows, $providers, $filters['api_source']),
            'type_labels' => FeatureMappingsView::TYPE_LABELS,
            'return_url' => self::returnUrl(['page' => $page] + $filters),
            'search' => [
                'api_source' => $filters['api_source'],
                'feature_type' => $filters['feature_type'],
                'q' => $filters['q'],
                'page' => $page,
                'items_per_page' => $itemsPerPage,
                'total_items' => $result['total'],
            ],
        ];
    }

    /**
     * The provider filter's choices: registered providers, providers with
     * waiting values, and the current filter.
     *
     * @param list<array<string, mixed>> $summaryRows
     * @param array<string, string> $providers
     * @return array<string, string>
     */
    private static function filterProviders(array $summaryRows, array $providers, string $current): array
    {
        foreach ($summaryRows as $row) {
            $name = self::str($row['api_source'] ?? '');
            if ($name !== '') {
                $providers[$name] ??= ucfirst($name);
            }
        }
        if ($current !== '') {
            $providers[$current] ??= ucfirst($current);
        }
        ksort($providers);

        return $providers;
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
