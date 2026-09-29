<?php

declare(strict_types=1);
/***************************************************************************
 *                                                                          *
 *   (c) 2024-2026 VacanteLitoral.ro                                       *
 *                                                                          *
 *   Location: app/addons/travel_core/functions/destinations.php           *
 *                                                                          *
 ***************************************************************************/

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\DestinationPicker;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

/**
 * The destination picker's words (components/destination_picker.tpl +
 * destination-picker.js), Travel Core's with the add-on's on top: an add-on
 * names its items ("resorts", "cities") and may reword any line.
 *
 * @param array<string, mixed> $overrides values from __(), empty ones ignored
 * @return array<string, string>
 */
function fn_travel_core_dest_words(array $overrides = []): array
{
    $keys = [
        'search', 'filter', 'item_type', 'group_type', 'country_type', 'gone', 'pending', 'no_pending', 'badge_all',
        'group_whole', 'group_some', 'group_none', 'sold', 'not_sold', 'no_match', 'more', 'showing', 'visible',
        'fold', 'loading', 'load_failed',
    ];
    $words = [];
    foreach ($keys as $key) {
        $words[$key] = TypeCoerce::toString(__('travel_core.dest_w_' . $key));
    }

    foreach ($overrides as $key => $value) {
        $value = TypeCoerce::toString($value);
        if ($value !== '') {
            $words[$key] = $value;
        }
    }

    return $words;
}

/**
 * Finish the page data the add-on built: every country through
 * DestinationPicker::finish(), and the words as the JSON the script reads.
 *
 * @param array<string, mixed> $dest
 * @return array<string, mixed>
 */
function fn_travel_core_dest_page(array $dest): array
{
    $modes = DestinationPicker::modes($dest['modes'] ?? []);
    // lazy_bodies: every country's figures come from its items, then its
    // body is left out; the script loads it from body_url when it opens.
    $lazy = ($dest['lazy_bodies'] ?? false) === true;
    $countries = [];
    foreach (is_array($dest['countries'] ?? null) ? $dest['countries'] : [] as $country) {
        if (is_array($country)) {
            $finished = DestinationPicker::finish($country, $modes);
            if ($lazy) {
                $finished['lazy'] = true;
                $finished['groups'] = [];
            }
            $countries[] = $finished;
        }
    }
    $dest['modes'] = $modes;
    $dest['countries'] = $countries;
    $dest += [
        'body_url' => '', 'search_url' => '', 'outside_url' => '', 'per_page' => 0, 'leaf_limit' => 0,
        'tiles' => [], 'notices' => [], 'intro' => '', 'filters' => [], 'facet' => [], 'chips' => [], 'sorts' => [], 'bulk' => [],
        'summary' => ['rows' => [], 'note' => ''], 'outside' => ['n' => 0, 'ids' => '', 'groups' => []],
    ];
    $words = is_array($dest['words'] ?? null) ? $dest['words'] : [];
    $dest['words_json'] = (string) json_encode($words, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);

    return $dest;
}
