<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\DestinationPicker;

/**
 * Novoton's Destinations page and dashboard card in Travel Core's shared
 * destination picker (components/destination_picker.tpl): what
 * DestinationsPage::build() found, in the picker's shape.
 *
 * Novoton has two levels, country › resort, and few enough resorts to put
 * every one on the page (no body is loaded on open). Its modes are the
 * whitelist's: Not sold / All resorts / Only selected.
 */
final class DestinationsPicker
{
    /**
     * @param array{configured: bool, countries: list<array<string, mixed>>, totals: array<string, int>, outside: list<array{product_id: int, hotel_name: string, country: string, resort: string}>} $page
     * @param array<string, string> $words fn_travel_core_dest_words() with Novoton's words
     * @return array<string, mixed> for fn_travel_core_dest_page()
     */
    public static function page(array $page, array $words, string $saveUrl, string $outsideUrl): array
    {
        $t = $page['totals'];
        $notices = [];
        if (!$page['configured']) {
            $notices[] = ['kind' => 'info', 'text' => '<strong>' . self::t('novoton_holidays.dest_not_configured_title') . '</strong> ' . self::t('novoton_holidays.dest_not_configured_body'), 'button' => ''];
        }
        if (($t['new'] ?? 0) > 0) {
            $notices[] = ['kind' => 'warning', 'text' => self::t('novoton_holidays.dest_new_alert', ['[n]' => $t['new']]), 'button' => self::t('novoton_holidays.dest_show_new')];
        }
        if (($t['gone'] ?? 0) > 0) {
            $notices[] = ['kind' => 'warning', 'text' => self::t('novoton_holidays.dest_gone_alert', ['[n]' => $t['gone']]), 'button' => ''];
        }

        $countries = [];
        foreach ($page['countries'] as $c) {
            $countries[] = self::country($c);
        }

        $outside = [];
        foreach ($page['outside'] as $p) {
            $outside[] = ['product_id' => $p['product_id'], 'label' => DashboardSummary::displayName($p['resort']) . ', ' . DashboardSummary::displayName($p['country'])];
        }

        return [
            'id' => 'novoton',
            'save_url' => $saveUrl,
            'outside_url' => $page['configured'] ? $outsideUrl : '',
            'words' => $words,
            'modes' => self::modes(),
            'tiles' => [
                ['label' => self::t('novoton_holidays.dest_tile_countries'), 'value' => $t['countries'] ?? 0, 'total' => 'countries', 'note' => '', 'warn' => false],
                ['label' => self::t('novoton_holidays.dest_tile_resorts'), 'value' => $t['resorts'] ?? 0, 'total' => 'items', 'note' => '', 'warn' => false],
                ['label' => self::t('novoton_holidays.dest_tile_hotels'), 'value' => $t['hotels'] ?? 0, 'total' => 'hotels',
                    'note' => self::t('novoton_holidays.dest_tile_hotels_note', ['[n]' => $t['priced'] ?? 0]), 'warn' => false],
                ['label' => self::t('novoton_holidays.dest_tile_new'), 'value' => $t['new'] ?? 0, 'total' => '', 'note' => '', 'warn' => ($t['new'] ?? 0) > 0],
            ],
            'notices' => $notices,
            'intro' => self::t('novoton_holidays.dest_intro'),
            'countries' => $countries,
            'summary' => [
                'rows' => [
                    ['label' => self::t('novoton_holidays.dest_tile_countries'), 'value' => $t['countries'] ?? 0, 'total' => 'countries'],
                    ['label' => self::t('novoton_holidays.dest_tile_resorts'), 'value' => $t['resorts'] ?? 0, 'total' => 'items'],
                    ['label' => self::t('novoton_holidays.dest_tile_hotels'), 'value' => $t['hotels'] ?? 0, 'total' => 'hotels'],
                ],
                'note' => '',
            ],
            'outside' => DestinationPicker::outside($outside),
            'leaf_limit' => 0,
            'per_page' => 0,
        ];
    }

    /**
     * The dashboard's Destinations card: one row per sold country.
     *
     * @param array{configured: bool, countries: list<array<string, mixed>>, totals: array<string, int>} $page
     * @return array<string, mixed>
     */
    public static function card(array $page, string $editUrl): array
    {
        $rows = [];
        $off = 0;
        foreach ($page['countries'] as $c) {
            $mode = self::str($c['mode'] ?? '');
            if ($mode === DestinationScope::MODE_OFF) {
                $off++;
                continue;
            }
            $count = self::int($c['resort_count'] ?? 0);
            $hotels = self::int($c['hotels_sold'] ?? 0);
            $priced = self::int($c['priced_sold'] ?? 0);
            $all = $mode === DestinationScope::MODE_ALL;
            $rows[] = [
                'label' => self::str($c['label'] ?? ''),
                'badge' => $all ? self::t('novoton_holidays.dest_badge_all') : self::t('novoton_holidays.dest_badge_some', ['[sold]' => self::int($c['sold'] ?? 0), '[total]' => $count]),
                'badge_class' => $all ? 'all' : 'specific',
                'words' => $all ? self::t('novoton_holidays.dash_dest_n_resorts', ['[n]' => $count]) : self::t('novoton_holidays.dash_dest_resorts_word'),
                'cells' => [
                    ['value' => $hotels, 'warn' => false],
                    ['value' => $priced, 'warn' => $hotels > 0 && $priced === 0],
                    ['value' => self::int($c['live_sold'] ?? 0), 'warn' => false],
                ],
                'new' => self::int($c['new'] ?? 0),
            ];
        }

        return [
            'id' => 'novoton-destinations',
            'title' => self::t('novoton_holidays.dest_title'),
            'intro' => $page['configured']
                ? self::t('novoton_holidays.dash_dest_intro', ['[countries]' => $page['totals']['countries'] ?? 0, '[resorts]' => $page['totals']['resorts'] ?? 0])
                : self::t('novoton_holidays.dash_dest_not_configured'),
            'edit_url' => $editUrl,
            'cols' => [
                ['label' => self::t('novoton_holidays.dash_dest_col_hotels')],
                ['label' => self::t('novoton_holidays.dash_col_realtime')],
                ['label' => self::t('novoton_holidays.dash_dest_col_live')],
            ],
            'rows' => $rows,
            'off' => $off,
        ];
    }

    /** @return list<array{value: string, label: string, hint: string, sells: string, badge: string}> */
    public static function modes(): array
    {
        return [
            ['value' => DestinationScope::MODE_OFF, 'label' => self::t('novoton_holidays.dest_mode_off'), 'hint' => self::t('novoton_holidays.dest_hint_off'),
                'sells' => DestinationPicker::SELLS_NONE, 'badge' => self::t('novoton_holidays.dest_badge_off')],
            ['value' => DestinationScope::MODE_ALL, 'label' => self::t('novoton_holidays.dest_mode_all'), 'hint' => self::t('novoton_holidays.dest_hint_all'),
                'sells' => DestinationPicker::SELLS_ALL, 'badge' => self::t('novoton_holidays.dest_badge_all')],
            ['value' => DestinationScope::MODE_SPECIFIC, 'label' => self::t('novoton_holidays.dest_mode_specific'), 'hint' => self::t('novoton_holidays.dest_hint_specific'),
                'sells' => DestinationPicker::SELLS_TICKED, 'badge' => self::t('novoton_holidays.dest_badge_some')],
        ];
    }

    /**
     * @param array<string, mixed> $c a DestinationsPage country
     * @return array<string, mixed>
     */
    private static function country(array $c): array
    {
        $label = self::str($c['label'] ?? '');
        $key = self::str($c['country'] ?? '');
        $items = [];
        foreach (is_array($c['resorts'] ?? null) ? $c['resorts'] : [] as $r) {
            if (!is_array($r)) {
                continue;
            }
            $name = self::str($r['name'] ?? '');
            $rLabel = self::str($r['label'] ?? $name);
            $hotels = self::int($r['hotels'] ?? 0);
            $priced = self::int($r['priced'] ?? 0);
            $items[] = [
                'value' => $name,
                'label' => $rLabel,
                'search' => mb_strtolower($rLabel . ' ' . $name),
                'selected' => ($r['selected'] ?? false) === true,
                'new' => ($r['new'] ?? false) === true,
                'gone' => ($r['gone'] ?? false) === true,
                'meta' => self::t('novoton_holidays.dash_n_hotels', [$hotels]) . ' · ' . self::t('novoton_holidays.dest_n_priced', ['[n]' => $priced]),
                'hotels' => $hotels,
                'priced' => $priced,
                'live' => self::int($r['live'] ?? 0),
            ];
        }

        return [
            'key' => $key,
            'label' => $label,
            'search' => mb_strtolower($label . ' ' . $key),
            'mode' => self::str($c['mode'] ?? DestinationScope::MODE_OFF),
            'meta' => self::t('novoton_holidays.dest_country_meta', ['[resorts]' => self::int($c['resort_count'] ?? 0), '[hotels]' => self::int($c['hotels'] ?? 0)]),
            'empty_text' => self::t('novoton_holidays.dest_no_hotels', ['[country]' => $label]),
            'groups' => $items === [] ? [] : [['key' => '', 'items' => $items]],
        ];
    }

    /** @param array<int|string, mixed> $params */
    private static function t(string $key, array $params = []): string
    {
        // Labels with several counts carry {one|many} after each number.
        return DestinationPicker::plurals(TypeCoerce::toString(__($key, $params)));
    }

    private static function str(mixed $v): string
    {
        return is_string($v) ? $v : (is_int($v) ? (string) $v : '');
    }

    private static function int(mixed $v): int
    {
        return is_int($v) ? $v : (is_numeric($v) ? (int) $v : 0);
    }
}
