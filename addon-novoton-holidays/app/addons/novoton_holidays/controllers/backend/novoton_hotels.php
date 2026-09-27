<?php
declare(strict_types=1);
/**
 * Novoton Holidays - Hotels Sub-Controller
 *
 * Delegated from novoton_holidays.php for modes that stream output or redirect.
 * Template-rendering modes (list_facilities) live in the main controller for
 * proper CS-Cart template resolution.
 *
 * Modes handled here (all exit() or return redirect):
 * - sync_facilities: Sync facilities from API (redirect)
 * - sync_hotel_facilities: Sync hotel facilities (redirect)
 * - save_facilities: Save facility classifications (redirect)
 * - check_packages: Check hotel packages from API (streaming + exit)
 *
 * @package NovotonHolidays
 * @since 2.8.0
 */

use Tygh\Addons\NovotonHolidays\NovotonApi;
use Tygh\Addons\NovotonHolidays\Services\Container;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Helpers\RequestCoerce;

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

/**
 * Mode: sync (removed)
 * Hotel sync is now handled exclusively by the cron Hotel List Sync:
 *   dispatch=novoton_cron.run&access_key=KEY&mode=hotel_list
 * The cron version saves more complete data (region, lat/lng, proper timestamps).
 */

// NOTE: list_facilities template mode is handled by novoton_holidays.php (main controller).
// Hotel-to-product import is handled exclusively by the cron:
//   dispatch=novoton_cron.run&access_key=KEY&mode=add_hotels_as_products
// (or the dashboard "Run" button, which calls the same AddProductsCommand).

/**
 * Mode: sync_facilities
 * Sync facilities from API
 */
if ($mode === 'sync_facilities') {
    if (!fn_check_permissions('manage_catalog', 'update', 'admin')) {
        return [CONTROLLER_STATUS_DENIED];
    }

    $result = fn_novoton_holidays_sync_facilities_list();

    if (TypeCoerce::toBool($result['success'])) {
        $added = TypeCoerce::toInt($result['added']);
        $updated = TypeCoerce::toInt($result['updated']);
        fn_set_notification('N', __('notice'), "Facilities synced! Added: {$added}, Updated: {$updated}");
    } else {
        fn_set_notification('E', __('error'), $result['error'] ?? 'Sync failed');
    }

    return [CONTROLLER_STATUS_REDIRECT, 'novoton_holidays.list_facilities'];
}

/**
 * Mode: sync_hotel_facilities
 * Populate novoton_hotel_facilities junction table by calling the API for each hotel
 */
if ($mode === 'sync_hotel_facilities') {
    if (!fn_check_permissions('manage_catalog', 'update', 'admin')) {
        return [CONTROLLER_STATUS_DENIED];
    }

    $hotelRepo = Container::getInstance()->hotelRepository();
    $hotel_ids = $hotelRepo->getAllIds();
    $synced = 0;
    $failed = 0;

    foreach ($hotel_ids as $hid) {
        $ok = fn_novoton_holidays_sync_hotel_facilities($hid);
        if ($ok) {
            $synced++;
        } else {
            $failed++;
        }
    }

    fn_set_notification('N', __('notice'), "Hotel facilities synced for {$synced} hotels. Failed: {$failed}.");

    return [CONTROLLER_STATUS_REDIRECT, 'novoton_holidays.list_facilities'];
}

/**
 * Mode: save_facilities
 * Save facility feature type mappings and Romanian translations from admin form
 */
if ($mode === 'save_facilities') {
    if (!fn_check_permissions('manage_catalog', 'update', 'admin')) {
        return [CONTROLLER_STATUS_DENIED];
    }

    $facility_types_raw = $_REQUEST['facility_types'] ?? [];
    $facility_types = is_array($facility_types_raw) ? $facility_types_raw : [];
    $facility_translations_raw = $_REQUEST['facility_translations'] ?? [];
    $facility_translations = is_array($facility_translations_raw) ? $facility_translations_raw : [];
    $allowed = [
        \Tygh\Addons\NovotonHolidays\Constants::FEATURE_TYPE_HOTEL_FACILITY,
        \Tygh\Addons\NovotonHolidays\Constants::FEATURE_TYPE_ROOM_FACILITY,
        \Tygh\Addons\NovotonHolidays\Constants::FEATURE_TYPE_TRAVEL_GROUP,
        \Tygh\Addons\NovotonHolidays\Constants::FEATURE_TYPE_BEACH_ACCESS,
    ];
    $updated = 0;
    $facilityRepo = Container::getInstance()->facilityRepository();

    foreach ($facility_types as $facility_id => $type) {
        $facility_id = (int) $facility_id;
        if ($facility_id <= 0 || !in_array($type, $allowed, true)) {
            continue;
        }

        $name_ro = isset($facility_translations[$facility_id])
            ? TypeCoerce::toString($facility_translations[$facility_id])
            : null;

        $facilityRepo->updateTypeAndTranslation($facility_id, $type, $name_ro);
        $updated++;
    }

    fn_set_notification('N', __('notice'), "Facilities saved ({$updated} updated).");

    return [CONTROLLER_STATUS_REDIRECT, 'novoton_holidays.list_facilities'];
}

/**
 * Mode: check_packages
 * Check hotel packages from API (hotelinfo) for all countries
 */
if ($mode === 'check_packages') {
    if (!fn_check_permissions('manage_catalog', 'update', 'admin')) {
        return [CONTROLLER_STATUS_DENIED];
    }

    $limit = max(1, min(2000, RequestCoerce::int($_REQUEST, 'limit', 500)));
    $run = isset($_REQUEST['run']);

    fn_novoton_holidays_stream_page_open('Check Hotel Packages');
    echo '<p class="hint">Retrieves <code>&lt;PackageName&gt;</code> from <code>hotelinfo</code> API for all hotels across all countries.</p>';

    // Form
    $form = fn_novoton_holidays_stream_form_fields(TypeCoerce::toString(fn_url('novoton_holidays.check_packages')));
    echo '<form method="get" action="' . $form['action'] . '">';
    echo $form['hidden_fields'];
    echo '<input type="hidden" name="run" value="1">';
    echo '<div class="form-row">';
    echo '<div class="form-group"><label>Limit per country</label><input type="number" name="limit" value="' . $limit . '" min="1" max="2000" style="width:80px"></div>';
    echo '<div class="form-group"><label>&nbsp;</label><button type="submit" class="btn-run">Check Packages</button></div>';
    echo '</div></form>';

    if (!$run) {
        echo '<p class="hint">Click <strong>Check Packages</strong> to retrieve package names from the API for all countries.</p>';
        fn_novoton_holidays_stream_page_close();
        exit;
    }

    echo '<div class="log">';

    // Get all countries from settings
    $countries = fn_novoton_holidays_parse_countries();

    echo "Countries: " . implode(', ', $countries) . "<br>";
    echo "Limit per country: {$limit}<br>";
    echo "Started: " . date('Y-m-d H:i:s') . "<br><br>\n";
    flush();

    try {
        $api = new NovotonApi();

        $grand_total = 0;
        $with_packages = 0;
        $without_packages = 0;
        $errors = 0;
        $results_table = [];

        $hotelRepo = Container::getInstance()->hotelRepository();
        $packageRepo = Container::getInstance()->hotelPackageRepository();

        foreach ($countries as $country) {
            // V3: Select hotels without package_name column (it's now in novoton_hotel_packages)
            $hotels = $hotelRepo->findByCountryWithLimit($country, $limit);

            if (empty($hotels)) {
                echo "<div class='country-header'>{$country}: 0 hotels in DB</div>\n";
                continue;
            }

            echo "<div class='country-header'>{$country}: " . count($hotels) . " hotels</div>\n";
            flush();

            foreach ($hotels as $hotel) {
                $grand_total++;
                $hotel_id = TypeCoerce::toString($hotel['hotel_id'] ?? '');
                $hotel_name = TypeCoerce::toString($hotel['hotel_name'] ?? '');

                try {
                    $hotel_info = $api->hotels()->getHotelInfo($hotel_id);

                    // V3: Extract all packages from hotelinfo
                    $packages = [];
                    if ((bool) $hotel_info && isset($hotel_info->packages)) {
                        // Single package format
                        if (isset($hotel_info->packages->IdCont)) {
                            $packages[] = [
                                'id' => (string)$hotel_info->packages->IdCont,
                                'name' => (string)($hotel_info->packages->PackageName ?? '')
                            ];
                        }
                        // Multiple packages format
                        if (isset($hotel_info->packages->package)) {
                            foreach ($hotel_info->packages->package as $pkg) {
                                $packages[] = [
                                    'id' => (string)($pkg->IdCont ?? ''),
                                    'name' => (string)($pkg->PackageName ?? '')
                                ];
                            }
                        }
                    }

                    if (!empty($packages)) {
                        $with_packages++;
                        $pkg_names = [];

                        // V3: Store packages in novoton_hotel_packages table
                        foreach ($packages as $pkg) {
                            if (!empty($pkg['id'])) {
                                $packageRepo->upsertByHotelAndPackage($hotel_id, $pkg['id'], $pkg['name']);
                                $pkg_names[] = $pkg['name'];
                            }
                        }

                        // Update hotel packages_count (has_room_price is set exclusively by room_price check)
                        $hotelRepo->updatePackagesCount($hotel_id, count($packages));

                        $display_pkgs = implode(', ', array_slice($pkg_names, 0, 2)) . (count($pkg_names) > 2 ? '...' : '');
                        echo "<span class='success'>&check; NVT-{$hotel_id} | {$hotel_name} &rarr; " . count($packages) . " pkg: " . htmlspecialchars($display_pkgs) . "</span><br>\n";

                        $results_table[] = [
                            'hotel_id' => $hotel_id,
                            'hotel_name' => $hotel_name,
                            'package_name' => implode(', ', $pkg_names),
                            'country' => $country
                        ];
                    } else {
                        $without_packages++;
                        echo "<span class='skip'>&cir; NVT-{$hotel_id} | {$hotel_name} - No packages</span><br>\n";
                    }
                } catch (Exception $e) {
                    $errors++;
                    echo "<span class='error'>&cross; NVT-{$hotel_id} | {$hotel_name} - Error: " . htmlspecialchars($e->getMessage()) . "</span><br>\n";
                }

                if ($grand_total % 25 === 0) {
                    echo "<div class='progress'>Progress: {$grand_total} hotels checked ({$with_packages} with packages)...</div>\n";
                    flush();
                }

                usleep(\Tygh\Addons\NovotonHolidays\Constants::API_DELAY_NORMAL);
            }
        }

        echo "<br><strong>Summary:</strong><br>";
        echo "With packages: {$with_packages}<br>";
        echo "Without packages: {$without_packages}<br>";
        echo "Errors: {$errors}<br>";
        echo "Total checked: {$grand_total}<br>";
        echo "Completed: " . date('Y-m-d H:i:s') . "<br>";

        // Show results table if any
        if (!empty($results_table)) {
            echo '</div>';
            echo '<h3 style="margin-top:20px; color:#003580;">Hotels with Packages (' . count($results_table) . ')</h3>';
            echo '<table class="summary-table">';
            echo '<tr><th>#</th><th>Hotel ID</th><th>Hotel Name</th><th>Package Name</th><th>Country</th></tr>';
            $idx = 0;
            foreach ($results_table as $row) {
                $idx++;
                echo '<tr>';
                echo '<td>' . $idx . '</td>';
                echo '<td>NVT-' . htmlspecialchars($row['hotel_id']) . '</td>';
                echo '<td>' . htmlspecialchars($row['hotel_name']) . '</td>';
                echo '<td><strong>' . htmlspecialchars($row['package_name']) . '</strong></td>';
                echo '<td>' . htmlspecialchars($row['country']) . '</td>';
                echo '</tr>';
            }
            echo '</table>';
        } else {
            echo '</div>';
        }

    } catch (Exception $e) {
        echo "<span class='error'>Error: " . htmlspecialchars($e->getMessage()) . "</span><br>";
        echo '</div>';
    }

    fn_novoton_holidays_stream_page_close();
    exit;
}
