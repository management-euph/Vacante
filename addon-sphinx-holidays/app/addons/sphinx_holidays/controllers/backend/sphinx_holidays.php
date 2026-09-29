<?php

declare(strict_types=1);
/**
 * Sphinx Holidays - Backend Admin Controller
 *
 * Modes:
 *   - manage (default): Dashboard with destination + hotel stats, sync controls
 *   - destinations: List/filter destinations with pagination
 *   - hotels: List/filter hotels with pagination
 *   - sync_destinations (POST): Trigger destination sync from admin panel
 *   - sync_hotels (POST): Trigger hotel sync from admin panel
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
use Tygh\Addons\SphinxHolidays\Services\CircuitSyncService;
use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;
use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\SphinxHolidays\Services\DestinationsPicker;
use Tygh\Addons\SphinxHolidays\Services\DestinationSyncService;
use Tygh\Addons\SphinxHolidays\Services\HotelSyncService;
use Tygh\Addons\SphinxHolidays\Services\PackageRouteSyncService;
use Tygh\Addons\SphinxHolidays\Services\WhitelistPageLoader;
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
// e.g. dispatch=sphinx_holidays.sync_destinations sets $mode = 'sync_destinations'

// ─── POST handlers ───

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($mode === 'sync_destinations') {
        if (!ConfigProvider::isConfigured()) {
            fn_set_notification('E', __('error'), __('sphinx_holidays.api_not_configured'));
            return [CONTROLLER_STATUS_REDIRECT, 'sphinx_holidays.manage'];
        }

        if (function_exists('set_time_limit')) {
            set_time_limit(0);
        }

        $api = Container::getApi();
        $repository = Container::getDestinationRepository();
        $service = new DestinationSyncService($api, $repository);

        $result = $service->sync();

        if (!empty($result['success'])) {
            fn_set_notification('N', __('notice'), TypeCoerce::toString(__('sphinx_holidays.sync_completed')) . ': ' . TypeCoerce::toInt($result['synced']) . '/' . TypeCoerce::toInt($result['total']));
        } else {
            fn_set_notification('E', __('error'), TypeCoerce::toString(__('sphinx_holidays.sync_failed')) . ': ' . (TypeCoerce::toString($result['error']) ?: 'Unknown error'));
        }

        return [CONTROLLER_STATUS_REDIRECT, 'sphinx_holidays.manage'];
    }

    if ($mode === 'sync_hotels') {
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

        // The whitelist's destination ids too: with country codes alone the
        // service syncs every destination of an "Only selected" country.
        $result = $service->sync(ConfigProvider::getSelectedCountryCodes(), ConfigProvider::getAllowedDestinationIds());

        if (!empty($result['success'])) {
            fn_set_notification('N', __('notice'), TypeCoerce::toString(__('sphinx_holidays.hotel_sync_completed')) . ': ' . TypeCoerce::toInt($result['synced']) . '/' . TypeCoerce::toInt($result['total']));
        } else {
            fn_set_notification('E', __('error'), TypeCoerce::toString(__('sphinx_holidays.hotel_sync_failed')) . ': ' . (TypeCoerce::toString($result['error']) ?: 'Unknown error'));
        }

        return [CONTROLLER_STATUS_REDIRECT, 'sphinx_holidays.manage'];
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

    // Destination stats
    $countsByType = $destRepo->getCountsByType();
    $totalDestinations = $destRepo->getTotal();
    $destLastSynced = $destRepo->getLastSyncedAt();

    // Hotel stats
    $totalHotels = $hotelRepo->getTotal();
    $hotelsByCountry = $hotelRepo->getCountsByCountry();
    $hotelLastSynced = $hotelRepo->getLastSyncedAt();

    // Product stats
    $linkedCount = $hotelRepo->countLinked();
    $skippedCount = Container::getHotelSkipRepository()->countSkipped();
    $unlinkedCount = $totalHotels - $linkedCount - $skippedCount;

    // API status
    $isConfigured = ConfigProvider::isConfigured();
    $selectedCountries = ConfigProvider::getSelectedCountryCodes();

    // Recent sync logs (both types)
    $syncLogRepo = Container::getSyncLogRepository();
    $syncLogs = $syncLogRepo->getRecent(10);

    $view->assign('counts_by_type', $countsByType);
    $view->assign('total_destinations', $totalDestinations);
    $view->assign('dest_last_synced', $destLastSynced);
    $view->assign('total_hotels', $totalHotels);
    $view->assign('hotels_by_country', $hotelsByCountry);
    $view->assign('hotel_last_synced', $hotelLastSynced);
    $view->assign('linked_products', $linkedCount);
    $view->assign('unlinked_hotels', $unlinkedCount);
    $view->assign('skipped_hotels', $skippedCount);
    $view->assign('selected_countries', $selectedCountries);
    $destLoader = new WhitelistPageLoader();
    $view->assign('sphinx_dest_card', DestinationsPicker::card($destLoader->built(), $destLoader->outside(), TypeCoerce::toString(fn_url('sphinx_holidays.whitelist'))));
    $view->assign('is_configured', $isConfigured);
    // Sphinx-shaped products with NO hotel row linked to them — what the
    // relink action actually repairs. (The old gate used the orphan count —
    // hotels whose product was deleted — which relink cannot fix and which is
    // 0 on the far more common "hotel rows lost" store, hiding the button.)
    $view->assign('unlinked_sphinx_products', $hotelRepo->countUnlinkedProducts(
        ConfigProvider::getProductCodePrefix(),
    ));
    // Hotels with a dead product_id (product deleted from CS-Cart without clearing the link)
    $view->assign('orphaned_spx_products', $hotelRepo->countOrphanedProducts());

    $view->assign('sync_logs', $syncLogs);

    // Cron URLs for the dashboard.
    // Use the storefront root (config.http_location) — same as novoton's
    // dashboard. fn_url('', 'C') returns a URL that already ends in
    // "index.php" on installs without SEO clean-URLs, so appending
    // "index.php?..." below produced a broken "index.phpindex.php".
    $cron_key = ConfigProvider::getCronAccessKey();
    $base_url = TypeCoerce::toString(\Tygh\Registry::get('config.http_location')) . '/';
    $cron_urls = [
        'destinations' => $base_url . "index.php?dispatch=sphinx_cron.run&access_key={$cron_key}&cron_mode=destinations",
        'hotels' => $base_url . "index.php?dispatch=sphinx_cron.run&access_key={$cron_key}&cron_mode=hotels",
        'add_products' => $base_url . "index.php?dispatch=sphinx_cron.run&access_key={$cron_key}&cron_mode=add_products",
        'add_circuit_products' => $base_url . "index.php?dispatch=sphinx_cron.run&access_key={$cron_key}&cron_mode=add_circuit_products",
        'package_routes' => $base_url . "index.php?dispatch=sphinx_cron.run&access_key={$cron_key}&cron_mode=package_routes",
        'circuits' => $base_url . "index.php?dispatch=sphinx_cron.run&access_key={$cron_key}&cron_mode=circuits",
        'experiences' => $base_url . "index.php?dispatch=sphinx_cron.run&access_key={$cron_key}&cron_mode=experiences",
        'order_status' => $base_url . "index.php?dispatch=sphinx_cron.run&access_key={$cron_key}&cron_mode=order_status",
        'cache_refresh' => $base_url . "index.php?dispatch=sphinx_cron.run&access_key={$cron_key}&cron_mode=cache_refresh",
        'cleanup' => $base_url . "index.php?dispatch=sphinx_cron.run&access_key={$cron_key}&cron_mode=cleanup",
        'discover_boards' => $base_url . "index.php?dispatch=sphinx_cron.run&access_key={$cron_key}&cron_mode=discover_boards",
        'assign_boards' => $base_url . "index.php?dispatch=sphinx_cron.run&access_key={$cron_key}&cron_mode=assign_boards",
        'update_products' => $base_url . "index.php?dispatch=sphinx_cron.run&access_key={$cron_key}&cron_mode=update_products",
        'reassign_features' => $base_url . "index.php?dispatch=sphinx_cron.run&access_key={$cron_key}&cron_mode=reassign_features",
        'enrich_hotel_data' => $base_url . "index.php?dispatch=sphinx_cron.run&access_key={$cron_key}&cron_mode=enrich_hotel_data",
        'backfill_hotel_locations' => $base_url . "index.php?dispatch=sphinx_cron.run&access_key={$cron_key}&cron_mode=backfill_hotel_locations",
        'geocode_hotels' => $base_url . "index.php?dispatch=sphinx_cron.run&access_key={$cron_key}&cron_mode=geocode_hotels",
        'sync_images' => $base_url . "index.php?dispatch=sphinx_cron.run&access_key={$cron_key}&cron_mode=sync_images",
        'process_image_queue' => $base_url . "index.php?dispatch=sphinx_cron.run&access_key={$cron_key}&cron_mode=process_image_queue",
        'sync_and_upload_images' => $base_url . "index.php?dispatch=sphinx_cron.run&access_key={$cron_key}&cron_mode=sync_and_upload_images",
        'diagnose_search' => $base_url . "index.php?dispatch=sphinx_cron.run&access_key={$cron_key}&cron_mode=diagnose_search",
        'diagnose_images' => $base_url . "index.php?dispatch=sphinx_cron.run&access_key={$cron_key}&cron_mode=diagnose_images",
        'diagnose_seo' => $base_url . "index.php?dispatch=sphinx_cron.run&access_key={$cron_key}&cron_mode=diagnose_seo",
    ];
    $view->assign('cron_urls', $cron_urls);
    $view->assign('cron_key', $cron_key);

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
}
