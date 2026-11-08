<?php

declare(strict_types=1);
/**
 * Sphinx Holidays - Backend Admin Controller
 *
 * Modes:
 *   - manage (default): Dashboard with destination + hotel stats and the scheduled jobs
 *   - run_job (POST): run one cron job from the dashboard, as the signed-in admin
 *   - destinations: List/filter destinations with pagination
 *   - hotels: List/filter hotels with pagination
 *   - whitelist: the destination whitelist (Travel Core's destination picker:
 *     per country Not sold / All destinations / Only selected, whole regions)
 *   - whitelist_body (AJAX GET): one country's regions and cities, as {html}
 *   - search_destinations (AJAX GET): regions and cities matching q, as hits
 *   - save_whitelist (POST): replace the whitelist (the picker's dest_json)
 *   - disable_outside (POST): disable the confirmed live products outside it
 *
 * @package SphinxHolidays
 * @since 1.0.0
 */

use Tygh\Addons\SphinxHolidays\Cron\Commands\AddProductsCommand;
use Tygh\Addons\SphinxHolidays\Cron\CronDispatcher;
use Tygh\Addons\SphinxHolidays\Cron\CronPlan;
use Tygh\Addons\SphinxHolidays\Cron\CronRunOutput;
use Tygh\Addons\SphinxHolidays\Repository\HotelSkipRepository;
use Tygh\Addons\SphinxHolidays\Services\CircuitSyncService;
use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;
use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\SphinxHolidays\Services\DashboardSummary;
use Tygh\Addons\SphinxHolidays\Services\DestinationsPicker;
use Tygh\Addons\SphinxHolidays\Services\HotelSyncService;
use Tygh\Addons\SphinxHolidays\Services\PackageRouteSyncService;
use Tygh\Addons\SphinxHolidays\Services\WhitelistPageLoader;
use Tygh\Addons\TravelCore\Cron\CronHealth;
use Tygh\Addons\TravelCore\Cron\CronRunLog;
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
if (function_exists('fn_sphinx_holidays_ensure_schema')) {
    fn_sphinx_holidays_ensure_schema();
}

/**
 * The destination whitelist page (fn_travel_core_dest_page()) around a
 * WhitelistPageLoader::built(): every country's body loads on open ($lazy),
 * or one country's body is rendered alone.
 *
 * @param array{configured: bool, countries: list<array<string, mixed>>, totals: array<string, int>} $built
 * @return array<string, mixed>
 */
function _sphinx_dest_page(array $built, bool $lazy): array
{
    $loader = new WhitelistPageLoader();
    $page = DestinationsPicker::page($built, $loader->outside(), $loader->routes(), fn_travel_core_dest_words([
        'search' => __('sphinx_holidays.dest_search'),
        'filter' => __('sphinx_holidays.dest_filter'),
        'item_type' => __('sphinx_holidays.dest_item_type'),
        'group_type' => __('sphinx_holidays.dest_group_type'),
        'no_match' => __('sphinx_holidays.dest_no_match'),
        'fold' => __('sphinx_holidays.dest_fold'),
    ]), [
        'save' => TypeCoerce::toString(fn_url('sphinx_holidays.save_whitelist')),
        'outside' => TypeCoerce::toString(fn_url('sphinx_holidays.disable_outside')),
        'body' => TypeCoerce::toString(fn_url('sphinx_holidays.whitelist_body')),
        'search' => TypeCoerce::toString(fn_url('sphinx_holidays.search_destinations')),
    ]);
    $page['lazy_bodies'] = $lazy;

    return fn_travel_core_dest_page($page);
}

// $mode is set automatically by CS-Cart from the dispatch parameter
// e.g. dispatch=sphinx_holidays.sync_circuits sets $mode = 'sync_circuits'

// ─── POST handlers ───

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    /*
     * Mode: run_job — run one cron job from the dashboard, as the signed-in admin.
     *
     * The dashboard's Run, its ⋯ menu items, Queue images for a scope and
     * Check one hotel post here (a CS-Cart POST with its security_hash), so
     * no cron key reaches the browser and a GET cannot start a job. Only what
     * CronPlan::runParams() allows reaches the command: the job's own
     * parameters, the flags of an action its menu offers, the scope and hotel
     * fields. The log streams as plain text in the window the dashboard
     * opened, as the cron URL prints it (CronRunOutput).
     */
    if ($mode === 'run_job') {
        if (!fn_check_permissions('manage_catalog', 'update', 'admin')) {
            return [CONTROLLER_STATUS_DENIED];
        }
        $job = (string) preg_replace('/[^a-z0-9_]/', '', strtolower(RequestCoerce::string($_REQUEST, 'job')));
        if (!array_key_exists($job, CronDispatcher::getAvailableModes())) {
            fn_set_notification('E', __('error'), __('sphinx_holidays.jobs_unknown', ['[job]' => $job]));
            return [CONTROLLER_STATUS_REDIRECT, 'sphinx_holidays.manage'];
        }
        $params = CronPlan::runParams($job, RequestCoerce::string($_REQUEST, 'job_action'), $_REQUEST);

        header('Content-Type: text/plain; charset=utf-8');
        echo CronRunOutput::startLine($job);
        try {
            CronRunOutput::dispatch(new CronDispatcher(), $job, $params);
        } catch (\Throwable $e) {
            echo 'ERROR: ' . $e->getMessage() . "\n";
        }
        exit;
    }

    if ($mode === 'sync_circuits') {
        if (!ConfigProvider::isConfigured()) {
            fn_set_notification('E', __('error'), __('sphinx_holidays.api_not_configured'));
            return [CONTROLLER_STATUS_REDIRECT, 'sphinx_holidays.circuits'];
        }

        if (function_exists('set_time_limit')) {
            set_time_limit(0);
        }

        // Admin "Sync now" runs a full re-fetch; the nightly cron stays incremental.
        $service = new CircuitSyncService(Container::getApi());
        $result = $service->sync(true);

        if (!empty($result['success'])) {
            fn_set_notification('N', __('notice'), TypeCoerce::toString(__('sphinx_holidays.sync_completed')) . ': ' . TypeCoerce::toInt($result['synced']) . '/' . TypeCoerce::toInt($result['total']));
        } else {
            fn_set_notification('E', __('error'), TypeCoerce::toString(__('sphinx_holidays.sync_failed')) . ': ' . (TypeCoerce::toString($result['error']) ?: 'Unknown error'));
        }

        return [CONTROLLER_STATUS_REDIRECT, 'sphinx_holidays.circuits'];
    }

    if ($mode === 'sync_package_routes') {
        if (!ConfigProvider::isConfigured()) {
            fn_set_notification('E', __('error'), __('sphinx_holidays.api_not_configured'));
            return [CONTROLLER_STATUS_REDIRECT, 'sphinx_holidays.package_routes'];
        }

        if (function_exists('set_time_limit')) {
            set_time_limit(0);
        }

        $service = new PackageRouteSyncService(Container::getApi());
        $result = $service->sync();

        if (!empty($result['success'])) {
            fn_set_notification('N', __('notice'), TypeCoerce::toString(__('sphinx_holidays.sync_completed')) . ': ' . TypeCoerce::toInt($result['synced']) . '/' . TypeCoerce::toInt($result['total']));
        } else {
            fn_set_notification('E', __('error'), TypeCoerce::toString(__('sphinx_holidays.sync_failed')) . ': ' . (TypeCoerce::toString($result['error']) ?: 'Unknown error'));
        }

        return [CONTROLLER_STATUS_REDIRECT, 'sphinx_holidays.package_routes'];
    }

    if ($mode === 'add_products') {
        $hotelRepo = Container::getHotelRepository();
        $hotelRepo->unlinkMissingProducts(); // a deleted product's hotel gets a new one
        $unlinked = $hotelRepo->findUnlinked('', 1);

        if (empty($unlinked)) {
            fn_set_notification('W', __('warning'), __('sphinx_holidays.no_unlinked_hotels'));
            return [CONTROLLER_STATUS_REDIRECT, 'sphinx_holidays.manage'];
        }

        $command = new AddProductsCommand();
        $command->setOutputCallback(function ($msg): void {
        }); // silent in web context
        $result = $command->execute();

        if (!empty($result['success'])) {
            $stats = is_array($result['stats'] ?? null) ? $result['stats'] : [];
            $added = TypeCoerce::toInt($stats['added'] ?? 0);
            $invalid = TypeCoerce::toInt($stats['invalid_country'] ?? 0);
            $msg = TypeCoerce::toString(__('sphinx_holidays.products_created')) . ': ' . $added;
            if ($invalid > 0) {
                $msg .= ' (' . $invalid . ' ' . TypeCoerce::toString(__('sphinx_holidays.skipped_invalid_country')) . ')';
            }
            fn_set_notification('N', __('notice'), $msg);
        } else {
            fn_set_notification('E', __('error'), __('sphinx_holidays.products_creation_failed'));
        }

        return [CONTROLLER_STATUS_REDIRECT, 'sphinx_holidays.manage'];
    }

    if ($mode === 'relink_products') {
        if (!ConfigProvider::isConfigured()) {
            fn_set_notification('E', __('error'), __('sphinx_holidays.api_not_configured'));
            return [CONTROLLER_STATUS_REDIRECT, 'sphinx_holidays.manage'];
        }

        if (function_exists('set_time_limit')) {
            set_time_limit(0);
        }

        $api = Container::getApi();
        $hotelRepo = Container::getHotelRepository();
        $destRepo = Container::getDestinationRepository();
        $skipRepo = Container::getHotelSkipRepository();
        $service = new HotelSyncService($api, $hotelRepo, $destRepo, $skipRepo);

        $result = $service->relinkExistingProducts();
        $rLinked = TypeCoerce::toInt($result['linked']);
        $rSkipped = TypeCoerce::toInt($result['skipped']);
        $rNotFound = TypeCoerce::toInt($result['not_found']);
        $rErrors = TypeCoerce::toInt($result['errors']);
        $rTotal = TypeCoerce::toInt($result['total']);

        if ($rLinked > 0 || $rSkipped > 0) {
            fn_set_notification('N', __('notice'), __('sphinx_holidays.relink_done', [
                '[linked]' => $rLinked,
                '[skipped]' => $rSkipped,
                '[not_found]' => $rNotFound,
                '[errors]' => $rErrors,
                '[total]' => $rTotal,
            ]));
        } elseif ($rTotal === 0) {
            fn_set_notification('W', __('warning'), __('sphinx_holidays.no_spx_products'));
        } else {
            fn_set_notification('W', __('warning'), __('sphinx_holidays.relink_done', [
                '[linked]' => 0,
                '[skipped]' => $rSkipped,
                '[not_found]' => $rNotFound,
                '[errors]' => $rErrors,
                '[total]' => $rTotal,
            ]));
        }

        return [CONTROLLER_STATUS_REDIRECT, 'sphinx_holidays.manage'];
    }

    if ($mode === 'retry_skipped') {
        $skipRepo = Container::getHotelSkipRepository();
        $reset = $skipRepo->resetSkipped();

        if ($reset > 0) {
            fn_set_notification('N', __('notice'), __('sphinx_holidays.skipped_reset', ['[count]' => $reset]));
        } else {
            fn_set_notification('W', __('warning'), __('sphinx_holidays.no_skipped_hotels'));
        }

        return [CONTROLLER_STATUS_REDIRECT, 'sphinx_holidays.manage'];
    }

    if ($mode === 'save_whitelist') {
        $loader = new WhitelistPageLoader();
        $rows = $loader->rows(DestinationPicker::readPost($_POST));
        // Every country sold starts with its row: none means "sell nothing",
        // which stops every Sphinx sync ("No sync targets configured").
        if ($rows === []) {
            fn_set_notification('E', __('error'), __('travel_core.dest_none_sold'));
            return [CONTROLLER_STATUS_REDIRECT, 'sphinx_holidays.whitelist'];
        }
        try {
            Container::getDestinationWhitelistRepository()->replaceAll($rows);
        } catch (\Exception $e) {
            fn_set_notification('E', __('error'), __('sphinx_holidays.whitelist_save_failed'));
            return [CONTROLLER_STATUS_REDIRECT, 'sphinx_holidays.whitelist'];
        }

        // Seed whitelisted regions into Feature Mappings
        fn_sphinx_holidays_seed_region_mappings();

        fn_set_notification('N', __('notice'), __('sphinx_holidays.whitelist_saved'));
        return [CONTROLLER_STATUS_REDIRECT, 'sphinx_holidays.whitelist'];
    }

    if ($mode === 'disable_outside') {
        // Only the products the admin confirmed AND still outside the saved
        // whitelist: a stale page never disables something now sold.
        $loader = new WhitelistPageLoader();
        $outside = array_map(static fn (array $p): int => $p['product_id'], $loader->outside()['outside']);
        $ids = array_values(array_intersect($outside, DestinationPicker::productIds($_POST['product_ids'] ?? '')));
        fn_set_notification('N', __('notice'), __('travel_core.dest_disabled', ['[n]' => $loader->disableProducts($ids)]));
        return [CONTROLLER_STATUS_REDIRECT, 'sphinx_holidays.whitelist'];
    }

    if ($mode === 'bulk_update_hotels') {
        $hotelIds = RequestCoerce::list($_REQUEST, 'hotel_ids');
        $status = RequestCoerce::string($_REQUEST, 'bulk_status');
        $validStatuses = ['active', 'inactive', 'pending', 'error'];

        if (empty($hotelIds)) {
            fn_set_notification('W', __('warning'), __('sphinx_holidays.no_hotels_selected'));
            return [CONTROLLER_STATUS_REDIRECT, 'sphinx_holidays.hotels'];
        }

        if (!in_array($status, $validStatuses, true)) {
            fn_set_notification('E', __('error'), 'Invalid status value.');
            return [CONTROLLER_STATUS_REDIRECT, 'sphinx_holidays.hotels'];
        }

        // sphinx_hotels.hotel_id is VARCHAR(100) (e.g. "s1-hotel-123") — keep as strings.
        $hotelIds = array_map(static fn ($v): string => TypeCoerce::toString($v), $hotelIds);
        $hotelRepo = Container::getHotelRepository();
        $affected = $hotelRepo->bulkUpdateStatus($hotelIds, $status);
        fn_set_notification('N', __('notice'), TypeCoerce::toString(__('sphinx_holidays.hotels_updated')) . ': ' . $affected);

        return [CONTROLLER_STATUS_REDIRECT, 'sphinx_holidays.hotels'];
    }

    if ($mode === 'bulk_delete_hotels') {
        $hotelIds = RequestCoerce::stringList($_REQUEST, 'hotel_ids');

        if (empty($hotelIds)) {
            fn_set_notification('W', __('warning'), __('sphinx_holidays.no_hotels_selected'));
            return [CONTROLLER_STATUS_REDIRECT, 'sphinx_holidays.hotels'];
        }

        $hotelRepo = Container::getHotelRepository();
        $affected = $hotelRepo->bulkDelete($hotelIds);
        fn_set_notification('N', __('notice'), TypeCoerce::toString(__('sphinx_holidays.hotels_deleted')) . ': ' . $affected);

        return [CONTROLLER_STATUS_REDIRECT, 'sphinx_holidays.hotels'];
    }

    if ($mode === 'bulk_sync_images') {
        $hotelIds = RequestCoerce::list($_REQUEST, 'hotel_ids');

        if (empty($hotelIds)) {
            fn_set_notification('W', __('warning'), __('sphinx_holidays.no_hotels_selected'));
            return [CONTROLLER_STATUS_REDIRECT, 'sphinx_holidays.hotels'];
        }

        if (function_exists('set_time_limit')) {
            set_time_limit(0);
        }

        $hotelRepo = Container::getHotelRepository();
        $synced = 0;
        $skipped = [];

        foreach ($hotelIds as $hotelId) {
            $hotelIdStr = TypeCoerce::toString($hotelId);
            $hotel = $hotelRepo->findById($hotelIdStr);
            if ($hotel === null) {
                $skipped[] = "#{$hotelIdStr}: not found";
                continue;
            }
            $hotelName = TypeCoerce::toString($hotel['name'] ?? '');
            if (empty($hotel['product_id'])) {
                $skipped[] = "{$hotelName}: no linked product";
                continue;
            }

            $productId = TypeCoerce::toInt($hotel['product_id']);
            $imageUrls = [];

            // Prefer images_json (full list) over image_url (single thumbnail)
            $imagesJson = TypeCoerce::toString($hotel['images_json'] ?? '');
            if ($imagesJson !== '' && $imagesJson !== '[]') {
                $images = json_decode($imagesJson, true);
                if (is_array($images)) {
                    foreach ($images as $img) {
                        $url = is_array($img) ? TypeCoerce::toString($img['url'] ?? '') : TypeCoerce::toString($img);
                        if ($url !== '') {
                            $imageUrls[] = $url;
                        }
                    }
                }
            }

            // Fallback to image_url if images_json was empty
            if (empty($imageUrls) && !empty($hotel['image_url'])) {
                $imageUrls[] = TypeCoerce::toString($hotel['image_url']);
            }

            if (empty($imageUrls)) {
                // Fetch fresh data from Sphinx API as fallback
                $api = Container::getApi();
                $fresh = $api->getHotel(TypeCoerce::toString($hotel['hotel_id'] ?? ''));
                if (!empty($fresh['images']) && is_array($fresh['images'])) {
                    foreach ($fresh['images'] as $img) {
                        $url = is_array($img) ? TypeCoerce::toString($img['url'] ?? '') : TypeCoerce::toString($img);
                        if ($url !== '') {
                            $imageUrls[] = $url;
                        }
                    }

                    // Update DB with fresh image data so next sync doesn't need API call
                    if (!empty($imageUrls)) {
                        $hotelRepo->updateImages(
                            TypeCoerce::toString($hotel['hotel_id'] ?? ''),
                            $imageUrls[0],
                            (string) json_encode($fresh['images']),
                        );
                    }
                }

                if (empty($imageUrls)) {
                    $skipped[] = "{$hotelName}: no images available (checked API)";
                    continue;
                }
            }

            $hotelSynced = 0;
            foreach ($imageUrls as $i => $url) {
                $isMain = ($i === 0);
                if (fn_sphinx_holidays_add_product_image($productId, $url, $isMain)) {
                    $hotelSynced++;
                }
            }

            if ($hotelSynced > 0) {
                $synced += $hotelSynced;
            } else {
                $skipped[] = "{$hotelName}: image download failed";
            }
        }

        $msg = TypeCoerce::toString(__('sphinx_holidays.images_synced')) . ': ' . $synced;
        if (!empty($skipped)) {
            $msg .= '<br><br><strong>Skipped:</strong><br>' . implode('<br>', $skipped);
        }
        fn_set_notification($synced > 0 ? 'N' : 'W', $synced > 0 ? __('notice') : __('warning'), $msg);

        return [CONTROLLER_STATUS_REDIRECT, 'sphinx_holidays.hotels'];
    }
}

// ─── AJAX JSON handlers ───

if ($mode === 'get_regions') {
    header('Content-Type: application/json; charset=utf-8');
    $country_code = RequestCoerce::string($_REQUEST, 'country_code');
    if ($country_code === '') {
        echo json_encode(['regions' => []]);
        exit;
    }
    $destRepo = Container::getDestinationRepository();
    $regions = $destRepo->getRegionsByCountry($country_code);
    echo json_encode(['regions' => $regions]);
    exit;
}

if ($mode === 'get_cities') {
    header('Content-Type: application/json; charset=utf-8');
    $region_id = RequestCoerce::int($_REQUEST, 'region_id');
    if ($region_id <= 0) {
        echo json_encode(['cities' => []]);
        exit;
    }
    $destRepo = Container::getDestinationRepository();
    $cities = $destRepo->getCitiesByParent($region_id);
    echo json_encode(['cities' => $cities]);
    exit;
}

if ($mode === 'whitelist_body') {
    // AJAX: one country's regions and cities for the picker, rendered by
    // Travel Core's destination_country_body.tpl.
    $country = strtoupper((string) preg_replace('/[^A-Za-z]/', '', RequestCoerce::string($_REQUEST, 'country')));
    $html = '';
    $dest = $country === '' ? [] : _sphinx_dest_page((new WhitelistPageLoader())->built($country), false);
    foreach (is_array($dest['countries'] ?? null) ? $dest['countries'] : [] as $c) {
        if (is_array($c) && ($c['key'] ?? '') === $country) {
            $view->assign('dest', $dest);
            $view->assign('country', $c);
            $html = TypeCoerce::toString($view->fetch('addons/travel_core/components/destination_country_body.tpl'));
        }
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['html' => $html]);
    exit;
}

if ($mode === 'search_destinations') {
    // AJAX for the picker's search: regions and cities matching q, each with
    // its country, so the picker can load that body and tick the match.
    header('Content-Type: application/json; charset=utf-8');
    $q = trim(RequestCoerce::string($_REQUEST, 'q'));
    $hits = [];
    foreach (strlen($q) < 2 ? [] : Container::getDestinationRepository()->search($q, 30) as $r) {
        $type = TypeCoerce::toString($r['type'] ?? '');
        if (in_array($type, ['region', 'city', 'destination'], true)) {
            $hits[] = [
                'country' => strtoupper(TypeCoerce::toString($r['country_code'] ?? '')),
                ($type === 'region' ? 'group' : 'item') => (string) TypeCoerce::toInt($r['destination_id'] ?? 0),
                'label' => TypeCoerce::toString($r['name'] ?? ''),
                'path' => TypeCoerce::toString($r['full_path'] ?? ''),
            ];
        }
    }
    echo json_encode(['hits' => $hits]);
    exit;
}

if ($mode === 'search_hotels') {
    header('Content-Type: application/json; charset=utf-8');
    $q = trim(RequestCoerce::string($_REQUEST, 'q'));
    if (strlen($q) < 2) {
        echo json_encode(['results' => []]);
        exit;
    }
    $hotelRepo = Container::getHotelRepository();
    $results = $hotelRepo->searchByName($q, 20);
    $formatted = [];
    foreach ($results as $r) {
        $formatted[] = [
            'hotel_id' => $r['hotel_id'],
            'name' => $r['name'],
            'classification' => TypeCoerce::toInt($r['classification'] ?? 0),
            'country_code' => $r['country_code'] ?? '',
            'destination_name' => $r['destination_name'] ?? '',
        ];
    }
    echo json_encode(['results' => $formatted]);
    exit;
}

// ─── GET handlers ───

if ($mode === 'product_links') {
    // Which hotel products cannot be booked, and why: a product with no
    // ?:sphinx_hotels row pointing at it renders as a plain catalog item
    // (no booking form, no location line). The Relink action on this page
    // repairs them — it is POST-only, so this page is its home in the admin.
    $auditor = new \Tygh\Addons\SphinxHolidays\Services\ProductLinkAuditor(
        Container::getHotelRepository(),
    );
    $report = $auditor->report(ConfigProvider::getProductCodePrefix());

    $view->assign('unlinked_sphinx_products', TypeCoerce::toInt($report['total']));
    $view->assign('unlinked_product_rows', $report['rows']);
    $view->assign('is_configured', ConfigProvider::isConfigured());

    return [CONTROLLER_STATUS_OK];
}

if ($mode === 'manage') {
    $destRepo = Container::getDestinationRepository();
    $hotelRepo = Container::getHotelRepository();
    $skipRepo = Container::getHotelSkipRepository();

    // The hotel table: every hotel, those with a product, those waiting for an immediate offer
    // (the availability gate's, not "Retry skipped"'s), those skipped; the rest: Create products.
    $totalHotels = $hotelRepo->getTotal();
    $linkedCount = $hotelRepo->countLinked();
    $skippedCount = $skipRepo->countSkipped();
    $waitingCount = $skipRepo->countSkipped(HotelSkipRepository::SKIP_REASON_NO_AVAILABILITY);
    $selectedCountries = ConfigProvider::getSelectedCountryCodes();

    // The Sphinx catalog line under the Destinations card.
    $view->assign('counts_by_type', $destRepo->getCountsByType());
    $view->assign('dest_last_synced', $destRepo->getLastSyncedAt());
    $view->assign('hotel_last_synced', $hotelRepo->getLastSyncedAt());
    // Fix products: each button shows only when it has something to do.
    $view->assign('unlinked_hotels', $totalHotels - $linkedCount - $skippedCount - $waitingCount);
    $view->assign('skipped_hotels', $skippedCount);
    $view->assign('selected_countries', $selectedCountries);
    $destLoader = new WhitelistPageLoader();
    $destBuilt = $destLoader->built();
    $view->assign('sphinx_dest_card', DestinationsPicker::card($destBuilt, $destLoader->outside(), TypeCoerce::toString(fn_url('sphinx_holidays.whitelist'))));
    $view->assign('sphinx_scope_countries', DashboardSummary::scopeCountries($selectedCountries, $destBuilt['countries']));
    // Its hotels per availability state, from Travel Core's weekly review.
    $view->assign('sphinx_availability_card', \Tygh\Addons\TravelCore\Services\AvailabilityCard::build('sphinx'));
    $view->assign('is_configured', ConfigProvider::isConfigured());
    // Sphinx-shaped products with NO hotel row linked to them — what the
    // relink action actually repairs. (The old gate used the orphan count —
    // hotels whose product was deleted — which relink cannot fix and which is
    // 0 on the far more common "hotel rows lost" store, hiding the button.)
    $view->assign('unlinked_sphinx_products', $hotelRepo->countUnlinkedProducts(ConfigProvider::getProductCodePrefix()));
    // Recent sync activity, All or Problems, paged: $search drives common/pagination.tpl.
    $sphinxActivity = \Tygh\Addons\SphinxHolidays\Services\SyncActivity::page(Container::getSyncLogRepository(), $_REQUEST, time());
    $view->assign('sphinx_activity', $sphinxActivity);
    $view->assign('search', $sphinxActivity['search']);

    // Scheduled jobs: one row per job, in the order they run, from the plan
    // (Travel Core's shared jobs card draws them). The page never prints a
    // command with the key in it: they are shown masked, Copy puts the real
    // one on the clipboard, and Run posts to sphinx_holidays.run_job.
    // The base is the storefront root (config.http_location), as novoton's:
    // fn_url('', 'C') ends in "index.php" on installs without SEO URLs.
    $cron_key = ConfigProvider::getCronAccessKey();
    $base_url = TypeCoerce::toString(\Tygh\Registry::get('config.http_location')) . '/';
    $planner = new CronPlan($base_url, $cron_key, defined('DIR_ROOT') ? TypeCoerce::toString(DIR_ROOT) : '');
    $cron_modes = CronDispatcher::getAvailableModes();
    $cron_records = [];
    foreach (array_keys($cron_modes) as $cron_mode) {
        $cron_records[$cron_mode] = CronRunLog::get(CronPlan::ADDON, $cron_mode);
    }
    $crontab_cli = $planner->crontab($cron_modes, 'cli', date('Y-m-d'));
    $job_stages = $planner->stages($cron_modes, $cron_records, [], time());
    $view->assign('sphinx_job_stages', $job_stages);
    $view->assign('sphinx_on_demand_jobs', $planner->onDemandRows($cron_modes, $cron_records, time()));
    $view->assign('sphinx_cron_has_key', $planner->hasKey());
    $view->assign('sphinx_cron_key', $cron_key);
    $view->assign('sphinx_crontab_cli', $crontab_cli);
    $view->assign('sphinx_crontab_url', $planner->crontab($cron_modes, 'url', date('Y-m-d')));
    $view->assign('sphinx_crontab_masked', $cron_key === '' ? $crontab_cli : str_replace($cron_key, '••••••••', $crontab_cli));

    // At a glance and Needs attention (Services\DashboardSummary), from the
    // figures above, the jobs' runs and whether a cron request was refused
    // for a wrong key since the last good scheduled run (Travel Core's CronHealth).
    $view->assign('sphinx_glance', DashboardSummary::glance(
        ['total' => $totalHotels, 'linked' => $linkedCount, 'waiting' => $waitingCount, 'skipped' => $skippedCount],
        $destBuilt,
        $job_stages,
    ));
    $cron_health = CronHealth::assess($cron_records, CronRunLog::lastWrongKeyRefusal(CronPlan::ADDON), time());
    $view->assign('sphinx_attention', DashboardSummary::attention(
        $job_stages,
        $skippedCount,
        $cron_health['state'] === CronHealth::REFUSED ? $cron_health['wrong_key_at'] : 0,
    ));

} elseif ($mode === 'destinations') {
    $repository = Container::getDestinationRepository();

    $params = [
        'type' => RequestCoerce::string($_REQUEST, 'type'),
        'parent_id' => RequestCoerce::int($_REQUEST, 'parent_id'),
        'q' => RequestCoerce::string($_REQUEST, 'q'),
        'page' => max(1, RequestCoerce::int($_REQUEST, 'page', 1)),
        'items_per_page' => RequestCoerce::int($_REQUEST, 'items_per_page', 50),
    ];

    if (!empty($params['q'])) {
        $items = $repository->search($params['q'], 200);
        $total = count($items);
    } else {
        $result = $repository->getFiltered($params['type'], $params['parent_id'], $params['page'], $params['items_per_page']);
        $items = $result['items'];
        $total = $result['total'];
    }

    $view->assign('destinations', $items);
    $view->assign('search', $params);
    $view->assign('total_items', $total);

} elseif ($mode === 'hotels') {
    $hotelsResult = TypeCoerce::toList(fn_sphinx_holidays_get_hotels($_REQUEST));
    $hotels = $hotelsResult[0] ?? [];
    $search = TypeCoerce::toStringMap($hotelsResult[1] ?? []);

    $hotelRepo = Container::getHotelRepository();
    $distinctCountries = $hotelRepo->getDistinctCountries();
    $distinctClassifications = $hotelRepo->getDistinctClassifications();
    $distinctPropertyTypes = $hotelRepo->getDistinctPropertyTypes();

    // Build sort URL base preserving all current filter params (safe URL encoding)
    $sortFilterParams = array_filter([
        'country_code' => $search['country_code'],
        'region_id' => $search['region_id'] ?: null,
        'destination_id' => $search['destination_id'] ?: null,
        'sync_status' => $search['sync_status'],
        'classification' => $search['classification'],
        'property_type' => $search['property_type'],
        'link_status' => $search['link_status'],
        'availability' => $search['availability'],
        'q' => $search['q'],
        'items_per_page' => $search['items_per_page'],
    ], static fn ($v) => $v !== '' && $v !== null);
    $sortUrlBase = 'sphinx_holidays.hotels?' . http_build_query($sortFilterParams);

    $view->assign('hotels', $hotels);
    $view->assign('search', $search);
    $view->assign('total_items', $search['total_items']);
    $view->assign('sort_url_base', $sortUrlBase);
    $view->assign('distinct_countries', $distinctCountries);
    $view->assign('distinct_classifications', $distinctClassifications);
    $view->assign('distinct_property_types', $distinctPropertyTypes);

} elseif ($mode === 'whitelist') {
    $destRepo = Container::getDestinationRepository();
    $view->assign('counts_by_type', $destRepo->getCountsByType());
    $view->assign('total_destinations', $destRepo->getTotal());
    $view->assign('sphinx_dest_last_synced', $destRepo->getLastSyncedAt());
    $view->assign('sphinx_destinations', _sphinx_dest_page((new WhitelistPageLoader())->built(), true));

} elseif ($mode === 'circuits') {
    $circuitRepo = Container::getCircuitRepository();
    [$circuits, $search] = $circuitRepo->getListing(TypeCoerce::toStringMap($_REQUEST));

    // Preserve active filters when building sort-header links.
    $sortFilterParams = array_filter([
        'transport_type' => $search['transport_type'] ?? '',
        'sync_status'    => $search['sync_status'] ?? '',
        'q'              => $search['q'] ?? '',
        'items_per_page' => $search['items_per_page'] ?? '',
    ], static fn ($v): bool => $v !== '');
    $sortUrlBase = 'sphinx_holidays.circuits?' . http_build_query($sortFilterParams);

    $view->assign('circuits', $circuits);
    $view->assign('search', $search);
    $view->assign('total_items', $search['total_items']);
    $view->assign('sort_url_base', $sortUrlBase);
    $view->assign('last_synced_at', $circuitRepo->getLastSyncedAt());
    $view->assign('total_count', $circuitRepo->countAll());
    // "Sync now" is enabled only when the API is configured.
    $view->assign('is_configured', ConfigProvider::isConfigured());

} elseif ($mode === 'package_routes') {
    $routeRepo = Container::getPackageRouteRepository();
    [$routes, $search] = $routeRepo->getListing(TypeCoerce::toStringMap($_REQUEST));

    $sortFilterParams = array_filter([
        'transport_type' => $search['transport_type'] ?? '',
        'departure'      => $search['departure'] ?? '',
        'arrival'        => $search['arrival'] ?? '',
        'duration'       => $search['duration'] ?? '',
        'items_per_page' => $search['items_per_page'] ?? '',
    ], static fn ($v): bool => $v !== '');
    $sortUrlBase = 'sphinx_holidays.package_routes?' . http_build_query($sortFilterParams);

    $view->assign('package_routes', $routes);
    $view->assign('search', $search);
    $view->assign('total_items', $search['total_items']);
    $view->assign('sort_url_base', $sortUrlBase);
    $view->assign('last_synced_at', $routeRepo->getLastSyncedAt());
    $view->assign('total_count', $routeRepo->countAll());
    // "Sync now" is enabled only when the API is configured.
    $view->assign('is_configured', ConfigProvider::isConfigured());
}
