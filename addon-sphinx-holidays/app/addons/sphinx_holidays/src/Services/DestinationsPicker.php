<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Services;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\DestinationPicker;

/**
 * Sphinx -> Destination whitelist in Travel Core's shared destination picker
 * (components/destination_picker.tpl): what we sell from Sphinx, per country,
 * and the dashboard's Destinations card.
 *
 * Three levels on the page: country › region (the country's direct children)
 * › city (theirs). Modes and rows (?:sphinx_destination_whitelist):
 *   off       Not sold          no row
 *   all       All destinations  (country, 'all')        new destinations included
 *   specific  Only selected     (country, 'specific') plus
 *                               (region, 'all')         a whole region, new cities included
 *                               (city, 'specific')      one city (its resorts come with it)
 * A region saved 'specific' before whole regions existed reads as whole: it
 * was ticked as a region. ConfigProvider::getAllowedDestinationIds() resolves
 * the rows for every sync.
 *
 * About 200 countries: only the countries in the whitelist (and the one a
 * body is loaded for) have their tree read; the others carry SQL totals. A
 * destination is new when it was first seen after the last Save. "Gone from
 * the feed" is not shown: the destinations sync never removes or flags rows.
 */
final class DestinationsPicker
{
    public const string MODE_OFF = 'off';
    public const string MODE_ALL = 'all';
    public const string MODE_SPECIFIC = 'specific';

    /**
     * The saved whitelist, per country code.
     *
     * @param list<array{destination_id: int, selection_type: string, type: string, country_code: string}> $rows
     * @return array<string, array{mode: string, ids: array<int, string>}> ids: destination_id => selection_type (non-country rows)
     */
    public static function scope(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $cc = strtoupper($row['country_code']);
            if ($cc === '') {
                continue;
            }
            $out[$cc] ??= ['mode' => self::MODE_SPECIFIC, 'ids' => []];
            if ($row['type'] === 'country') {
                if ($row['selection_type'] === 'all') {
                    $out[$cc]['mode'] = self::MODE_ALL;
                }
            } else {
                $out[$cc]['ids'][$row['destination_id']] = $row['selection_type'];
            }
        }

        return $out;
    }

    /**
     * Every country, sold first; the whitelisted ones (and those in $trees)
     * with their regions and cities.
     *
     * @param list<array{destination_id: int, name: string, country_code: string, continent: string}> $countries
     * @param array<string, array{groups: int, items: int}> $structure
     * @param array<string, array{hotels: int, live: int}> $hotelTotals
     * @param list<array{destination_id: int, parent_id: int, name: string, type: string, country_code: string, first_seen_at: string}> $tree
     * @param array<int, array{hotels: int, live: int}> $hotelsByDest
     * @param list<array{circuit_id: int, name: string, product_id: int, live: bool, destination_ids: list<int>}> $circuits
     * @param list<array{destination_id: int, selection_type: string, type: string, country_code: string}> $whitelist
     * @return array{configured: bool, countries: list<array<string, mixed>>, totals: array<string, int>}
     */
    public static function build(array $countries, array $structure, array $hotelTotals, array $tree, array $hotelsByDest, array $circuits, array $whitelist, string $savedAt): array
    {
        $scope = self::scope($whitelist);
        $savedTs = $savedAt === '' ? 0 : (int) strtotime($savedAt);

        $byCountry = [];
        $parentOf = [];
        foreach ($tree as $node) {
            $byCountry[$node['country_code']][] = $node;
            $parentOf[$node['destination_id']] = $node['parent_id'];
        }
        $countryIdOf = [];
        foreach ($countries as $c) {
            $countryIdOf[$c['country_code']] = $c['destination_id'];
        }

        // Each destination's place on the page: the region (level 1) and the
        // city (level 2) it rolls up to. Hotels and circuits deeper down
        // (resorts under a city) count for their city.
        $levels = [];
        foreach ($byCountry as $cc => $nodes) {
            $cid = $countryIdOf[$cc] ?? 0;
            foreach ($nodes as $node) {
                $path = [];
                $id = $node['destination_id'];
                for ($i = 0; $i < 8 && $id > 0 && $id !== $cid; $i++) {
                    array_unshift($path, $id);
                    $id = $parentOf[$id] ?? 0;
                }
                if ($id === $cid && $path !== []) {
                    $levels[$node['destination_id']] = [$path[0], $path[1] ?? 0];
                }
            }
        }
        $circuitsOf = [];
        foreach ($circuits as $circuit) {
            $cities = [];
            foreach ($circuit['destination_ids'] as $destId) {
                $city = $levels[$destId][1] ?? 0;
                if ($city > 0) {
                    $cities[$city] = true;
                }
            }
            foreach (array_keys($cities) as $city) {
                $circuitsOf[$city] = ($circuitsOf[$city] ?? 0) + 1;
            }
        }
        $figures = [];
        foreach ($hotelsByDest as $destId => $h) {
            [$region, $city] = $levels[$destId] ?? [0, 0];
            $at = $city > 0 ? $city : $region;
            if ($at > 0) {
                $figures[$at]['hotels'] = ($figures[$at]['hotels'] ?? 0) + $h['hotels'];
                $figures[$at]['live'] = ($figures[$at]['live'] ?? 0) + $h['live'];
            }
        }

        $totals = ['countries' => 0, 'cities' => 0, 'regions' => 0, 'hotels' => 0, 'live' => 0, 'new' => 0];
        $list = [];
        foreach ($countries as $c) {
            $cc = $c['country_code'];
            $mode = isset($scope[$cc]) ? $scope[$cc]['mode'] : self::MODE_OFF;
            $ids = $scope[$cc]['ids'] ?? [];
            $country = [
                'key' => $cc,
                'label' => $c['name'] !== '' ? $c['name'] : $cc,
                'code' => $cc,
                'search' => mb_strtolower($c['name'] . ' ' . $cc),
                'facet' => $c['continent'],
                'mode' => $mode,
                'empty_text' => self::t('sphinx_holidays.dest_no_destinations', ['[country]' => $c['name'] !== '' ? $c['name'] : $cc]),
            ];

            if (!isset($byCountry[$cc])) {
                // Not whitelisted and not opened: SQL totals only.
                $s = $structure[$cc] ?? ['groups' => 0, 'items' => 0];
                $h = $hotelTotals[$cc] ?? ['hotels' => 0, 'live' => 0];
                $zero = ['sold' => 0, 'hotels' => 0, 'priced' => 0, 'instant' => 0, 'live' => 0, 'groups_sold' => 0];
                $list[] = $country + [
                    'lazy' => true,
                    'new' => 0,
                    'empty' => $s['groups'] === 0,
                    'meta' => self::meta($s['groups'], $s['items'], $h['hotels']),
                    'stats' => [
                        'total' => $s['items'], 'groups' => $s['groups'], 'saved' => $zero,
                        'all' => ['sold' => $s['items'], 'hotels' => $h['hotels'], 'priced' => 0, 'instant' => 0, 'live' => $h['live'], 'groups_sold' => $s['groups']],
                    ],
                    'sort_hotels' => $h['hotels'],
                ];
                continue;
            }

            $cid = $countryIdOf[$cc] ?? 0;
            $regions = [];
            $citiesOf = [];
            foreach ($byCountry[$cc] as $node) {
                if ($node['parent_id'] === $cid) {
                    $regions[] = $node;
                } elseif (($levels[$node['destination_id']][1] ?? 0) === $node['destination_id']) {
                    $citiesOf[$node['parent_id']][] = $node;
                }
            }
            $byName = static fn (array $a, array $b): int => strcasecmp(TypeCoerce::toString($a['name'] ?? ''), TypeCoerce::toString($b['name'] ?? ''));
            usort($regions, $byName);
            $groups = [];
            $cityCount = 0;
            $countryHotels = 0;
            foreach ($regions as $r) {
                $rid = $r['destination_id'];
                $whole = isset($ids[$rid]);
                $items = [];
                $regionHotels = $figures[$rid]['hotels'] ?? 0;
                $cities = $citiesOf[$rid] ?? [];
                usort($cities, $byName);
                foreach ($cities as $city) {
                    $id = $city['destination_id'];
                    $selected = $whole || isset($ids[$id]);
                    $sold = $mode === self::MODE_ALL || ($mode === self::MODE_SPECIFIC && $selected);
                    $hotels = $figures[$id]['hotels'] ?? 0;
                    $isNew = $mode === self::MODE_SPECIFIC && !$sold && $savedTs > 0
                        && $city['first_seen_at'] !== '' && (int) strtotime($city['first_seen_at']) > $savedTs;
                    $items[] = self::item($city, $selected, $isNew, $hotels, $figures[$id]['live'] ?? 0, $circuitsOf[$id] ?? 0, $sold || $hotels > 0);
                    $regionHotels += $hotels;
                    $totals['new'] += $isNew ? 1 : 0;
                }
                $cityCount += count($items);
                $countryHotels += $regionHotels;
                $groups[] = [
                    'key' => (string) $rid,
                    'label' => $r['name'],
                    'whole' => $whole && $mode === self::MODE_SPECIFIC,
                    'open' => $mode === self::MODE_SPECIFIC && array_filter($items, static fn (array $i): bool => $i['selected'] === true || $i['new'] === true) !== [],
                    'meta' => self::t('sphinx_holidays.dest_region_meta', ['[cities]' => count($items), '[hotels]' => $regionHotels]),
                    'items' => $items,
                ];
            }

            $rule = $mode === self::MODE_ALL ? DestinationPicker::SELLS_ALL : ($mode === self::MODE_SPECIFIC ? DestinationPicker::SELLS_TICKED : DestinationPicker::SELLS_NONE);
            $sold = DestinationPicker::stats($groups, $rule);
            if ($mode !== self::MODE_OFF) {
                $totals['countries']++;
                $totals['cities'] += $sold['sold'];
                $totals['regions'] += $sold['groups_sold'];
                $totals['hotels'] += $sold['hotels'];
                $totals['live'] += $sold['live'];
            }
            $list[] = $country + [
                'meta' => self::meta(count($groups), $cityCount, $countryHotels),
                'groups' => $groups,
                'sort_hotels' => $countryHotels,
            ];
        }

        // Sold countries first, then the biggest, then A–Z.
        usort($list, static fn (array $a, array $b): int => [(int) ($a['mode'] === self::MODE_OFF), -$a['sort_hotels'], $a['label']]
            <=> [(int) ($b['mode'] === self::MODE_OFF), -$b['sort_hotels'], $b['label']]);

        return ['configured' => $scope !== [], 'countries' => $list, 'totals' => $totals];
    }

    /**
     * Live products the saved whitelist no longer covers — hotels, and
     * circuits none of whose destinations is allowed — and the circuits in
     * scope.
     *
     * @param list<array{product_id: int, destination_id: int, place: string}> $liveHotels
     * @param list<array{circuit_id: int, name: string, product_id: int, live: bool, destination_ids: list<int>}> $circuits
     * @param list<int> $allowed ConfigProvider::getAllowedDestinationIds()
     * @return array{outside: list<array{product_id: int, label: string}>, circuits_in_scope: int}
     */
    public static function outside(array $liveHotels, array $circuits, array $allowed): array
    {
        $ok = array_fill_keys($allowed, true);
        $outside = [];
        if ($allowed !== []) {
            foreach ($liveHotels as $h) {
                if (!isset($ok[$h['destination_id']])) {
                    $outside[] = ['product_id' => $h['product_id'], 'label' => $h['place']];
                }
            }
        }
        $inScope = 0;
        foreach ($circuits as $circuit) {
            $hit = array_filter($circuit['destination_ids'], static fn (int $id): bool => isset($ok[$id])) !== [];
            $inScope += $hit ? 1 : 0;
            if ($allowed !== [] && !$hit && $circuit['live'] && $circuit['destination_ids'] !== []) {
                $outside[] = ['product_id' => $circuit['product_id'], 'label' => self::t('sphinx_holidays.dest_circuit_label', ['[name]' => $circuit['name']])];
            }
        }

        return ['outside' => $outside, 'circuits_in_scope' => $inScope];
    }

    /**
     * The page data for fn_travel_core_dest_page().
     *
     * @param array{configured: bool, countries: list<array<string, mixed>>, totals: array<string, int>} $page build()
     * @param array{outside: list<array{product_id: int, label: string}>, circuits_in_scope: int} $outside
     * @param array<string, string> $words fn_travel_core_dest_words() with Sphinx's words
     * @param array{save: string, outside: string, body: string, search: string} $urls
     * @return array<string, mixed>
     */
    public static function page(array $page, array $outside, int $routes, array $words, array $urls): array
    {
        $t = $page['totals'];
        $notices = [];
        if (!$page['configured']) {
            $notices[] = ['kind' => 'warning', 'text' => self::t('sphinx_holidays.dest_not_configured'), 'button' => ''];
        }
        if ($t['new'] > 0) {
            $notices[] = ['kind' => 'warning', 'text' => self::t('sphinx_holidays.dest_new_alert', ['[n]' => $t['new']]), 'button' => self::t('sphinx_holidays.dest_show_new')];
        }
        $facets = [];
        foreach ($page['countries'] as $c) {
            $f = TypeCoerce::toString($c['facet'] ?? '');
            if ($f !== '') {
                $facets[$f] = ['value' => $f, 'label' => $f];
            }
        }
        ksort($facets);

        return [
            'id' => 'sphinx',
            'save_url' => $urls['save'],
            'outside_url' => $page['configured'] ? $urls['outside'] : '',
            'body_url' => $urls['body'],
            'search_url' => $urls['search'],
            'lazy_bodies' => true,
            'per_page' => 50,
            'leaf_limit' => 0,
            'words' => $words,
            'modes' => self::modes(),
            'tiles' => [
                ['label' => self::t('sphinx_holidays.dest_tile_countries'), 'value' => $t['countries'], 'total' => 'countries', 'note' => '', 'warn' => false],
                ['label' => self::t('sphinx_holidays.dest_tile_cities'), 'value' => $t['cities'], 'total' => 'items',
                    'note' => self::t('sphinx_holidays.dest_tile_cities_note', ['[n]' => $t['regions']]), 'warn' => false],
                ['label' => self::t('sphinx_holidays.dest_tile_hotels'), 'value' => $t['hotels'], 'total' => 'hotels',
                    'note' => self::t('sphinx_holidays.dest_tile_hotels_note', ['[n]' => $t['live']]), 'warn' => false],
                ['label' => self::t('sphinx_holidays.dest_tile_circuits'), 'value' => $outside['circuits_in_scope'], 'total' => '',
                    'note' => self::t('sphinx_holidays.dest_tile_circuits_note', ['[n]' => $routes]), 'warn' => false],
                ['label' => self::t('sphinx_holidays.dest_tile_new'), 'value' => $t['new'], 'total' => '', 'note' => '', 'warn' => $t['new'] > 0],
            ],
            'notices' => $notices,
            'intro' => self::t('sphinx_holidays.dest_intro'),
            'facet' => $facets === [] ? [] : ['label' => self::t('sphinx_holidays.dest_all_continents'), 'options' => array_values($facets)],
            'countries' => $page['countries'],
            'summary' => [
                'rows' => [
                    ['label' => self::t('sphinx_holidays.dest_tile_countries'), 'value' => $t['countries'], 'total' => 'countries'],
                    ['label' => self::t('sphinx_holidays.dest_regions_sold'), 'value' => $t['regions'], 'total' => 'groups'],
                    ['label' => self::t('sphinx_holidays.dest_tile_cities'), 'value' => $t['cities'], 'total' => 'items'],
                    ['label' => self::t('sphinx_holidays.dest_tile_hotels'), 'value' => $t['hotels'], 'total' => 'hotels'],
                ],
                'note' => self::t('sphinx_holidays.dest_feature_note'),
            ],
            'outside' => DestinationPicker::outside($outside['outside']),
        ];
    }

    /** @return list<array{value: string, label: string, hint: string, sells: string, badge: string}> */
    public static function modes(): array
    {
        return [
            ['value' => self::MODE_OFF, 'label' => self::t('sphinx_holidays.dest_mode_off'), 'hint' => self::t('sphinx_holidays.dest_hint_off'),
                'sells' => DestinationPicker::SELLS_NONE, 'badge' => self::t('sphinx_holidays.dest_badge_off')],
            ['value' => self::MODE_ALL, 'label' => self::t('sphinx_holidays.dest_mode_all'), 'hint' => self::t('sphinx_holidays.dest_hint_all'),
                'sells' => DestinationPicker::SELLS_ALL, 'badge' => self::t('sphinx_holidays.dest_badge_all')],
            ['value' => self::MODE_SPECIFIC, 'label' => self::t('sphinx_holidays.dest_mode_specific'), 'hint' => self::t('sphinx_holidays.dest_hint_specific'),
                'sells' => DestinationPicker::SELLS_TICKED, 'badge' => self::t('sphinx_holidays.dest_badge_some')],
        ];
    }

    /**
     * The posted picker as whitelist rows. A region or city must be one the
     * country's tree has; a city inside a whole region needs no row of its
     * own. A country whose body never loaded keeps its saved rows.
     *
     * @param array<string, array{mode: string, items: list<string>, groups: list<string>, loaded: bool}> $picked DestinationPicker::readPost()
     * @param array<string, int> $countryIds country code => its destination id
     * @param array<string, array{regions: array<int, true>, cities: array<int, int>}> $trees loaded countries: region ids; city id => its region id
     * @param array<string, array{mode: string, ids: array<int, string>}> $saved scope()
     * @return list<array{destination_id: int, selection_type: string}>
     */
    public static function rows(array $picked, array $countryIds, array $trees, array $saved): array
    {
        $rows = [];
        foreach ($picked as $country => $entry) {
            $cc = strtoupper(trim((string) $country));
            $cid = $countryIds[$cc] ?? 0;
            $mode = $entry['mode'];
            if ($cid <= 0 || !in_array($mode, [self::MODE_ALL, self::MODE_SPECIFIC], true)) {
                continue;
            }
            $rows[] = ['destination_id' => $cid, 'selection_type' => $mode];
            if ($mode === self::MODE_ALL) {
                continue;
            }
            if (!$entry['loaded'] || !isset($trees[$cc])) {
                if (($saved[$cc]['mode'] ?? '') === self::MODE_SPECIFIC) {
                    foreach ($saved[$cc]['ids'] as $id => $type) {
                        $rows[] = ['destination_id' => $id, 'selection_type' => $type === 'all' ? 'all' : 'specific'];
                    }
                }
                continue;
            }
            $whole = [];
            foreach ($entry['groups'] as $g) {
                $id = (int) $g;
                if (isset($trees[$cc]['regions'][$id]) && !isset($whole[$id])) {
                    $whole[$id] = true;
                    $rows[] = ['destination_id' => $id, 'selection_type' => 'all'];
                }
            }
            $seen = [];
            foreach ($entry['items'] as $i) {
                $id = (int) $i;
                $region = $trees[$cc]['cities'][$id] ?? 0;
                if ($region > 0 && !isset($whole[$region]) && !isset($seen[$id])) {
                    $seen[$id] = true;
                    $rows[] = ['destination_id' => $id, 'selection_type' => 'specific'];
                }
            }
        }

        return $rows;
    }

    /**
     * The regions and cities of one built country, for rows().
     *
     * @param array<string, mixed> $country a build() country with groups
     * @return array{regions: array<int, true>, cities: array<int, int>}
     */
    public static function treeOf(array $country): array
    {
        $out = ['regions' => [], 'cities' => []];
        foreach (is_array($country['groups'] ?? null) ? $country['groups'] : [] as $g) {
            if (!is_array($g)) {
                continue;
            }
            $rid = TypeCoerce::toInt($g['key'] ?? 0);
            $out['regions'][$rid] = true;
            foreach (is_array($g['items'] ?? null) ? $g['items'] : [] as $i) {
                if (is_array($i)) {
                    $out['cities'][TypeCoerce::toInt($i['value'] ?? 0)] = $rid;
                }
            }
        }

        return $out;
    }

    /**
     * The dashboard's Destinations card.
     *
     * @param array{configured: bool, countries: list<array<string, mixed>>, totals: array<string, int>} $page build()
     * @param array{outside: list<array{product_id: int, label: string}>, circuits_in_scope: int} $outside
     * @return array<string, mixed>
     */
    public static function card(array $page, array $outside, string $editUrl): array
    {
        $rows = [];
        $off = 0;
        $alerts = [];
        $modes = DestinationPicker::modes(self::modes());
        foreach ($page['countries'] as $c) {
            if (($c['mode'] ?? '') === self::MODE_OFF) {
                $off++;
                continue;
            }
            $finished = DestinationPicker::finish($c, $modes);
            $attrs = is_array($finished['attrs'] ?? null) ? $finished['attrs'] : [];
            $new = TypeCoerce::toInt($finished['new'] ?? 0);
            if ($new > 0) {
                $alerts[] = self::t('sphinx_holidays.dest_alert_new', ['[n]' => $new, '[country]' => TypeCoerce::toString($c['label'] ?? '')]);
            }
            $circuits = 0;
            foreach (is_array($finished['groups'] ?? null) ? $finished['groups'] : [] as $g) {
                if (!is_array($g)) {
                    continue;
                }
                $whole = ($g['whole'] ?? false) === true;
                foreach (is_array($g['items'] ?? null) ? $g['items'] : [] as $i) {
                    if (is_array($i) && DestinationPicker::sold(TypeCoerce::toString($finished['rule'] ?? ''), $i, $whole)) {
                        $circuits += TypeCoerce::toInt($i['circuits'] ?? 0);
                    }
                }
            }
            $rows[] = [
                'label' => TypeCoerce::toString($c['label'] ?? ''),
                'badge' => TypeCoerce::toString($finished['badge'] ?? ''),
                'badge_class' => TypeCoerce::toString($finished['badge_class'] ?? ''),
                'words' => self::t('sphinx_holidays.dest_card_cities', ['[n]' => TypeCoerce::toInt($attrs['saved-sold'] ?? 0)]),
                'cells' => [
                    ['value' => TypeCoerce::toInt($attrs['saved-hotels'] ?? 0), 'warn' => false],
                    ['value' => $circuits, 'warn' => false],
                    ['value' => TypeCoerce::toInt($attrs['saved-live'] ?? 0), 'warn' => false],
                ],
                'new' => $new,
            ];
        }
        if ($outside['outside'] !== []) {
            $alerts[] = self::t('sphinx_holidays.dest_alert_outside', ['[n]' => count($outside['outside'])]);
        }

        return [
            'id' => 'sphinx-destinations',
            'title' => self::t('sphinx_holidays.dest_card_title'),
            'intro' => $page['configured']
                ? self::t('sphinx_holidays.dest_card_intro', ['[countries]' => $page['totals']['countries'], '[cities]' => $page['totals']['cities']])
                : self::t('sphinx_holidays.no_sync_targets'),
            'edit_url' => $editUrl,
            'cols' => [
                ['label' => self::t('sphinx_holidays.dest_tile_hotels')],
                ['label' => self::t('sphinx_holidays.dest_col_circuits')],
                ['label' => self::t('sphinx_holidays.dest_col_live')],
            ],
            'rows' => $rows,
            'off' => $off,
            'alerts' => $alerts,
        ];
    }

    /**
     * @param array{destination_id: int, name: string, first_seen_at: string} $city
     * @return array<string, mixed>
     */
    private static function item(array $city, bool $selected, bool $new, int $hotels, int $live, int $circuits, bool $synced): array
    {
        $circ = $circuits > 0 ? ' · ' . self::t('sphinx_holidays.dest_n_circuits', [$circuits]) : '';

        return [
            'value' => (string) $city['destination_id'],
            'label' => $city['name'],
            'search' => mb_strtolower($city['name']),
            'selected' => $selected,
            'new' => $new,
            'meta' => ($synced ? self::t('sphinx_holidays.dest_city_meta', ['[hotels]' => $hotels]) : self::t('sphinx_holidays.dest_city_not_synced')) . $circ,
            'meta_muted' => !$synced,
            'hotels' => $hotels,
            'live' => $live,
            'circuits' => $circuits,
        ];
    }

    private static function meta(int $regions, int $cities, int $hotels): string
    {
        if ($regions === 0) {
            return '';
        }

        return $hotels > 0
            ? self::t('sphinx_holidays.dest_country_meta', ['[regions]' => $regions, '[cities]' => $cities, '[hotels]' => $hotels])
            : self::t('sphinx_holidays.dest_country_meta_none', ['[regions]' => $regions, '[cities]' => $cities]);
    }

    /** @param array<int|string, mixed> $params */
    private static function t(string $key, array $params = []): string
    {
        return TypeCoerce::toString(__($key, $params));
    }
}
