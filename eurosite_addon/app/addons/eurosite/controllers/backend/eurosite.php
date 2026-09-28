<?php

declare(strict_types=1);
/**
 * Eurosite Touring — backend admin controller.
 *
 * Modes:
 *   - manage (default): dashboard — API health, catalog counts, last syncs,
 *     recent bookings, cron URL map, quick sync actions
 *   - whitelist: destination whitelist editor (countries → cities)
 *   - hotels: the listed (whitelisted) hotels — availability, images,
 *     products; filters, sorting and CS-Cart paging
 *   - create_products (POST): products for the selected hotels (hotel_keys[])
 *   - check_availability (POST): run the availability check for the selected
 *     hotels' destinations (all listed destinations when none is selected)
 *   - get_cities (AJAX GET): synced cities of a country as JSON, with a live
 *     getCityRequest fallback for countries not yet synced
 *   - run_sync (POST): run one cron command inline (sync_type param)
 *   - save_whitelist (POST): replace the whitelist (whitelist_json field)
 *   - test_connection (POST): cheap auth probe (getRoomTypes)
 *   - generate_cron_key (POST): mint the SHARED Travel Core cron key
 *   - seo_templates: SEO Templates (Travel Core's shared page)
 *   - save_seo_templates (POST): mode, "Apply" ticks, per-language templates
 *   - apply_seo_templates (POST): save, then re-apply to every linked product
 */

use Tygh\Addons\Eurosite\Cron\CronDispatcher;
use Tygh\Addons\Eurosite\Repository\HotelListingRepository;
use Tygh\Addons\Eurosite\Services\ConfigProvider;
use Tygh\Addons\Eurosite\Services\Container;
use Tygh\Addons\Eurosite\Services\CronPlanBuilder;
use Tygh\Addons\Eurosite\Services\HotelListView;
use Tygh\Addons\TravelCore\Cron\CronKeyService;
use Tygh\Addons\TravelCore\Helpers\RequestCoerce;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Tygh;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

/** @var \Smarty $view */
$view = Tygh::$app['view'];

// The dashboard is a first-class entry point for schema-dependent reads —
// apply any pending deltas before touching the eurosite tables (per-request
// guarded; also covers stores whose init-time heal was stamped early).
if (function_exists('fn_eurosite_ensure_schema')) {
    fn_eurosite_ensure_schema();
}

// ─── POST handlers ───

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($mode === 'run_sync') {
        $syncType = (string) preg_replace('/[^a-z0-9_]/', '', strtolower(RequestCoerce::string($_REQUEST, 'sync_type')));
        $dispatcher = new CronDispatcher();
        if (!$dispatcher->hasMode($syncType)) {
            fn_set_notification('E', __('error'), "Unknown sync type: {$syncType}");

            return [CONTROLLER_STATUS_REDIRECT, 'eurosite.manage'];
        }

        if (function_exists('set_time_limit')) {
            set_time_limit(0);
        }
        // The dispatcher echoes progress lines (cron context); buffer them
        // away so they never leak into the admin page.
        ob_start();
        try {
            $result = $dispatcher->dispatch($syncType, []);
        } finally {
            ob_end_clean();
        }

        // A sync fired from the whitelist editor returns there, not to the
        // dashboard — being bounced off the page you were working on is how
        // you lose your place in an 86-row list.
        $returnTo = RequestCoerce::string($_REQUEST, 'return_to') === 'whitelist'
            ? 'eurosite.whitelist'
            : 'eurosite.manage';

        $success = TypeCoerce::toBool($result['success'] ?? false);
        $stats = TypeCoerce::toStringMap($result['stats'] ?? []);
        $summary = TypeCoerce::toInt($stats['synced'] ?? 0) . '/' . TypeCoerce::toInt($stats['total'] ?? 0) . ' synced';
        if (!empty($result['busy'])) {
            fn_set_notification('W', __('warning'), TypeCoerce::toString($result['message'] ?? 'Sync already running.'));
        } elseif ($success) {
            fn_set_notification('N', __('notice'), "Eurosite sync '{$syncType}' completed: {$summary}");
        } else {
            fn_set_notification('E', __('error'), "Eurosite sync '{$syncType}' failed: " . TypeCoerce::toString($result['error'] ?? 'unknown error'));
        }

        return [CONTROLLER_STATUS_REDIRECT, $returnTo];
    }

    if ($mode === 'save_whitelist') {
        $raw = RequestCoerce::string($_REQUEST, 'whitelist_json');
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            fn_set_notification('E', __('error'), 'Invalid whitelist payload.');

            return [CONTROLLER_STATUS_REDIRECT, 'eurosite.whitelist'];
        }
        $entries = [];
        foreach ($decoded as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $entries[] = [
                'country_code'   => strtoupper(TypeCoerce::toString($entry['country_code'] ?? '')),
                'city_code'      => strtoupper(TypeCoerce::toString($entry['city_code'] ?? '')),
                'selection_type' => TypeCoerce::toString($entry['selection_type'] ?? 'specific'),
            ];
        }
        Container::whitelist()->replaceAll($entries);
        fn_set_notification('N', __('notice'), 'Eurosite destination whitelist saved (' . count($entries) . ' entries).');

        return [CONTROLLER_STATUS_REDIRECT, 'eurosite.whitelist'];
    }

    if ($mode === 'generate_cron_key') {
        // The dashboard offers this because the alternative — an operator
        // inventing a key in the settings form — is what left this store with
        // a blank one, and a blank key makes every scheduled sync answer 403.
        //
        // The key it mints is NOT eurosite's any more. Cron authentication
        // moved into Travel Core: one key, one place to rotate it, one
        // crontab to update. Writing an eurosite-local key here would produce
        // a row nothing reads — ConfigProvider::getCronKey() delegates to
        // CronKeyService — so the button would report success while every
        // scheduled job kept using the old key. The whole mint, including the
        // row-creation and the read-back that a silent updateValue() no-op
        // made necessary, lives in CronKeyService::generate() now; this is
        // the same button pointed at it.
        $newKey = CronKeyService::generate();
        ConfigProvider::resetSettingsCache();

        if ($newKey === '') {
            fn_set_notification(
                'E',
                __('error'),
                // Deliberately does NOT say "open an admin page to let the '
                // self-heal create the setting": generate() already ran that
                // same repair (CronKeyService::ensureSettingExists) before
                // writing, so if we are here it did not work and repeating it
                // will not either. Point at the log and the manual field.
                'Could not store the cron security key. Check the PHP error log, then set the key '
                . 'by hand in Settings > Travel Core > Cron security key.',
            );

            return [CONTROLLER_STATUS_REDIRECT, 'eurosite.manage'];
        }

        // 'W', not 'N': this rotates the key for EVERY travel addon, so Travel
        // Core's own exchange-rate job and the Sphinx and Novoton crontab
        // entries just stopped working too. Naming fewer places than that
        // understates what the operator has to fix.
        fn_set_notification(
            'W',
            __('warning'),
            'A new shared cron security key was generated in Travel Core. It authenticates the '
            . 'scheduled jobs of every travel addon, so re-copy the crontab commands from '
            . 'Travel Core -> Tools and from the Eurosite, Sphinx and Novoton dashboards — '
            . 'the old URLs no longer work.',
        );

        return [CONTROLLER_STATUS_REDIRECT, 'eurosite.manage'];
    }

    if ($mode === 'seed_menu') {
        $created = function_exists('fn_eurosite_seed_storefront_menu') ? fn_eurosite_seed_storefront_menu() : 0;
        if ($created > 0) {
            fn_set_notification('N', __('notice'), "Storefront menu seeded ({$created} items). Review it under Design > Menus.");
        } else {
            fn_set_notification('W', __('warning'), 'Storefront menu items already exist (or seeding is unsupported on this build) — manage them under Design > Menus.');
        }

        return [CONTROLLER_STATUS_REDIRECT, 'eurosite.manage'];
    }

    if ($mode === 'test_connection') {
        try {
            $rooms = Container::getApi()->getRoomTypes();
            fn_set_notification('N', __('notice'), 'Eurosite API OK — room-type catalog answered with ' . count($rooms) . ' entries.');
        } catch (\Throwable $e) {
            fn_set_notification('E', __('error'), 'Eurosite API error: ' . $e->getMessage());
        }

        return [CONTROLLER_STATUS_REDIRECT, 'eurosite.manage'];
    }

    if ($mode === 'save_seo_templates') {
        fn_travel_core_seo_page_save('eurosite', $_REQUEST);

        return [CONTROLLER_STATUS_REDIRECT, 'eurosite.seo_templates'];
    }

    if ($mode === 'apply_seo_templates') {
        $hotelRepo = Container::hotels();

        return fn_travel_core_seo_page_bulk_apply(
            'eurosite',
            $_REQUEST,
            static fn (int $offset, int $batch): array => $hotelRepo->linkedBatchForSeo($offset, $batch),
            static fn (array $hotel): array => \Tygh\Addons\Eurosite\Services\EurositeProductFactory::placeholders(TypeCoerce::toStringMap($hotel)),
            'eurosite.seo_templates',
        );
    }

    if ($mode === 'create_products' || $mode === 'check_availability') {
        $keys = [];
        foreach ((array) ($_REQUEST['hotel_keys'] ?? []) as $key) {
            $key = strtoupper((string) preg_replace('/[^A-Za-z0-9_:]/', '', TypeCoerce::toString($key)));
            if (str_contains($key, ':')) {
                $keys[$key] = $key;
            }
        }
        $returnTo = HotelListView::returnUrl(RequestCoerce::string($_REQUEST, 'return_query'));
        if (function_exists('set_time_limit')) {
            set_time_limit(0);
        }

        if ($mode === 'create_products') {
            if ($keys === []) {
                fn_set_notification('W', __('warning'), __('eurosite.hotels_select_first', ['[default]' => 'Select the hotels first.']));

                return [CONTROLLER_STATUS_REDIRECT, $returnTo];
            }
            $r = Container::hotelProducts()->createFor(Container::hotels()->findManyWithDetails(array_values($keys)));
            [$type, $message] = HotelListView::createdNotice($r);
            fn_set_notification($type, __($type === 'E' ? 'error' : ($type === 'W' ? 'warning' : 'notice')), $message);

            return [CONTROLLER_STATUS_REDIRECT, $returnTo];
        }

        // check_availability: one run per destination of the selection.
        $cities = [];
        foreach (Container::hotels()->findManyWithDetails(array_values($keys)) as $row) {
            $cities[TypeCoerce::toString($row['city_code'] ?? '')] = true;
        }
        $cities = array_filter(array_map('strval', array_keys($cities)), static fn (string $c): bool => $c !== '');
        $dispatcher = new CronDispatcher();
        $ok = 0;
        $failed = [];
        ob_start();
        try {
            foreach ($cities === [] ? [''] : $cities as $city) {
                $result = $dispatcher->dispatch('availability', $city === '' ? [] : ['city' => $city]);
                if (!empty($result['success'])) {
                    $ok++;
                } else {
                    $failed[] = ($city !== '' ? $city . ': ' : '') . TypeCoerce::toString($result['error'] ?? $result['message'] ?? 'failed');
                }
            }
        } finally {
            ob_end_clean();
        }
        if ($failed === []) {
            fn_set_notification('N', __('notice'), __('eurosite.hotels_checked', ['[default]' => 'Availability checked.']));
        } else {
            fn_set_notification('E', __('error'), TypeCoerce::toString(__('eurosite.hotels_check_failed', ['[default]' => 'The availability check failed:'])) . ' ' . implode('; ', array_slice($failed, 0, 3)));
        }

        return [CONTROLLER_STATUS_REDIRECT, $returnTo];
    }

    return [CONTROLLER_STATUS_REDIRECT, 'eurosite.manage'];
}

// ─── GET modes ───

if ($mode === 'get_cities') {
    // AJAX: cities of one country for the whitelist editor. Synced rows
    // first; live fallback so the editor works before the first city sync.
    $country = strtoupper((string) preg_replace('/[^A-Za-z]/', '', RequestCoerce::string($_REQUEST, 'country')));
    $cities = [];
    $source = 'db';
    if ($country !== '') {
        try {
            $hotelCounts = Container::hotels()->countAllByCity($country);
        } catch (\Throwable) {
            $hotelCounts = [];
        }
        foreach (Container::cities()->getByCountry($country) as $row) {
            $code = TypeCoerce::toString($row['city_code'] ?? '');
            $cities[] = [
                'code'   => $code,
                'name'   => TypeCoerce::toString($row['name'] ?? ''),
                'is_own' => TypeCoerce::toString($row['is_own'] ?? 'N') === 'Y',
                'hotels' => $hotelCounts[$code] ?? 0,
            ];
        }
        if ($cities === []) {
            $source = 'live';
            try {
                foreach (Container::getApi()->getCities($country) as $city) {
                    $cities[] = ['code' => $city['code'], 'name' => $city['name'], 'is_own' => false, 'hotels' => 0];
                }
            } catch (\Throwable $e) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                exit;
            }
        }
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'source' => $source, 'cities' => $cities]);
    exit;
}

if ($mode === 'probe_availability') {
    // "Find dates with offers": run the read-only probe here, in the admin,
    // and print its report as plain text in the window the form opened. The
    // form used to post to the storefront cron endpoint, which the admin's
    // form handling refused ("Couldn't upload the file") — and needed the key.
    @set_time_limit(600);
    header('Content-Type: text/plain; charset=utf-8');
    $probe = new \Tygh\Addons\Eurosite\Cron\Commands\ProbeAvailabilityCommand();
    $probe->setOutputCallback(static function (string $message, bool $newline = true): void {
        echo $message, $newline ? "\n" : '';
        if (ob_get_level() > 0) {
            @ob_flush();
        }
        flush();
    });
    $probeParams = [];
    foreach (['country', 'city', 'from', 'months', 'step', 'nights', 'max_calls'] as $key) {
        $value = trim(RequestCoerce::string($_REQUEST, $key));
        if ($value !== '') {
            $probeParams[$key] = $value;
        }
    }
    $probe->execute($probeParams);
    exit;
}

if ($mode === 'search_destinations') {
    // AJAX for the whitelist search box: countries + cities by name OR code,
    // from the synced catalogs. Each result carries enough context for the
    // picker to open the right country and scroll to the right city.
    $query = trim(RequestCoerce::string($_REQUEST, 'q'));
    $results = [];
    if (mb_strlen($query) >= 2) {
        foreach (Container::countries()->search($query) as $row) {
            $cc = TypeCoerce::toString($row['country_code'] ?? '');
            $results[] = [
                'id'           => 'country:' . $cc,
                'type'         => 'country',
                'country_code' => $cc,
                'city_code'    => '',
                'text'         => TypeCoerce::toString($row['name'] ?? '') . ' (' . $cc . ')',
            ];
        }
        foreach (Container::cities()->search($query) as $row) {
            $cc = TypeCoerce::toString($row['country_code'] ?? '');
            $city = TypeCoerce::toString($row['city_code'] ?? '');
            $own = TypeCoerce::toString($row['is_own'] ?? 'N') === 'Y';
            $results[] = [
                'id'           => 'city:' . $cc . ':' . $city,
                'type'         => 'city',
                'country_code' => $cc,
                'city_code'    => $city,
                'is_own'       => $own,
                'text'         => TypeCoerce::toString($row['name'] ?? '')
                    . ' — ' . TypeCoerce::toString($row['country_name'] ?? $cc)
                    . ($own ? ' ★' : ''),
            ];
        }
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'results' => $results]);
    exit;
}

if ($mode === 'seo_templates') {
    // The preview uses a hotel that is a product, else any listed hotel.
    $sampleRow = Container::hotels()->sampleForSeo();
    fn_travel_core_seo_page_assign(
        'eurosite',
        $sampleRow === null ? null : \Tygh\Addons\Eurosite\Services\EurositeProductFactory::placeholders($sampleRow),
        [
            'save'  => 'eurosite.save_seo_templates',
            'apply' => 'eurosite.apply_seo_templates',
            'title' => TypeCoerce::toString(__('eurosite.seo_templates_title', ['[default]' => 'Eurosite — SEO Templates'])),
        ],
    );
}

if ($mode === 'hotels') {
    $perPageRaw = \Tygh\Registry::get('settings.Appearance.admin_elements_per_page');
    $listing = new HotelListingRepository(is_numeric($perPageRaw) && (int) $perPageRaw > 0 ? (int) $perPageRaw : 50);
    $hotelRepo = Container::hotels();
    $rows = [];
    $search = $listing->normalize($_REQUEST) + ['total_items' => 0];
    $summary = HotelListView::emptySummary();
    try {
        [$rows, $search] = $listing->getListing($_REQUEST);
        $summary = HotelListView::summary(
            $hotelRepo->countActive(),
            $hotelRepo->countByAvailability(),
            $listing->imageCounts(),
            $hotelRepo->countProducts(),
            $hotelRepo->countGateHidden(),
            $hotelRepo->countNotWhitelisted(),
            $hotelRepo->countsByCity(),
            Container::syncLog()->getLastPerType(),
        );
    } catch (\Throwable $e) {
        fn_set_notification('E', __('error'), 'Eurosite hotel list unavailable: ' . $e->getMessage());
    }

    // Pre-formatted for Smarty 5 (modifiers throw inside the admin capture).
    $view->assign('eurosite_hotels', HotelListView::rows($rows, ConfigProvider::allowProductsWithoutImages()));
    $view->assign('search', $search);
    $view->assign('eurosite_hotel_summary', $summary);
    $view->assign('eurosite_hotel_chips', HotelListView::chips($search, $summary));
    $view->assign('eurosite_hotels_url', HotelListView::listUrl($search));
    // "Sort by images" starts ascending (no images first), then toggles.
    $view->assign('eurosite_images_sort_order', ($search['sort_by'] ?? '') === 'images' ? ($search['sort_order_rev'] ?? 'asc') : 'asc');
    $view->assign('eurosite_hotels_query', HotelListView::filterQuery($search));
    $view->assign('eurosite_root_category_set', ConfigProvider::getHotelsCategoryId() > 0);
    $view->assign('eurosite_without_images_allowed', ConfigProvider::allowProductsWithoutImages());

    return;
}

if ($mode === 'whitelist') {
    $countries = Container::countries()->findAll();

    // Self-heal the country catalog before rendering.
    //
    // Two states put the editor in front of an unusable list: an empty catalog
    // (no `countries` sync has ever run) and — the one seen in the field — a
    // catalog whose rows carry a code but no name, which renders as a column of
    // bare "TT" / "VC" and makes the search box useless, because it matches on
    // name. Both are repaired the same way the city list already repairs itself:
    // ask the API and upsert what comes back. getCountryRequest is a small,
    // static call, and this only runs while the catalog is unusable.
    // "Unusable" means EVERY row is nameless — one odd row is not worth an API
    // round trip on each page load.
    $namesMissing = $countries !== [];
    foreach ($countries as $row) {
        if (trim(TypeCoerce::toString($row['name'] ?? '')) !== '') {
            $namesMissing = false;
            break;
        }
    }

    $healFailed = '';
    if ($countries === [] || $namesMissing) {
        try {
            $live = Container::getApi()->getCountries();
            if ($live !== []) {
                Container::countries()->upsertBatch($live);
                $countries = Container::countries()->findAll();
                $namesMissing = false;
            }
        } catch (\Throwable $e) {
            // No API (unconfigured store, network, bad credentials) is not a
            // reason to 503 the editor — it still works off codes.
            $healFailed = $e->getMessage();
        }
    }

    $entries = Container::whitelist()->findAll();

    // country => ['all' => bool, 'cities' => list<string>]
    $whitelistMap = [];
    foreach ($entries as $entry) {
        $cc = TypeCoerce::toString($entry['country_code'] ?? '');
        $city = TypeCoerce::toString($entry['city_code'] ?? '');
        if ($cc === '') {
            continue;
        }
        $whitelistMap[$cc] = $whitelistMap[$cc] ?? ['all' => false, 'cities' => []];
        if ($city === '') {
            $whitelistMap[$cc]['all'] = true;
        } else {
            $whitelistMap[$cc]['cities'][] = $city;
        }
    }

    // Own-offer cities per country, on the row itself: the "own" badge and
    // the "Show only destinations with own hotels" filter read it.
    $ownByCountry = Container::cities()->ownCountsByCountry();
    foreach ($countries as $i => $row) {
        $countries[$i]['own_cities'] = $ownByCountry[TypeCoerce::toString($row['country_code'] ?? '')] ?? 0;
    }

    $view->assign('eurosite_countries', $countries);
    $view->assign('eurosite_whitelist_map', $whitelistMap);
    $view->assign('eurosite_whitelist_json', json_encode($whitelistMap));
    $view->assign('eurosite_countries_synced', $countries !== []);
    $view->assign('eurosite_country_names_missing', $countries !== [] && $namesMissing);
    $view->assign('eurosite_country_heal_error', $healFailed);
    $view->assign('eurosite_countries_last_synced', Container::countries()->getLastSyncedAt());
    $view->assign('eurosite_wl_country_count', count($countries));
    $view->assign('eurosite_wl_city_count', Container::cities()->count());
    $view->assign('eurosite_wl_own_city_count', Container::cities()->count(true));

    return;
}

if ($mode === 'manage' || empty($mode)) {
    // Never 503 the dashboard: any data failure (missing table on a store
    // whose heal has not run yet, DB hiccough) degrades to an error banner
    // on an otherwise-rendered page, so the admin sees WHAT is wrong.
    $counts = [
        'countries' => 0, 'cities' => 0, 'own_cities' => 0, 'hotels' => 0,
        'room_types' => 0, 'tags' => 0, 'cache' => 0, 'whitelist' => 0, 'bookings' => 0,
        'immediate' => 0, 'products' => 0,
    ];
    $lastSyncs = [];
    $recentBookings = [];
    try {
        $syncLog = Container::syncLog();

        $counts = [
            'countries'  => Container::countries()->count(),
            'cities'     => Container::cities()->count(),
            'own_cities' => Container::cities()->count(true),
            'hotels'     => Container::hotels()->countActive(),
            'room_types' => Container::roomTypes()->count(),
            'tags'       => Container::tags()->count(),
            'cache'      => Container::productInfoCache()->count(),
            'whitelist'  => Container::whitelist()->count(),
            'bookings'   => Container::bookings()->count(),
            // The rows for the availability check and the product jobs.
            'immediate'  => Container::hotels()->countByAvailability()['IM'],
            'products'   => Container::hotels()->countProducts(),
        ];

        // Pre-format for Smarty 5 (modifiers throw inside the admin capture).
        foreach ($syncLog->getLastPerType() as $type => $row) {
            $lastSyncs[$type] = [
                'status'     => TypeCoerce::toString($row['status'] ?? ''),
                'synced'     => TypeCoerce::toInt($row['items_synced'] ?? 0),
                'total'      => TypeCoerce::toInt($row['items_total'] ?? 0),
                'started_at' => TypeCoerce::toString($row['started_at'] ?? ''),
                'duration_s' => round(TypeCoerce::toFloat($row['duration_ms'] ?? 0) / 1000, 1),
                'error'      => TypeCoerce::toString($row['error_message'] ?? ''),
            ];
        }

        foreach (Container::bookings()->getRecent(10) as $row) {
            $recentBookings[] = [
                'booking_id'  => TypeCoerce::toInt($row['booking_id'] ?? 0),
                'hotel_name'  => TypeCoerce::toString($row['hotel_name'] ?? ''),
                'check_in'    => TypeCoerce::toString($row['check_in'] ?? ''),
                'status'      => TypeCoerce::toString($row['status'] ?? ''),
                'total'       => number_format(TypeCoerce::toFloat($row['total_price'] ?? 0), 2)
                    . ' ' . TypeCoerce::toString($row['currency'] ?? 'EUR'),
                'order_id'    => TypeCoerce::toInt($row['order_id'] ?? 0),
                'created_at'  => TypeCoerce::toString($row['created_at'] ?? ''),
            ];
        }
    } catch (\Throwable $e) {
        fn_set_notification('E', __('error'), 'Eurosite dashboard data unavailable: ' . $e->getMessage());
        fn_log_event('general', 'runtime', ['message' => 'Eurosite dashboard error: ' . $e->getMessage()]);
    }

    $syncModes = CronDispatcher::getAvailableModes();

    // ── Catalogs and their schedules, as ONE list ──
    //
    // CronPlanBuilder owns the ordering, the schedule wording, the staleness
    // test and the crontab text; the controller only hands it the numbers it
    // already gathered. See its docblock for why the dashboard stopped
    // describing the same eight jobs in two separate tables.
    $cronKey = ConfigProvider::getCronAccessKey();
    $baseUrl = TypeCoerce::toString(\Tygh\Registry::get('config.http_location'));
    $plan = new CronPlanBuilder($baseUrl, $cronKey, defined('DIR_ROOT') ? TypeCoerce::toString(constant('DIR_ROOT')) : '');

    $cronRows = $plan->rows($syncModes, $counts, $lastSyncs);

    // All four crontab variants up front: the page switches plan (nightly full
    // vs per-catalog) and format (URL vs CLI) client-side, with no round trip.
    $generatedOn = date('j M Y') . ' · server time ' . date_default_timezone_get();
    $crontabs = [];
    foreach (['full', 'per'] as $planKey) {
        foreach (['url', 'cli'] as $format) {
            $crontabs[$planKey . '_' . $format] = $plan->crontab($planKey, $format, $syncModes, $generatedOn);
        }
    }

    // Kept for the "Sync now" buttons, which post a mode rather than call a URL.
    $cronUrls = [];
    foreach (array_keys($syncModes) as $m) {
        $cronUrls[$m] = $plan->url(TypeCoerce::toString($m));
    }

    $apiUser = ConfigProvider::getApiUser();
    $view->assign('eurosite_counts', $counts);
    $view->assign('eurosite_recent_bookings', $recentBookings);
    $view->assign('eurosite_sync_modes', $syncModes);
    $view->assign('eurosite_cron_urls', $cronUrls);
    $view->assign('eurosite_cron_rows', $cronRows);
    $view->assign('eurosite_crontabs_json', json_encode($crontabs));
    $view->assign('eurosite_cron_has_key', $plan->hasKey());
    $view->assign('eurosite_cron_key', $cronKey);
    $view->assign('eurosite_api_url', ConfigProvider::getApiUrl());
    $view->assign('eurosite_api_user', $apiUser);
    $view->assign('eurosite_is_configured', $apiUser !== '' && $apiUser !== 'YourUser');

    return;
}
