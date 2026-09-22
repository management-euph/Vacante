<?php
declare(strict_types=1);
/**
 * Novoton Holidays - Main Backend Controller
 *
 * All template-rendering modes live directly in this file so CS-Cart's
 * dispatch can always resolve the template at:
 *   views/novoton_holidays/{mode}.tpl
 *
 * Modes that call exit() (streaming) or return status arrays (redirects)
 * are delegated to sub-controller files via include — those never reach
 * CS-Cart's template resolver so the include mechanism works fine.
 *
 * @package NovotonHolidays
 * @since 2.8.0
 */

use Tygh\Registry;
use Tygh\Tygh;
use Tygh\Addons\NovotonHolidays\Constants;
use Tygh\Addons\NovotonHolidays\NovotonApi;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Addons\NovotonHolidays\Services\Container;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Helpers\RequestCoerce;

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

// ============================================================================
// DELEGATION: Modes that never need CS-Cart template resolution
// (they either call exit() or return a status array).
// These are safe to include from sub-controller files.
// ============================================================================

// Hotels: exit/redirect modes only
$hotels_delegate = [
    'sync_facilities', 'sync_hotel_facilities', 'save_facilities', 'check_packages'
];

// Prices: all modes either exit() or return redirect
$prices_delegate = [
    'update_prices', 'check_prices', 'check_prices_hotel',
    'download_active_prices_csv', 'cron_offers_update'
];

// Tools: exit/redirect modes only
$tools_delegate = [
    'test_api', 'test_formats', 'test_product', 'test_hotel_list', 'test_room_price',
    'test_search', 'test_facilities',
    'export_hotel_features_csv', 'get_hotel_features_csv',
    'cron_export_hotel_features',
    'export_hotel_features_xml', 'download_hotel_features_xml'
];

$include_map = [
    ['modes' => $hotels_delegate, 'file' => __DIR__ . '/novoton_hotels.php'],
    ['modes' => $prices_delegate, 'file' => __DIR__ . '/novoton_prices.php'],
    ['modes' => $tools_delegate,  'file' => __DIR__ . '/novoton_tools.php'],
];

foreach ($include_map as $entry) {
    if (in_array($mode, $entry['modes'], true)) {
        $result = include $entry['file'];
        if (is_array($result)) {
            return $result;
        }
        // These modes should always exit() or return arrays — never reach here.
        // But if they do, fall through to end of file for safety.
        break;
    }
}

// ============================================================================
// TEMPLATE-RENDERING MODES
// These assign Smarty variables and fall through to end of file.
// CS-Cart then renders views/novoton_holidays/{mode}.tpl
// ============================================================================

/** @var \Smarty $view */
$view = Tygh::$app['view'];

// ============================================================================
// POST HANDLERS
// ============================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($mode === 'save_excluded_resorts') {
        if (!fn_check_permissions('manage_catalog', 'update', 'admin')) {
            return [CONTROLLER_STATUS_DENIED];
        }

        $excluded = $_POST['excluded_resorts'] ?? [];

        $clean_excluded = [];
        if (is_array($excluded)) {
            foreach ($excluded as $resort) {
                $resort = trim(TypeCoerce::toString($resort));
                if (!empty($resort)) {
                    $clean_excluded[] = $resort;
                }
            }
        }

        $value = json_encode(array_unique($clean_excluded));
        db_query(
            "UPDATE ?:addon_options SET value = ?s WHERE addon = ?s AND option_id = 'excluded_resorts'",
            $value,
            \Tygh\Addons\NovotonHolidays\Constants::ADDON_ID
        );

        Registry::del('addons.novoton_holidays');

        fn_set_notification('N', __('notice'), 'Excluded resorts saved: ' . count($clean_excluded) . ' resorts');

        return [CONTROLLER_STATUS_REDIRECT, 'novoton_holidays.manage'];
    }
}

/**
 * Mode: fix_tab
 */
if ($mode === 'fix_tab') {
    if (!fn_check_permissions('manage_catalog', 'update', 'admin')) {
        return [CONTROLLER_STATUS_DENIED];
    }

    $result = fn_novoton_holidays_fix_tab_name();

    if ($result) {
        fn_set_notification('N', __('notice'), 'Product tab name fixed successfully');
    } else {
        fn_set_notification('W', __('warning'), 'No tab found to fix');
    }

    return [CONTROLLER_STATUS_REDIRECT, 'addons.update&addon=novoton_holidays'];
}

/**
 * Mode: recompute_calendar_prices
 */
if ($mode === 'recompute_calendar_prices') {
    if (!fn_check_permissions('manage_catalog', 'update', 'admin')) {
        return [CONTROLLER_STATUS_DENIED];
    }

    $hotelRepo = Container::getInstance()->hotelRepository();
    $hotel_ids = $hotelRepo->findIdsWithPriceinfoData();

    $count = 0;
    $errors = 0;
    foreach ($hotel_ids as $hid) {
        try {
            \Tygh\Addons\NovotonHolidays\Services\PriceInfoService::precomputeCalendarPrices((string) $hid);
            $count++;
        } catch (\Throwable $e) {
            $errors++;
        }
    }

    $with_prices = $hotelRepo->countWithCalendarPrices();

    $msg = "Calendar prices recomputed for {$count} / " . count($hotel_ids) . " hotels."
         . " ({$with_prices} hotels now have calendar prices)";
    if ($errors > 0) {
        $msg .= " — {$errors} errors.";
    }
    fn_set_notification('N', __('notice'), $msg);
    return [CONTROLLER_STATUS_REDIRECT, 'novoton_holidays.manage'];
}

// ============================================================================
// TEMPLATE MODES — from novoton_hotels.php
// ============================================================================

/**
 * Mode: list_facilities
 */
if ($mode === 'list_facilities') {
    $facilityRepo = Container::getInstance()->facilityRepository();
    $facilities = $facilityRepo->findAllFull();
    $count = count($facilities);
    $last_sync = $facilityRepo->getLastSyncedAt();

    // Build feature type options with CS-Cart feature names for the dropdown.
    // Show all feature types so admins can classify facilities into any category.
    // Feature IDs are stored under addons.travel_core.feature_id_<type> settings
    // (see travel_core/func.php fn_settings_variants_addons_travel_core_feature_id_*).
    $facility_feature_types = \Tygh\Addons\NovotonHolidays\Constants::VALID_FEATURE_TYPES;
    $feature_type_options = [];
    foreach ($facility_feature_types as $ft) {
        $settingKey = 'addons.travel_core.feature_id_' . $ft;
        $featureId = TypeCoerce::toInt(Registry::get($settingKey));
        $label = ucwords(str_replace('_', ' ', $ft));
        if ($featureId > 0) {
            $featureName = db_get_field(
                "SELECT fd.description FROM ?:product_features_descriptions fd WHERE fd.feature_id = ?i AND fd.lang_code = ?s",
                $featureId, DESCR_SL
            );
            if ($featureName) {
                $label .= ' → ' . TypeCoerce::toString($featureName) . " #{$featureId}";
            } else {
                $label .= " → #{$featureId}";
            }
        } else {
            $label .= ' (not configured)';
        }
        $feature_type_options[$ft] = $label;
    }

    $view->assign('facilities', $facilities);
    $view->assign('facilities_count', $count);
    $view->assign('last_sync', $last_sync);
    $view->assign('feature_type_options', $feature_type_options);
}

// ============================================================================
// TEMPLATE MODES — from novoton_prices.php
// ============================================================================

/**
 * Mode: room_price
 */
if ($mode === 'room_price') {
    if (!fn_check_permissions('manage_catalog', 'update', 'admin')) {
        return [CONTROLLER_STATUS_DENIED];
    }

    $hotel_id = RequestCoerce::string($_REQUEST, 'hotel_id');
    $check_in = RequestCoerce::string($_REQUEST, 'check_in', date('Y-m-d', strtotime('+' . Constants::DEFAULT_CHECKIN_DAYS_AHEAD . ' days')));
    $check_out = RequestCoerce::string($_REQUEST, 'check_out', date('Y-m-d', strtotime('+' . (Constants::DEFAULT_CHECKIN_DAYS_AHEAD + Constants::DEFAULT_STAY_NIGHTS) . ' days')));

    $view->assign('hotel_id', $hotel_id);
    $view->assign('check_in', $check_in);
    $view->assign('check_out', $check_out);

    if (!empty($hotel_id) && !empty($_REQUEST['check'])) {
        try {
            $api = new NovotonApi();

            $params = [
                'hotel_id' => $hotel_id,
                'check_in' => $check_in,
                'check_out' => $check_out,
                'adults' => RequestCoerce::int($_REQUEST, 'adults', 2),
                'children' => RequestCoerce::list($_REQUEST, 'children'),
            ];

            $result = $api->pricing()->getRoomPrice($params);

            $view->assign('result', $result);
            $view->assign('last_request', $api->getLastRequestFormatted());
            $view->assign('last_response', $api->getLastResponse());

        } catch (\Throwable $e) {
            $view->assign('error', $e->getMessage());
        }
    }
}

// ============================================================================
// TEMPLATE MODES — from novoton_tools.php
// ============================================================================

/**
 * Mode: test_hotel_request
 */
if ($mode === 'test_hotel_request') {
    $hotel_id = RequestCoerce::string($_REQUEST, 'hotel_id');

    $view->assign('hotel_id', htmlspecialchars($hotel_id, ENT_QUOTES, 'UTF-8'));
    $view->assign('package_name', htmlspecialchars(RequestCoerce::string($_REQUEST, 'package_name'), ENT_QUOTES, 'UTF-8'));
    $view->assign('check_in', htmlspecialchars(RequestCoerce::string($_REQUEST, 'check_in'), ENT_QUOTES, 'UTF-8'));
    $view->assign('check_out', htmlspecialchars(RequestCoerce::string($_REQUEST, 'check_out'), ENT_QUOTES, 'UTF-8'));
    $view->assign('adults', htmlspecialchars(RequestCoerce::string($_REQUEST, 'adults', '2'), ENT_QUOTES, 'UTF-8'));
    $view->assign('room_id', htmlspecialchars(RequestCoerce::string($_REQUEST, 'room_id'), ENT_QUOTES, 'UTF-8'));
    $view->assign('board_id', htmlspecialchars(RequestCoerce::string($_REQUEST, 'board_id'), ENT_QUOTES, 'UTF-8'));
    $view->assign('holder', htmlspecialchars(RequestCoerce::string($_REQUEST, 'holder'), ENT_QUOTES, 'UTF-8'));

    if (!empty($hotel_id)) {
        try {
            $api = new NovotonApi();

            $hotels = $api->hotels();
            $hotel_info = $hotels->getHotelInfo($hotel_id);
            $last_request = $api->getLastRequestFormatted();
            $last_response = $api->getLastResponse();

            $hotel_desc = $hotels->getHotelDescription($hotel_id, 'UK', true);

            $view->assign('hotel_info', $hotel_info);
            $view->assign('hotel_desc', $hotel_desc);
            $view->assign('last_request', $last_request);
            $view->assign('last_response', $last_response);

        } catch (\Exception $e) {
            $view->assign('error', $e->getMessage());
        }
    }
}

/**
 * Mode: test_alternative_rs
 */
if ($mode === 'test_alternative_rs') {
    $hotel_id = RequestCoerce::string($_REQUEST, 'hotel_id');
    $id_num = RequestCoerce::string($_REQUEST, 'id_num');
    $check_in = RequestCoerce::string($_REQUEST, 'check_in', date('Y-m-d', strtotime('+' . Constants::DEFAULT_CHECKIN_DAYS_AHEAD . ' days')));
    $check_out = RequestCoerce::string($_REQUEST, 'check_out', date('Y-m-d', strtotime('+' . (Constants::DEFAULT_CHECKIN_DAYS_AHEAD + Constants::DEFAULT_STAY_NIGHTS) . ' days')));

    $view->assign('hotel_id', htmlspecialchars($hotel_id, ENT_QUOTES, 'UTF-8'));
    $view->assign('id_num', htmlspecialchars($id_num, ENT_QUOTES, 'UTF-8'));
    $view->assign('check_in', htmlspecialchars($check_in, ENT_QUOTES, 'UTF-8'));
    $view->assign('check_out', htmlspecialchars($check_out, ENT_QUOTES, 'UTF-8'));

    if (!empty($_REQUEST['search']) && !empty($hotel_id)) {
        try {
            $api = new NovotonApi();

            $params = [
                'hotel_id' => $hotel_id,
                'check_in' => $check_in,
                'check_out' => $check_out,
                'adults' => RequestCoerce::int($_REQUEST, 'adults', 2),
                'children' => RequestCoerce::int($_REQUEST, 'children', 0),
            ];

            $results = $api->availability()->searchAvailability($params);

            $view->assign('results', $results);
            $view->assign('last_request', $api->getLastRequestFormatted());

        } catch (\Exception $e) {
            $view->assign('error', $e->getMessage());
        }
    }
}

// ============================================================================
// TEMPLATE MODES — managed directly in this file
// ============================================================================

/**
 * Mode: manage (default)
 * Dashboard with statistics and quick actions
 */
if ($mode === 'manage' || empty($mode)) {
    $hotelRepo = Container::getInstance()->hotelRepository();
    $bookingReporting = Container::getInstance()->bookingReportingRepository();
    $syncLogRepo = Container::getInstance()->syncLogRepository();

    $addon_settings = ConfigProvider::all();

    $countries = fn_novoton_holidays_parse_countries();

    $stats = [
        'hotels' => [
            'total' => $hotelRepo->count(),
            'with_prices' => $hotelRepo->count(['has_verified_room_price' => true]),
            'with_products' => $hotelRepo->count(['has_product' => true]),
            'with_packages' => $hotelRepo->count(['has_packages' => true]),
            'without_packages' => $hotelRepo->count(['no_packages' => true]),
        ],
        'bookings' => (new \Tygh\Addons\NovotonHolidays\Services\BookingQueryService($bookingReporting))->getStats(),
        'by_country' => []
    ];

    foreach ($countries as $country) {
        $stats['by_country'][$country] = [
            'total' => $hotelRepo->count(['country' => $country]),
            'with_prices' => $hotelRepo->count(['country' => $country, 'has_verified_room_price' => true]),
            'with_packages' => $hotelRepo->count(['country' => $country, 'has_packages' => true]),
            'with_products' => $hotelRepo->count(['country' => $country, 'has_product' => true]),
        ];
    }

    $recent_syncs = $syncLogRepo->findRecent(10);

    $last_syncs = [
        'hotellist' => $syncLogRepo->getLastSyncDate('hotel_list'),
        'hotelinfo' => $syncLogRepo->getLastSyncDate('hotelinfo'),
        'prices' => $syncLogRepo->getLastSyncDate('sync_priceinfo'),
        'offers_update' => $syncLogRepo->getLastSyncDate('offers_update'),
        'facilities' => $syncLogRepo->getLastSyncDate('facilities'),
        'resort_list' => $syncLogRepo->getLastSyncDate('resort_list'),
    ];

    // Through the ConfigProvider, not the raw settings array: the cron key
    // lives in Travel Core now, so a direct read of novoton's own settings
    // would print URLs carrying the legacy key (or none) while the endpoint
    // authenticates against Core's.
    $cron_key = ConfigProvider::getCronAccessKey();
    $base_url = TypeCoerce::toString(Registry::get('config.http_location')) . '/';

    $cron_urls = [
        'hotel_info_batched' => $base_url . "index.php?dispatch=novoton_cron.run&access_key={$cron_key}&mode=hotel_info_batched",
        'sync_priceinfo_batched' => $base_url . "index.php?dispatch=novoton_cron.run&access_key={$cron_key}&mode=sync_priceinfo_batched",
        'hotel_list' => $base_url . "index.php?dispatch=novoton_cron.run&access_key={$cron_key}&mode=hotel_list",
        'resort_list' => $base_url . "index.php?dispatch=novoton_cron.run&access_key={$cron_key}&mode=resort_list",
        'list_facilities' => $base_url . "index.php?dispatch=novoton_cron.run&access_key={$cron_key}&mode=list_facilities",
        'hotel_facilities_batched' => $base_url . "index.php?dispatch=novoton_cron.run&access_key={$cron_key}&mode=hotel_facilities_batched",
        'resinfo' => $base_url . "index.php?dispatch=novoton_cron.run&access_key={$cron_key}&mode=resinfo",
        'offers_update' => $base_url . "index.php?dispatch=novoton_cron.run&access_key={$cron_key}&mode=offers_update",
        'room_price' => $base_url . "index.php?dispatch=novoton_cron.run&access_key={$cron_key}&mode=room_price",
        'add_products' => $base_url . "index.php?dispatch=novoton_cron.run&access_key={$cron_key}&mode=add_hotels_as_products",
        'reassign_features' => $base_url . "index.php?dispatch=novoton_cron.run&access_key={$cron_key}&mode=reassign_features",
        'compute_prices' => $base_url . "index.php?dispatch=novoton_cron.run&access_key={$cron_key}&mode=compute_prices",
        'recompute_calendar_prices' => $base_url . "index.php?dispatch=novoton_cron.run&access_key={$cron_key}&mode=recompute_calendar_prices",
        'geocode_addresses' => $base_url . "index.php?dispatch=novoton_cron.run&access_key={$cron_key}&mode=geocode_addresses",
        'backfill_images' => $base_url . "index.php?dispatch=novoton_cron.run&access_key={$cron_key}&mode=backfill_images",
        'cleanup' => $base_url . "index.php?dispatch=novoton_cron.run&access_key={$cron_key}&mode=cleanup",
    ];

    $xml_feed_url = $base_url . "index.php?dispatch=novoton_export.hotel_features_xml&access_key={$cron_key}";

    $view->assign('stats', $stats);
    // NOTE: deliberately NOT assigned as 'countries' — that is a CS-Cart core
    // Smarty global ([code => name] map) and overwriting it with our numeric
    // list shadows core data on the whole admin page. No template consumed it.
    $view->assign('recent_syncs', $recent_syncs);
    $view->assign('last_syncs', $last_syncs);
    $view->assign('cron_urls', $cron_urls);
    $view->assign('cron_key', $cron_key);
    $view->assign('xml_feed_url', $xml_feed_url);
    $view->assign('addon_settings', $addon_settings);
    $view->assign('addon_version', ConfigProvider::getVersion());

    $resorts_by_country = [];
    $hidden_resorts = array_map('strtoupper', ConfigProvider::getHiddenResorts());
    $resorts = $hotelRepo->getCountryCityPairs();
    foreach ($resorts as $resort) {
        if (!empty($hidden_resorts) && in_array(strtoupper($resort['city']), $hidden_resorts, true)) {
            continue;
        }
        $resorts_by_country[$resort['country']][] = $resort['city'];
    }
    $view->assign('resorts_by_country', $resorts_by_country);

    $excluded_resorts = [];
    if (!empty($addon_settings['excluded_resorts'])) {
        $excluded_resorts = json_decode(TypeCoerce::toString($addon_settings['excluded_resorts']), true);
        if (!is_array($excluded_resorts)) {
            $excluded_resorts = [];
        }
    }
    $view->assign('excluded_resorts', $excluded_resorts);
}

/**
 * Mode: hotels
 */
if ($mode === 'hotels') {
    $hotelRepo = Container::getInstance()->hotelRepository();

    $filters = [];
    $req_country = RequestCoerce::string($_REQUEST, 'country');
    if (!empty($req_country)) {
        $filters['country'] = $req_country;
    }
    $req_has_room_price = RequestCoerce::string($_REQUEST, 'has_room_price');
    if (!empty($req_has_room_price)) {
        $filters['has_room_price'] = $req_has_room_price;
    }
    if (!empty($_REQUEST['has_product'])) {
        $filters['has_product'] = true;
    }

    $items_per_page = TypeCoerce::toInt(Registry::get('settings.Appearance.admin_elements_per_page')) ?: 30;
    $page = RequestCoerce::int($_REQUEST, 'page', 1);
    $offset = ($page - 1) * $items_per_page;

    $hotels = $hotelRepo->findAll($filters, $items_per_page, $offset);
    $total = $hotelRepo->count($filters);

    $view->assign('hotels', $hotels);
    $view->assign('total', $total);
    $view->assign('page', $page);
    $view->assign('items_per_page', $items_per_page);
    // 'countries' deliberately not assigned — core Smarty global; no consumer.
    $view->assign('filters', $filters);
}

/**
 * Mode: view_hotel
 */
if ($mode === 'view_hotel') {
    $hotel_id = RequestCoerce::string($_REQUEST, 'hotel_id');

    if (empty($hotel_id)) {
        return [CONTROLLER_STATUS_REDIRECT, 'novoton_holidays.hotels'];
    }

    $hotelRepo = Container::getInstance()->hotelRepository();
    $hotel = $hotelRepo->findById($hotel_id);

    if (empty($hotel)) {
        fn_set_notification('E', __('error'), 'Hotel not found');
        return [CONTROLLER_STATUS_REDIRECT, 'novoton_holidays.hotels'];
    }

    $packageRepo = Container::getInstance()->hotelPackageRepository();
    $hotel['packages'] = $packageRepo->findForHotelDetail($hotel_id);

    if (!empty($hotel['hotel_data'])) {
        $hotelData = TypeCoerce::toStringMap(json_decode(TypeCoerce::toString($hotel['hotel_data']), true));
        $rooms = TypeCoerce::toStringMap($hotelData['rooms'] ?? []);
        if (!empty($rooms)) {
            $hotel['rooms'] = isset($rooms['IdRoom']) ? [$rooms] : TypeCoerce::toRowList($hotelData['rooms'] ?? []);
        }
        $boards = TypeCoerce::toStringMap($hotelData['boards'] ?? []);
        if (!empty($boards)) {
            $hotel['boards'] = isset($boards['IdBoard']) ? [$boards] : TypeCoerce::toRowList($hotelData['boards'] ?? []);
        }
    }

    $facilities = fn_novoton_holidays_get_hotel_facilities($hotel_id);

    $bookingRepo = Container::getInstance()->bookingRepository();
    $bookings = $bookingRepo->findByHotelId($hotel_id);

    $view->assign('hotel', $hotel);
    $view->assign('facilities', $facilities);
    $view->assign('bookings', $bookings);
}
