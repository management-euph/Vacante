<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Services;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\DestinationPicker;

/**
 * Eurosite -> Destination whitelist in Travel Core's shared destination
 * picker (components/destination_picker.tpl): what we sell from Eurosite, per
 * country, and the dashboard's Destinations card.
 *
 * Two levels, country › city, and four modes:
 *   off       Not sold        no row
 *   all       All cities      (CC, '', 'all')       new cities included
 *   own       Own cities      (CC, '', 'own')       every own-offer city, new ones included
 *   specific  Only selected   (CC, '', 'specific') + (CC, CITY, 'specific') per city
 * A country saved before the page existed (city rows, no country row) reads
 * as "Only selected".
 *
 * The catalog holds ~21,700 cities, so a country's cities load when it opens
 * (eurosite.whitelist_body); the page carries each country's figures.
 * Hotels are fetched only for whitelisted cities: a city that was never sold
 * says "Hotels not synced yet", not "0 hotels". A city is new when it was
 * first seen after the last Save; gone when its last cities-sync stamp is
 * two days behind its country's latest.
 */
final class DestinationsPicker
{
    public const string MODE_OFF = 'off';
    public const string MODE_ALL = 'all';
    public const string MODE_OWN = 'own';
    public const string MODE_SPECIFIC = 'specific';

    private const int GONE_AFTER_SECONDS = 2 * 86400;

    /**
     * The saved whitelist, per country: its mode and ticked cities.
     *
     * @param list<array<string, mixed>> $rows ?:eurosite_destination_whitelist
     * @return array<string, array{mode: string, cities: array<string, true>}>
     */
    public static function scope(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $cc = strtoupper(trim(TypeCoerce::toString($row['country_code'] ?? '')));
            if ($cc === '') {
                continue;
            }
            $city = strtoupper(trim(TypeCoerce::toString($row['city_code'] ?? '')));
            $type = TypeCoerce::toString($row['selection_type'] ?? '');
            $out[$cc] ??= ['mode' => self::MODE_SPECIFIC, 'cities' => []];
            if ($city !== '') {
                $out[$cc]['cities'][$city] = true;
            } elseif (in_array($type, [self::MODE_ALL, self::MODE_OWN, self::MODE_SPECIFIC], true)) {
                $out[$cc]['mode'] = $type;
            }
        }

        return $out;
    }

    /**
     * Whether a city is sold under a saved scope.
     *
     * @param array<string, array{mode: string, cities: array<string, true>}> $scope
     */
    public static function allows(array $scope, string $country, string $city, bool $own): bool
    {
        $c = $scope[strtoupper($country)] ?? null;
        if ($c === null) {
            return false;
        }

        return match ($c['mode']) {
            self::MODE_ALL => true,
            self::MODE_OWN => $own,
            default => isset($c['cities'][strtoupper($city)]),
        };
    }

    /**
     * Every country with its cities, what is sold, and the products outside.
     *
     * @param list<array<string, mixed>> $countries ?:eurosite_countries rows (country_code, name)
     * @param list<array{city_code: string, country_code: string, name: string, is_own: bool, first_seen_at: string, last_synced_at: string, hotels: int, priced: int, instant: int, live: int}> $cities CityRepository::pickerRows()
     * @param list<array<string, mixed>> $whitelist the saved rows
     * @param list<array{product_id: int, hotel_name: string, country_code: string, city_code: string, city_name: string, country_name: string}> $live
     * @return array{configured: bool, countries: list<array<string, mixed>>, totals: array<string, int>, outside: list<array{product_id: int, label: string}>}
     */
    public static function build(array $countries, array $cities, array $whitelist, string $savedAt, array $live): array
    {
        $scope = self::scope($whitelist);
        $savedTs = $savedAt === '' ? 0 : (int) strtotime($savedAt);

        $byCountry = [];
        $latest = [];
        $ownOf = [];
        foreach ($cities as $c) {
            $cc = strtoupper($c['country_code']);
            $byCountry[$cc][] = $c;
            $latest[$cc] = max($latest[$cc] ?? 0, self::ts($c['last_synced_at']));
            $ownOf[$cc . '|' . strtoupper($c['city_code'])] = $c['is_own'];
        }

        $names = [];
        foreach ($countries as $row) {
            $cc = strtoupper(trim(TypeCoerce::toString($row['country_code'] ?? '')));
            if ($cc !== '') {
                $names[$cc] = trim(TypeCoerce::toString($row['name'] ?? '')) ?: $cc;
            }
        }
        foreach (array_merge(array_keys($scope), array_keys($byCountry)) as $cc) {
            $names[(string) $cc] ??= (string) $cc;
        }

        $totals = ['countries' => 0, 'cities' => 0, 'own' => 0, 'hotels' => 0, 'priced' => 0, 'instant' => 0, 'live' => 0, 'new' => 0, 'gone' => 0];
        $list = [];
        foreach ($names as $cc => $name) {
            $cc = (string) $cc;
            $mode = isset($scope[$cc]) ? $scope[$cc]['mode'] : self::MODE_OFF;
            $ticked = $scope[$cc]['cities'] ?? [];
            $items = [];
            $own = 0;
            $hotelsKnown = false;
            $hotels = 0;
            $new = 0;
            $gone = 0;
            $seen = [];
            foreach ($byCountry[$cc] ?? [] as $c) {
                $code = strtoupper($c['city_code']);
                $seen[$code] = true;
                $isGone = $latest[$cc] > 0 && self::ts($c['last_synced_at']) < $latest[$cc] - self::GONE_AFTER_SECONDS;
                $selected = isset($ticked[$code]);
                $sold = !$isGone && self::allows($scope, $cc, $code, $c['is_own']);
                $isNew = !$sold && !$isGone && $savedTs > 0 && in_array($mode, [self::MODE_OWN, self::MODE_SPECIFIC], true)
                    && self::ts($c['first_seen_at']) > $savedTs;
                $items[] = self::item($c['city_code'], $c['name'], $c['is_own'], $selected, $isNew, $isGone, $c, $sold || $c['hotels'] > 0);
                $own += $c['is_own'] ? 1 : 0;
                $hotelsKnown = $hotelsKnown || $c['hotels'] > 0;
                $hotels += $c['hotels'];
                $new += $isNew ? 1 : 0;
                $gone += $isGone && $selected ? 1 : 0;
                if ($sold) {
                    $totals['cities']++;
                    $totals['own'] += $c['is_own'] ? 1 : 0;
                    $totals['hotels'] += $c['hotels'];
                    $totals['priced'] += $c['priced'];
                    $totals['instant'] += $c['instant'];
                    $totals['live'] += $c['live'];
                }
            }
            // Ticked cities the catalog no longer has: listed (ticked, gone)
            // so the next Save keeps them unless someone unticks them.
            foreach (array_keys($ticked) as $code) {
                if (!isset($seen[(string) $code]) && ($byCountry[$cc] ?? []) !== []) {
                    $items[] = self::item((string) $code, (string) $code, false, true, false, true, [], false);
                    $gone++;
                }
            }
            $totals['new'] += $new;
            $totals['gone'] += $gone;
            if ($mode !== self::MODE_OFF) {
                $totals['countries']++;
            }

            $count = count($byCountry[$cc] ?? []);
            $list[] = [
                'key' => $cc,
                'label' => $name,
                'code' => $cc,
                'search' => mb_strtolower($name . ' ' . $cc),
                'mode' => $mode,
                'flags' => $own > 0 ? ['own' => $own] : [],
                'badges' => $own > 0 ? [['text' => self::t('eurosite.dest_own_n', ['[n]' => $own]), 'class' => 'own', 'title' => self::t('eurosite.dest_own_title')]] : [],
                'meta' => $count === 0 ? '' : ($hotelsKnown
                    ? self::t('eurosite.dest_country_meta', ['[cities]' => $count, '[hotels]' => $hotels])
                    : self::t('eurosite.dest_country_meta_none', ['[cities]' => $count])),
                'empty_text' => self::t('eurosite.dest_no_cities', ['[country]' => $name]),
                'groups' => $items === [] ? [] : [['key' => '', 'items' => self::ordered($items)]],
                'sort_hotels' => $hotels,
            ];
        }

        // Sold countries first, then the biggest catalogs, then A–Z.
        usort($list, static fn (array $a, array $b): int => [(int) ($a['mode'] === self::MODE_OFF), -$a['sort_hotels'], -count($a['groups']), $a['label']]
            <=> [(int) ($b['mode'] === self::MODE_OFF), -$b['sort_hotels'], -count($b['groups']), $b['label']]);

        $outside = [];
        if ($scope !== []) {
            foreach ($live as $p) {
                $cc = strtoupper($p['country_code']);
                $city = strtoupper($p['city_code']);
                if (!self::allows($scope, $cc, $city, $ownOf[$cc . '|' . $city] ?? false)) {
                    $outside[] = [
                        'product_id' => $p['product_id'],
                        'label' => ($p['city_name'] !== '' ? $p['city_name'] : $p['city_code']) . ', ' . ($names[$cc] ?? ($p['country_name'] !== '' ? $p['country_name'] : $cc)),
                    ];
                }
            }
        }
        $totals['outside'] = count($outside);

        return ['configured' => $scope !== [], 'countries' => $list, 'totals' => $totals, 'outside' => $outside];
    }

    /**
     * The page data for fn_travel_core_dest_page().
     *
     * @param array{configured: bool, countries: list<array<string, mixed>>, totals: array<string, int>, outside: list<array{product_id: int, label: string}>} $page build()
     * @param array<string, string> $words fn_travel_core_dest_words() with Eurosite's words
     * @param array{save: string, outside: string, body: string, search: string} $urls
     * @return array<string, mixed>
     */
    public static function page(array $page, array $words, array $urls): array
    {
        $t = $page['totals'];
        $notices = [];
        if (!$page['configured']) {
            $notices[] = ['kind' => 'warning', 'text' => self::t('eurosite.dest_not_configured'), 'button' => ''];
        }
        if ($t['new'] > 0) {
            $notices[] = ['kind' => 'warning', 'text' => self::t('eurosite.dest_new_alert', ['[n]' => $t['new']]), 'button' => self::t('eurosite.dest_show_new')];
        }
        if ($t['gone'] > 0) {
            $notices[] = ['kind' => 'warning', 'text' => self::t('eurosite.dest_gone_alert', ['[n]' => $t['gone']]), 'button' => ''];
        }

        return [
            'id' => 'eurosite',
            'save_url' => $urls['save'],
            'outside_url' => $page['configured'] ? $urls['outside'] : '',
            'body_url' => $urls['body'],
            'search_url' => $urls['search'],
            'lazy_bodies' => true,
            'leaf_limit' => 60,
            'words' => $words,
            'modes' => self::modes(),
            'tiles' => [
                ['label' => self::t('eurosite.dest_tile_countries'), 'value' => $t['countries'], 'total' => 'countries', 'note' => '', 'warn' => false],
                ['label' => self::t('eurosite.dest_tile_cities'), 'value' => $t['cities'], 'total' => 'items',
                    'note' => self::t('eurosite.dest_tile_cities_note', ['[n]' => $t['own']]), 'warn' => false],
                ['label' => self::t('eurosite.dest_tile_hotels'), 'value' => $t['hotels'], 'total' => 'hotels',
                    'note' => self::t('eurosite.dest_tile_hotels_note', ['[priced]' => $t['priced'], '[instant]' => $t['instant']]), 'warn' => false],
                ['label' => self::t('eurosite.dest_tile_new'), 'value' => $t['new'], 'total' => '', 'note' => '', 'warn' => $t['new'] > 0],
            ],
            'notices' => $notices,
            'intro' => self::t('eurosite.dest_intro'),
            'filters' => [['key' => 'own', 'label' => self::t('eurosite.dest_filter_own')]],
            'chips' => [['flag' => 'own', 'label' => self::t('eurosite.dest_chip_own')]],
            'bulk' => [['flag' => 'own', 'label' => self::t('eurosite.dest_select_own')]],
            'sorts' => [
                ['value' => '', 'label' => self::t('eurosite.dest_sort_own')],
                ['value' => 'az', 'label' => self::t('eurosite.dest_sort_az')],
                ['value' => 'hotels', 'label' => self::t('eurosite.dest_sort_hotels')],
            ],
            'countries' => $page['countries'],
            'summary' => [
                'rows' => [
                    ['label' => self::t('eurosite.dest_tile_countries'), 'value' => $t['countries'], 'total' => 'countries'],
                    ['label' => self::t('eurosite.dest_tile_cities'), 'value' => $t['cities'], 'total' => 'items'],
                    ['label' => self::t('eurosite.dest_tile_hotels'), 'value' => $t['hotels'], 'total' => 'hotels'],
                ],
                'note' => '',
            ],
            'outside' => DestinationPicker::outside($page['outside']),
        ];
    }

    /** @return list<array{value: string, label: string, hint: string, sells: string, badge: string, requires: string}> */
    public static function modes(): array
    {
        return [
            ['value' => self::MODE_OFF, 'label' => self::t('eurosite.dest_mode_off'), 'hint' => self::t('eurosite.dest_hint_off'),
                'sells' => DestinationPicker::SELLS_NONE, 'badge' => self::t('eurosite.dest_badge_off'), 'requires' => ''],
            ['value' => self::MODE_ALL, 'label' => self::t('eurosite.dest_mode_all'), 'hint' => self::t('eurosite.dest_hint_all'),
                'sells' => DestinationPicker::SELLS_ALL, 'badge' => self::t('eurosite.dest_badge_all'), 'requires' => ''],
            ['value' => self::MODE_OWN, 'label' => self::t('eurosite.dest_mode_own'), 'hint' => self::t('eurosite.dest_hint_own'),
                'sells' => 'flag:own', 'badge' => self::t('eurosite.dest_badge_own'), 'requires' => 'own'],
            ['value' => self::MODE_SPECIFIC, 'label' => self::t('eurosite.dest_mode_specific'), 'hint' => self::t('eurosite.dest_hint_specific'),
                'sells' => DestinationPicker::SELLS_TICKED, 'badge' => self::t('eurosite.dest_badge_some'), 'requires' => ''],
        ];
    }

    /**
     * The posted picker as whitelist rows. Countries must be known; a city
     * must be one of the country's synced cities (or, for a country with none
     * synced, look like a code: its list came live from the API). A country
     * whose cities never loaded keeps its saved ticks.
     *
     * @param array<string, array{mode: string, items: list<string>, groups: list<string>, loaded: bool}> $picked DestinationPicker::readPost()
     * @param array<string, true> $known country codes
     * @param array<string, array<string, true>> $synced country => its synced city codes
     * @param array<string, array{mode: string, cities: array<string, true>}> $saved scope()
     * @return list<array{country_code: string, city_code: string, selection_type: string}>
     */
    public static function rows(array $picked, array $known, array $synced, array $saved): array
    {
        $rows = [];
        foreach ($picked as $country => $entry) {
            $cc = strtoupper(trim((string) $country));
            $mode = $entry['mode'];
            if (!isset($known[$cc]) || !in_array($mode, [self::MODE_ALL, self::MODE_OWN, self::MODE_SPECIFIC], true)) {
                continue;
            }
            $rows[] = ['country_code' => $cc, 'city_code' => '', 'selection_type' => $mode];
            if ($mode !== self::MODE_SPECIFIC) {
                continue;
            }
            $codes = $entry['loaded']
                ? $entry['items']
                : (($saved[$cc]['mode'] ?? '') === self::MODE_SPECIFIC ? array_map('strval', array_keys($saved[$cc]['cities'])) : []);
            $keep = [];
            foreach ($codes as $code) {
                $code = strtoupper(trim($code));
                $ok = isset($synced[$cc]) && $synced[$cc] !== []
                    ? isset($synced[$cc][$code]) || isset($saved[$cc]['cities'][$code])
                    : preg_match('/^[A-Z0-9_-]{1,16}$/', $code) === 1;
                if ($code !== '' && $ok && !isset($keep[$code])) {
                    $keep[$code] = true;
                    $rows[] = ['country_code' => $cc, 'city_code' => $code, 'selection_type' => self::MODE_SPECIFIC];
                }
            }
        }

        return $rows;
    }

    /**
     * The dashboard's Destinations card, and the lines it flags.
     *
     * @param array{configured: bool, countries: list<array<string, mixed>>, totals: array<string, int>, outside: list<array{product_id: int, label: string}>} $page build()
     * @return array<string, mixed>
     */
    public static function card(array $page, string $editUrl): array
    {
        $rows = [];
        $off = 0;
        $alerts = [];
        foreach ($page['countries'] as $c) {
            $finished = DestinationPicker::finish($c, DestinationPicker::modes(self::modes()));
            $new = TypeCoerce::toInt($finished['new'] ?? 0);
            if ($new > 0) {
                $alerts[] = self::t('eurosite.dest_alert_new', ['[n]' => $new, '[country]' => TypeCoerce::toString($c['label'] ?? '')]);
            }
            if (($c['mode'] ?? '') === self::MODE_OFF) {
                $off++;
                continue;
            }
            $attrs = is_array($finished['attrs'] ?? null) ? $finished['attrs'] : [];
            $total = TypeCoerce::toInt($attrs['total'] ?? 0);
            $rows[] = [
                'label' => TypeCoerce::toString($c['label'] ?? ''),
                'badge' => TypeCoerce::toString($finished['badge'] ?? ''),
                'badge_class' => TypeCoerce::toString($finished['badge_class'] ?? ''),
                'words' => ($c['mode'] ?? '') === self::MODE_ALL ? self::t('eurosite.dest_card_n_cities', ['[n]' => $total]) : self::t('eurosite.dest_card_cities'),
                'cells' => [
                    ['value' => TypeCoerce::toInt($attrs['saved-hotels'] ?? 0), 'warn' => false],
                    ['value' => TypeCoerce::toInt($attrs['saved-instant'] ?? 0), 'warn' => TypeCoerce::toInt($attrs['saved-hotels'] ?? 0) > 0 && TypeCoerce::toInt($attrs['saved-instant'] ?? 0) === 0],
                    ['value' => TypeCoerce::toInt($attrs['saved-live'] ?? 0), 'warn' => false],
                ],
                'new' => $new,
            ];
        }
        if ($page['totals']['gone'] > 0) {
            $alerts[] = self::t('eurosite.dest_alert_gone', ['[n]' => $page['totals']['gone']]);
        }
        if ($page['totals']['outside'] > 0) {
            $alerts[] = self::t('eurosite.dest_alert_outside', ['[n]' => $page['totals']['outside']]);
        }

        return [
            'id' => 'eurosite-destinations',
            'title' => self::t('eurosite.dest_card_title'),
            'intro' => $page['configured']
                ? self::t('eurosite.dest_card_intro', ['[countries]' => $page['totals']['countries'], '[cities]' => $page['totals']['cities']])
                : self::t('eurosite.dest_card_not_configured'),
            'edit_url' => $editUrl,
            'cols' => [
                ['label' => self::t('eurosite.dest_tile_hotels')],
                ['label' => self::t('eurosite.dest_col_instant')],
                ['label' => self::t('eurosite.dest_col_live')],
            ],
            'rows' => $rows,
            'off' => $off,
            'alerts' => $alerts,
        ];
    }

    /**
     * Own-offer cities first, then A–Z (the "Own first" sort, the default).
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private static function ordered(array $items): array
    {
        $own = static fn (array $i): int => is_array($i['flags'] ?? null) && ($i['flags']['own'] ?? false) === true ? 0 : 1;
        usort($items, static fn (array $a, array $b): int => [$own($a), TypeCoerce::toString($a['label'] ?? '')]
            <=> [$own($b), TypeCoerce::toString($b['label'] ?? '')]);

        return $items;
    }

    /**
     * @param array<string, mixed> $row the pickerRows() row ([] for a gone city)
     * @return array<string, mixed>
     */
    private static function item(string $code, string $name, bool $own, bool $selected, bool $new, bool $gone, array $row, bool $synced): array
    {
        $hotels = TypeCoerce::toInt($row['hotels'] ?? 0);
        $priced = TypeCoerce::toInt($row['priced'] ?? 0);
        $instant = TypeCoerce::toInt($row['instant'] ?? 0);
        if ($gone) {
            $meta = self::t('eurosite.dest_city_gone');
        } elseif (!$synced) {
            $meta = self::t('eurosite.dest_city_not_synced');
        } else {
            $meta = self::t('eurosite.dest_city_meta', ['[hotels]' => $hotels, '[priced]' => $priced, '[instant]' => $instant]);
        }
        $label = trim($name) !== '' ? trim($name) : $code;

        return [
            'value' => $code,
            'label' => $label,
            'code' => $code,
            'search' => mb_strtolower($label . ' ' . $code),
            'selected' => $selected,
            'new' => $new,
            'gone' => $gone,
            'flags' => ['own' => $own],
            'badges' => $own ? [['text' => self::t('eurosite.dest_own'), 'class' => 'own']] : [],
            'meta' => $meta,
            'meta_muted' => !$synced && !$gone,
            'hotels' => $hotels,
            'priced' => $priced,
            'instant' => $instant,
            'live' => TypeCoerce::toInt($row['live'] ?? 0),
        ];
    }

    private static function ts(string $datetime): int
    {
        return $datetime === '' ? 0 : (int) strtotime($datetime);
    }

    /** @param array<int|string, mixed> $params */
    private static function t(string $key, array $params = []): string
    {
        return TypeCoerce::toString(__($key, $params));
    }
}
