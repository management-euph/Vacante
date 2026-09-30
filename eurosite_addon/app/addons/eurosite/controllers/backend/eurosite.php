<?php

declare(strict_types=1);
/**
 * Eurosite Touring — backend admin controller.
 *
 * Modes:
 *   - manage (default): dashboard — API health, catalog counts, last syncs,
 *     recent bookings, cron URL map, quick sync actions
 *   - whitelist: the destination whitelist (Travel Core's destination picker:
 *     per country Not sold / All cities / Own cities / Only selected)
 *   - whitelist_body (AJAX GET): one country's cities for the picker, as {html}
 *   - search_destinations (AJAX GET): cities matching q, as picker hits
 *   - hotels: the listed (whitelisted) hotels — availability, images,
 *     products; filters, sorting and CS-Cart paging
 *   - create_products (POST): products for the selected hotels (hotel_keys[])
 *   - check_availability (POST): run the availability check for the selected
 *     hotels' destinations (all listed destinations when none is selected)
 *   - run_sync (POST): run one cron command inline (sync_type param)
 *   - save_whitelist (POST): replace the whitelist (the picker's dest_json)
 *   - disable_outside (POST): disable the live products outside the saved
 *     whitelist that the admin confirmed (Save never touches products)
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
use Tygh\Addons\Eurosite\Services\DestinationsPicker;
use Tygh\Addons\Eurosite\Services\HotelListView;
use Tygh\Addons\TravelCore\Cron\CronKeyService;
use Tygh\Addons\TravelCore\Helpers\RequestCoerce;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\DestinationPicker;
use Tygh\Tygh;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

/** @var \Smarty $view */
$view = Tygh::$app['view'];

// The init.php schema self-heal is stamp-gated and, by design, skips a failed
// ALTER quietly: a store can reach this controller without the columns its
// pages read (the whitelist's first_seen_at). Apply the deltas here too, like
// the cron controller does. SchemaMigrator::ensure() runs once per request and
// only ALTERs what is missing (two INFORMATION_SCHEMA reads). An ALTER that
// fails now stops the page with its own error instead of "Unknown column".
if (function_exists('fn_eurosite_ensure_schema')) {
    fn_eurosite_ensure_schema();
}

/**
 * The destination whitelist as DestinationsPicker::build() sees it: every
 * country (or one), its synced cities with their figures, the saved rows and
 * the live products.
 *
 * @return array{configured: bool, countries: list<array<string, mixed>>, totals: array<string, int>, outside: list<array{product_id: int, label: string}>}
 */
function _eurosite_dest_build(string $country = ''): array
{
    $countries = Container::countries()->findAll();
    if ($country !== '') {
        $countries = array_values(array_filter($countries, static fn (array $row): bool => strtoupper(TypeCoerce::toString($row['country_code'] ?? '')) === $country));
    }
    $whitelist = Container::whitelist();

    return DestinationsPicker::build(
        $countries,
        Container::cities()->pickerRows($country),
        $whitelist->findAll(),
        $whitelist->lastSavedAt(),
        $country === '' ? $whitelist->liveProducts() : [],
    );
}

/**
 * The picker page data (fn_travel_core_dest_page()) around a build().
 *
 * @param array{configured: bool, countries: list<array<string, mixed>>, totals: array<string, int>, outside: list<array{product_id: int, label: string}>} $built
 * @return array<string, mixed>
 */
function _eurosite_dest_page(array $built, bool $lazy = true): array
{
    $page = DestinationsPicker::page($built, fn_travel_core_dest_words([
        'search' => __('eurosite.dest_search'),
        'filter' => __('eurosite.dest_filter'),
        'item_type' => __('eurosite.dest_item_type'),
        'gone' => __('eurosite.dest_gone'),
        'no_match' => __('eurosite.dest_no_match'),
        'fold' => __('eurosite.dest_fold'),
    ]), [
        'save' => TypeCoerce::toString(fn_url('eurosite.save_whitelist')),
        'outside' => TypeCoerce::toString(fn_url('eurosite.disable_outside')),
        'body' => TypeCoerce::toString(fn_url('eurosite.whitelist_body')),
        'search' => TypeCoerce::toString(fn_url('eurosite.search_destinations')),
    ]);
    $page['lazy_bodies'] = $lazy;

    return fn_travel_core_dest_page($page);
}

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
        $known = [];
        foreach (Container::countries()->findAll() as $row) {
            $known[strtoupper(TypeCoerce::toString($row['country_code'] ?? ''))] = true;
        }
        $synced = [];
        foreach (Container::cities()->pickerRows() as $row) {
            $synced[strtoupper($row['country_code'])][strtoupper($row['city_code'])] = true;
        }
        $whitelist = Container::whitelist();
        $rows = DestinationsPicker::rows(DestinationPicker::readPost($_POST), $known, $synced, DestinationsPicker::scope($whitelist->findAll()));
        $countries = array_filter($rows, static fn (array $r): bool => $r['city_code'] === '');

        // An empty whitelist stops every hotel sync ("Configure the whitelist
        // first"): choosing to sell nothing is not something Save does.
        if ($countries === []) {
            fn_set_notification('E', __('error'), __('travel_core.dest_none_sold'));

            return [CONTROLLER_STATUS_REDIRECT, 'eurosite.whitelist'];
        }

        try {
            $whitelist->replaceAll($rows);
            fn_set_notification('N', __('notice'), __('eurosite.dest_saved', [
                '[countries]' => count($countries),
                '[cities]' => count($rows) - count($countries),
            ]));
        } catch (\Throwable $e) {
            error_log('eurosite: could not save the destination whitelist — ' . $e->getMessage());
            fn_set_notification('E', __('error'), __('travel_core.dest_save_failed'));
        }

        return [CONTROLLER_STATUS_REDIRECT, 'eurosite.whitelist'];
    }

    if ($mode === 'disable_outside') {
        // Only the products the admin confirmed AND still outside the saved
        // whitelist: a stale page never disables something now sold.
        $confirmed = DestinationPicker::productIds($_POST['product_ids'] ?? '');
        $outside = array_map(static fn (array $p): int => $p['product_id'], _eurosite_dest_build()['outside']);
        $ids = array_values(array_intersect($outside, $confirmed));
        $n = Container::whitelist()->disableProducts($ids);
        fn_set_notification('N', __('notice'), __('travel_core.dest_disabled', ['[n]' => $n]));

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

if ($mode === 'whitelist_body') {
    // AJAX: one country's cities for the picker, rendered by Travel Core's
    // destination_country_body.tpl. A country with no synced city gets the
    // live getCityRequest list (not stored), so it can be picked before the
    // first cities sync.
    $country = strtoupper((string) preg_replace('/[^A-Za-z]/', '', RequestCoerce::string($_REQUEST, 'country')));
    $html = '';
    if ($country !== '') {
        $built = _eurosite_dest_build($country);
        if ($built['countries'] !== [] && ($built['countries'][0]['groups'] ?? []) === []) {
            try {
                $items = [];
                foreach (Container::getApi()->getCities($country) as $city) {
                    $items[] = [
                        'city_code' => TypeCoerce::toString($city['code']), 'country_code' => $country, 'name' => TypeCoerce::toString($city['name']),
                        'is_own' => false, 'first_seen_at' => '', 'last_synced_at' => '', 'hotels' => 0, 'priced' => 0, 'instant' => 0, 'live' => 0,
                    ];
                }
                $whitelist = Container::whitelist();
                $built = DestinationsPicker::build(Container::countries()->findAll(), $items, $whitelist->findAll(), $whitelist->lastSavedAt(), []);
                $built['countries'] = array_values(array_filter($built['countries'], static fn (array $c): bool => $c['key'] === $country));
            } catch (\Throwable) {
                // No API: the body says no cities are synced yet.
            }
        }
        $dest = _eurosite_dest_page($built, false);
        foreach (is_array($dest['countries'] ?? null) ? $dest['countries'] : [] as $c) {
            if (is_array($c) && ($c['key'] ?? '') === $country) {
                $view->assign('dest', $dest);
                $view->assign('country', $c);
                $html = TypeCoerce::toString($view->fetch('addons/travel_core/components/destination_country_body.tpl'));
            }
        }
    }
    header('Content-Type: application/json');
    echo json_encode(['html' => $html]);
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
    // AJAX for the picker's search box: cities by name OR code, from the
    // synced catalog (countries are matched on the page itself). Each hit
    // names its country, so the picker can load that body and tick the city.
    $query = trim(RequestCoerce::string($_REQUEST, 'q'));
    $hits = [];
    if (mb_strlen($query) >= 2) {
        foreach (Container::cities()->search($query, 30) as $row) {
            $cc = strtoupper(TypeCoerce::toString($row['country_code'] ?? ''));
            $code = TypeCoerce::toString($row['city_code'] ?? '');
            $name = trim(TypeCoerce::toString($row['name'] ?? ''));
            $hits[] = [
                'country' => $cc,
                'item' => $code,
                'label' => ($name !== '' ? $name : $code) . (TypeCoerce::toString($row['is_own'] ?? 'N') === 'Y' ? ' ★' : ''),
                'path' => $code,
            ];
        }
    }
    header('Content-Type: application/json');
    echo json_encode(['hits' => $hits]);
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

    $view->assign('eurosite_destinations', _eurosite_dest_page(_eurosite_dest_build()));
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
    $destCard = [];
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

        // Destinations: what we sell per country, and what needs a look.
        $destCard = DestinationsPicker::card(_eurosite_dest_build(), TypeCoerce::toString(fn_url('eurosite.whitelist')));

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
    $view->assign('eurosite_dest_card', $destCard);
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
