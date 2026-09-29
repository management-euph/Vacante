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
    /**
     * Mode: run_job — run one cron job from the dashboard, as the signed-in admin.
     *
     * The dashboard's Run / Check status / Force full sync / Reset used to be
     * links to the public cron URL, carrying the shared cron key: the key went
     * into browser history and Referer headers, and a GET "Reset" could be
     * fired by any page the admin visited. This is a POST (CS-Cart checks its
     * security_hash) and needs no key. The dashboard opens it in a new tab, where
     * the job's log streams as plain text, exactly as the cron URL showed it.
     */
    if ($mode === 'run_job') {
        if (!fn_check_permissions('manage_catalog', 'update', 'admin')) {
            return [CONTROLLER_STATUS_DENIED];
        }

        $job = TypeCoerce::toString(preg_replace('/[^a-z0-9_]/', '', strtolower(RequestCoerce::string($_REQUEST, 'job'))));
        if (!array_key_exists($job, \Tygh\Addons\NovotonHolidays\Cron\CronDispatcher::getAvailableModes())) {
            fn_set_notification('E', __('error'), __('novoton_holidays.job_unknown', ['[job]' => $job]));

            return [CONTROLLER_STATUS_REDIRECT, 'novoton_holidays.manage'];
        }

        // The batched jobs' extra actions, passed on as the flags their
        // commands already read from the cron URL.
        $params = [];
        $action = RequestCoerce::string($_REQUEST, 'job_action');
        if (in_array($action, ['status', 'force_full', 'reset'], true)) {
            $params[$action] = '1';
        }

        header('Content-Type: text/plain; charset=utf-8');
        $logger = new \Tygh\Addons\NovotonHolidays\Helpers\SyncLogger($job);
        $logger->outputHeader($job);
        try {
            $dispatcher = new \Tygh\Addons\NovotonHolidays\Cron\CronDispatcher(_nvt_api(), $logger);
            $result = $dispatcher->dispatch($job, $params);
            $logger->complete(TypeCoerce::toBool($result['success'] ?? true));
        } catch (\Throwable $e) {
            $logger->output('ERROR: ' . $e->getMessage());
        }
        $logger->outputFooter();
        exit;
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
    // It rewrites every hotel's calendar prices: a POST (CS-Cart checks the
    // security_hash), never a link a page could fire. The dashboard runs it
    // through run_job; this stays for bookmarked forms.
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return [CONTROLLER_STATUS_REDIRECT, 'novoton_holidays.manage'];
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

    $stats = [
        'hotels' => [
            'total' => $hotelRepo->count(),
            'with_prices' => $hotelRepo->count(['has_verified_room_price' => true]),
            'with_products' => $hotelRepo->count(['has_product' => true]),
            'with_packages' => $hotelRepo->count(['has_packages' => true]),
            'without_packages' => $hotelRepo->count(['no_packages' => true]),
        ],
        'bookings' => (new \Tygh\Addons\NovotonHolidays\Services\BookingQueryService($bookingReporting))->getStats(),
    ];

    // Recent sync activity, CS-Cart paginated: $search carries page,
    // items_per_page and total_items for common/pagination.tpl.
    $activity_status = RequestCoerce::string($_REQUEST, 'activity') === 'failed' ? 'failed' : '';
    $activity = $syncLogRepo->findPaginated(
        max(1, RequestCoerce::int($_REQUEST, 'page', 1)),
        max(1, RequestCoerce::int($_REQUEST, 'items_per_page', 10)),
        '',
        $activity_status,
    );
    $view->assign('recent_syncs', $activity['items']);
    $view->assign('search', [
        'page' => $activity['page'],
        'items_per_page' => $activity['per_page'],
        'total_items' => $activity['total'],
        'activity' => $activity_status,
    ]);
    $view->assign('novoton_activity_failed', $syncLogRepo->count('', 'failed'));
    $view->assign('novoton_activity_all', $activity_status === '' ? $activity['total'] : $syncLogRepo->count());

    // Through the ConfigProvider, not the raw settings array: the cron key
    // lives in Travel Core now, so a direct read of novoton's own settings
    // would print URLs carrying the legacy key (or none) while the endpoint
    // authenticates against Core's.
    $cron_key = ConfigProvider::getCronAccessKey();
    $base_url = TypeCoerce::toString(Registry::get('config.http_location')) . '/';

    // Scheduled jobs: one row per job, in the order they run, from the plan
    // builder. The page never prints a command with the key in it — every
    // command is shown masked, and Copy puts the real one on the clipboard.
    $planner = new \Tygh\Addons\NovotonHolidays\Services\CronPlanBuilder(
        $base_url,
        $cron_key,
        defined('DIR_ROOT') ? TypeCoerce::toString(DIR_ROOT) : '',
    );
    $cron_modes = \Tygh\Addons\NovotonHolidays\Cron\CronDispatcher::getAvailableModes();
    $cron_records = [];
    $cron_legacy = [];
    foreach (array_keys($cron_modes) as $cron_mode) {
        $cron_records[$cron_mode] = \Tygh\Addons\TravelCore\Cron\CronRunLog::get(\Tygh\Addons\NovotonHolidays\Services\CronPlanBuilder::ADDON, $cron_mode);
        foreach (\Tygh\Addons\NovotonHolidays\Services\CronPlanBuilder::legacyLogTypes($cron_mode) as $log_type) {
            $log_row = $syncLogRepo->getLastSync($log_type);
            $log_at = $log_row === null ? false : strtotime(TypeCoerce::toString($log_row['sync_date'] ?? ''));
            if ($log_at !== false && $log_at > ($cron_legacy[$cron_mode]['at'] ?? 0)) {
                $cron_legacy[$cron_mode] = ['at' => $log_at, 'ok' => TypeCoerce::toString($log_row['status'] ?? '') !== 'failed'];
            }
        }
    }
    $mask = static fn (string $text): string => $cron_key === '' ? $text : str_replace($cron_key, '••••••••', $text);
    $crontab_cli = $planner->crontab($cron_modes, 'cli', date('Y-m-d'));
    $xml_feed_url = $base_url . 'index.php?dispatch=novoton_export.hotel_features_xml&access_key=' . rawurlencode($cron_key);

    $job_stages = $planner->stages($cron_modes, $cron_records, $cron_legacy, time());
    $view->assign('novoton_job_stages', $job_stages);
    $view->assign('novoton_on_demand_jobs', $planner->onDemandRows($cron_modes, $cron_records, time()));
    $view->assign('novoton_cron_has_key', $planner->hasKey());
    $view->assign('novoton_cron_key', $cron_key);
    $view->assign('novoton_crontab_cli', $crontab_cli);
    $view->assign('novoton_crontab_url', $planner->crontab($cron_modes, 'url', date('Y-m-d')));
    $view->assign('novoton_crontab_masked', $mask($crontab_cli));
    $view->assign('novoton_xml_feed_url', $xml_feed_url);
    $view->assign('novoton_xml_feed_masked', $mask($xml_feed_url));

    // NOT 'stats': CS-Cart's backend index.tpl prints {$stats|default:"" nofilter}
    // at the bottom of every admin page (its own timing text), so an array
    // assigned under that name rendered as a literal "Array" under the page.
    $view->assign('novoton_stats', $stats);
    // NOTE: deliberately NOT assigned as 'countries' — that is a CS-Cart core
    // Smarty global ([code => name] map) and overwriting it with our numeric
    // list shadows core data on the whole admin page. No template consumed it.
    $view->assign('addon_settings', $addon_settings);
    $view->assign('addon_version', ConfigProvider::getVersion());

    // Destinations: what we sell per country (the card), and what needs a
    // look (new resorts, resorts gone from the feed, live products outside
    // the whitelist) for Needs attention. One GROUP BY and one join.
    $destRepo = new \Tygh\Addons\NovotonHolidays\Repository\DestinationWhitelistRepository();
    $destinations = \Tygh\Addons\NovotonHolidays\Services\DestinationsPage::build(
        \Tygh\Addons\NovotonHolidays\Services\DestinationScope::current(),
        $destRepo->catalog(),
        \Tygh\Addons\NovotonHolidays\Constants::COUNTRIES,
        array_values(ConfigProvider::getSettingCountries()),
        ConfigProvider::getExcludedResorts(),
        ConfigProvider::getHiddenResorts(),
        $destRepo->liveProducts(),
    );
    $view->assign('novoton_destinations', $destinations);
    $view->assign('novoton_dest_card', \Tygh\Addons\NovotonHolidays\Services\DestinationsPicker::card($destinations, \Tygh\Addons\TravelCore\Helpers\TypeCoerce::toString(fn_url('novoton_destinations.manage'))));
    $view->assign('novoton_dest_alerts', \Tygh\Addons\NovotonHolidays\Services\DashboardSummary::destinationAlerts($destinations));

    // The top of the page: which figures are a problem, and what fixes them.
    $view->assign('novoton_job_health', \Tygh\Addons\NovotonHolidays\Services\DashboardSummary::jobHealth($job_stages));
    $view->assign('novoton_attention', \Tygh\Addons\NovotonHolidays\Services\DashboardSummary::attention($job_stages, [
        'total' => $stats['hotels']['total'],
        'with_packages' => $stats['hotels']['with_packages'],
    ]));
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
