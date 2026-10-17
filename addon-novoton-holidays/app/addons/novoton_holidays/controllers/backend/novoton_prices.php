<?php
declare(strict_types=1);
/**
 * Novoton Holidays - Prices Controller
 *
 * Price synchronization and management.
 * Split from novoton_holidays.php for maintainability.
 *
 * Modes:
 * - update_prices: Update product prices from API
 * - check_prices: Check which hotels have active prices (resort-based bulk query)
 * - check_prices_hotel: Check which hotels have active prices (per-hotel query)
 * - room_price: Check room prices for hotels
 * - download_active_prices_csv: Download prices CSV
 * - cron_offers_update: Cron job for offers update
 *
 * @package NovotonHolidays
 * @since 2.8.0
 */

use Tygh\Tygh;
use Tygh\Addons\NovotonHolidays\Constants;
use Tygh\Addons\NovotonHolidays\NovotonApi;
use Tygh\Addons\NovotonHolidays\Helpers\PriceCheckFlags;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Addons\NovotonHolidays\Services\Container;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Helpers\RequestCoerce;

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

/**
 * Mode: update_prices
 * Update product prices from Novoton API
 */
if ($mode === 'update_prices') {
    if (!fn_check_permissions('manage_catalog', 'update', 'admin')) {
        return [CONTROLLER_STATUS_DENIED];
    }

    $product_id = RequestCoerce::int($_REQUEST, 'product_id');

    if ($product_id > 0) {
        // Update single product
        $result = fn_novoton_holidays_update_product_prices($product_id);

        if ($result === true) {
            fn_set_notification('N', __('notice'), 'Price updated successfully');
        } elseif ($result === 'no_data') {
            fn_set_notification('W', __('warning'), 'No price data available for this hotel');
        } else {
            fn_set_notification('E', __('error'), 'Failed to update price');
        }

        return [CONTROLLER_STATUS_REDIRECT, 'products.update&product_id=' . $product_id];
    }

    // Batch update
    fn_novoton_holidays_stream_page_open('Updating Product Prices');
    echo '<div class="log">';

    $limit = RequestCoerce::int($_REQUEST, 'limit', 50);

    // Get hotels with products
    $hotelRepo = Container::getInstance()->hotelRepository();
    $hotels = $hotelRepo->findWithProductsSortedByStaleness($limit);

    echo "Updating prices for " . count($hotels) . " products...<br><br>\n";
    flush();

    $updated = 0;
    $failed = 0;
    $no_data = 0;

    foreach ($hotels as $hotel) {
        $hotelProductId = TypeCoerce::toInt($hotel['product_id'] ?? 0);
        $hotelName = TypeCoerce::toString($hotel['hotel_name'] ?? '');
        $result = fn_novoton_holidays_update_product_prices($hotelProductId);

        if ($result === true) {
            echo "<span class='success'>✓ " . htmlspecialchars($hotelName) . "</span><br>\n";
            $updated++;
        } elseif ($result === 'no_data') {
            echo "<span class='warning'>⚠ " . htmlspecialchars($hotelName) . " - No data</span><br>\n";
            $no_data++;
        } else {
            echo "<span class='error'>✗ " . htmlspecialchars($hotelName) . "</span><br>\n";
            $failed++;
        }

        if ($updated > 0 && $updated % 10 === 0) {
            flush();
        }
    }

    echo "<br><strong>Summary:</strong><br>";
    echo "Updated: {$updated}<br>";
    echo "No data: {$no_data}<br>";
    echo "Failed: {$failed}<br>";

    echo '</div>';
    fn_novoton_holidays_stream_page_close();
    exit;
}

/**
 * Mode: check_prices
 * Check which hotels have active prices using resort-based bulk queries.
 *
 * Queries room_price per resort (M API calls where M << N individual hotel
 * calls). Extracts <IdHotel> values via xpath on the parsed XML response.
 */
if ($mode === 'check_prices') {
    if (!fn_check_permissions('manage_catalog', 'update', 'admin')) {
        return [CONTROLLER_STATUS_DENIED];
    }

    // Default dates: the next 7-14 July (PriceCheckFlags::nextPeakCheckIn)
    $default_check_in = PriceCheckFlags::nextPeakCheckIn(new \DateTimeImmutable('today'));
    $default_check_out = date('Y-m-d', (int) strtotime($default_check_in . ' +7 days'));

    // Parse countries: support both multi-select array and legacy single string
    $selected_countries = [];
    $rawCountries = RequestCoerce::list($_REQUEST, 'countries');
    if (!empty($rawCountries)) {
        foreach ($rawCountries as $rc) {
            $selected_countries[] = strtoupper(trim(TypeCoerce::toString($rc)));
        }
    } elseif (!empty($_REQUEST['country'])) {
        $selected_countries = [strtoupper(trim(RequestCoerce::string($_REQUEST, 'country')))];
    }

    $check_in = RequestCoerce::string($_REQUEST, 'check_in', $default_check_in);
    $check_out = RequestCoerce::string($_REQUEST, 'check_out', $default_check_out);
    $run = isset($_REQUEST['run']);

    // All available countries from addon constants
    $available_countries = Constants::COUNTRIES;
    // Pre-select addon's configured countries when nothing is selected yet
    if (empty($selected_countries) && !$run) {
        $selected_countries = ConfigProvider::getSelectedCountries();
    }

    fn_novoton_holidays_stream_page_open('Checking Hotel Prices (Resort-based)');

    // Date / settings form
    $form = fn_novoton_holidays_stream_form_fields(TypeCoerce::toString(fn_url('novoton_holidays.check_prices')));
    echo '<form method="get" action="' . $form['action'] . '">';
    echo $form['hidden_fields'];
    echo '<input type="hidden" name="run" value="1">';
    echo '<div class="form-row">';
    echo '<div class="form-group"><label>Check-in</label><input type="text" name="check_in" value="' . htmlspecialchars($check_in) . '" placeholder="e.g. ' . htmlspecialchars($default_check_in) . '" style="width:130px"></div>';
    echo '<div class="form-group"><label>Check-out</label><input type="text" name="check_out" value="' . htmlspecialchars($check_out) . '" placeholder="e.g. ' . htmlspecialchars($default_check_out) . '" style="width:130px"></div>';
    echo '<div class="form-group"><label>&nbsp;</label><button type="submit" class="btn-run">Check Prices</button></div>';
    echo '</div>';
    echo '<div class="form-group" style="margin-bottom:15px;"><label>Countries</label>';
    echo '<div class="country-checkboxes">';
    foreach ($available_countries as $c) {
        $checked = in_array($c, $selected_countries) ? ' checked' : '';
        $label = ucwords(strtolower($c));
        echo '<label><input type="checkbox" name="countries[]" value="' . htmlspecialchars($c) . '"' . $checked . '> ' . htmlspecialchars($label) . '</label>';
    }
    echo '</div></div>';
    echo '</form>';

    if (!$run || empty($selected_countries)) {
        echo '<p class="hint">Select countries, set check-in / check-out dates and click <strong>Check Prices</strong> to start.<br>';
        echo 'Uses resort-based bulk queries (1 API call per resort instead of 1 per hotel).</p>';
        fn_novoton_holidays_stream_page_close();
        exit;
    }

    echo '<div class="log">';

    $api = new NovotonApi();
    $hotelRepo = Container::getInstance()->hotelRepository();

    $grand_total_hotels = 0;
    $grand_with_prices = 0;
    $grand_no_prices = 0;
    $grand_unknown = 0;
    $grand_resorts = 0;
    $grand_resort_errors = 0;

    // Process each selected country
    foreach ($selected_countries as $country) {
        echo "<div class='country-header'>Country: " . htmlspecialchars($country) . "</div>\n";
        flush();

        // Step 1: Get resort names from resort_list API (the authoritative source).
        $all_hotels = $hotelRepo->findByCountryIndexed($country);
        $total_hotels = count($all_hotels);
        $grand_total_hotels += $total_hotels;

        $resorts = [];
        $resort_list_response = $api->destinations()->getResortList($country);
        if ((bool) $resort_list_response) {
            foreach ($resort_list_response->xpath('//Resort') ?: [] as $r) {
                $name = trim((string)$r);
                if (!empty($name)) {
                    $resorts[] = $name;
                }
            }
        }

        if (empty($resorts)) {
            echo "<span class='error'>resort_list API returned no resorts for " . htmlspecialchars($country) . ". Skipping.</span><br>\n";
            continue;
        }

        sort($resorts);
        $total_resorts = count($resorts);
        $grand_resorts += $total_resorts;

        echo "Resorts: " . (int)$total_resorts . " | Hotels in DB: " . (int)$total_hotels . "<br>";
        echo "Check-in: " . htmlspecialchars($check_in) . " | Check-out: " . htmlspecialchars($check_out) . "<br>\n";
        flush();

        try {
            $hotels_with_prices = [];
            $answered_resorts = [];
            $resort_errors = 0;

            // Step 2: Query each resort in bulk
            foreach ($resorts as $resort_idx => $resort_name) {
                $resort_num = $resort_idx + 1;
                echo "<div class='resort-header'>[{$resort_num}/{$total_resorts}] " . htmlspecialchars($resort_name) . "</div>\n";
                flush();

                try {
                    $xml = $api->pricing()->getRoomPriceByResort([
                        'resort'    => $resort_name,
                        'check_in'  => $check_in,
                        'check_out' => $check_out,
                        'adults'    => 2,
                    ]);

                    $rawResponse = $api->getLastResponse() ?: '';
                    $rawResponseRaw = $api->getLastResponseRaw() ?: '';
                    $response_kb = round(strlen($rawResponse) / 1024, 1);
                    $raw_kb = round(strlen($rawResponseRaw) / 1024, 1);

                    // Show debug info for first resort to help diagnose issues
                    if ($resort_idx === 0) {
                        $rawSnippet = substr($rawResponseRaw, 0, 500);
                        $reqSnippet = substr($api->getLastRequest() ?: '', 0, 500);
                        echo "<details><summary class='debug'>Debug: first resort request/response</summary>";
                        echo "<div style='font-size:11px;background:#f5f5f5;padding:8px;margin:4px 0;'>";
                        echo "<strong>Request (first 500 chars):</strong><br><code>" . htmlspecialchars($reqSnippet) . "</code><br><br>";
                        echo "<strong>Response (first 500 chars, raw {$raw_kb} KB / cleaned {$response_kb} KB):</strong><br><code>" . htmlspecialchars($rawSnippet) . "</code>";
                        echo "</div></details>\n";
                        flush();
                    }

                    if ($xml === false) {
                        $errorDetail = $api->getLastError() ?: 'no error info';
                        echo "<span class='skip'>  Empty/invalid response (cleaned: {$response_kb} KB, raw: {$raw_kb} KB, error: " . htmlspecialchars($errorDetail) . ")</span><br>\n";
                        flush();
                        continue;
                    }

                    // Extract hotel IDs from the parsed XML.
                    // wrapMultiRootResponse() in PricingApiClient ensures all
                    // <room_price> siblings are wrapped under a single root,
                    // so //IdHotel xpath reliably finds all hotel IDs.
                    $resort_hotel_ids = [];
                    $nodes = $xml->xpath('//IdHotel') ?: [];
                    foreach ($nodes as $node) {
                        $val = trim((string)$node);
                        if ($val !== '' && ctype_digit($val)) {
                            $resort_hotel_ids[] = $val;
                        }
                    }
                    $resort_hotel_ids = array_unique($resort_hotel_ids);

                    if (!empty($resort_hotel_ids)) {
                        $count = count($resort_hotel_ids);
                        foreach ($resort_hotel_ids as $hid) {
                            $hotels_with_prices[$hid] = $resort_name;
                        }
                        $answered_resorts[] = $resort_name;
                        echo "<span class='success'>  {$count} hotels with prices ({$response_kb} KB)</span><br>\n";
                    } else {
                        $errorNode = $xml->xpath('//Error') ?: $xml->xpath('//error');
                        if (!empty($errorNode)) {
                            echo "<span class='error'>  API error: " . htmlspecialchars(trim((string)$errorNode[0])) . "</span><br>\n";
                        } else {
                            $answered_resorts[] = $resort_name;
                            echo "<span class='skip'>  No prices ({$response_kb} KB)</span><br>\n";
                        }
                    }

                } catch (\Throwable $e) {
                    echo "<span class='error'>  Error: " . htmlspecialchars($e->getMessage()) . "</span><br>\n";
                    $resort_errors++;
                }

                flush();
            }

            $grand_resort_errors += $resort_errors;

            // Step 4: Update database - mark hotels with/without prices. A hotel
            // whose resort did not answer keeps its flag (PriceCheckFlags).
            echo "<br><div class='resort-header'>Updating database...</div>\n";
            flush();

            $split = PriceCheckFlags::splitByResort($all_hotels, $hotels_with_prices, $answered_resorts);
            Container::getInstance()->databaseHelper()->batchUpdateHasRoomPriceFlag($split['with'], $split['without']);
            $with_prices_count = count($split['with']);
            $no_prices = count($split['without']);

            $grand_with_prices += $with_prices_count;
            $grand_no_prices += $no_prices;

            // Step 5: Report hotels found by API but NOT in our database
            $unknown_hotels = array_diff_key($hotels_with_prices, $all_hotels);
            $grand_unknown += count($unknown_hotels);

            echo "<br><strong>{$country} summary:</strong> ";
            echo "Resorts: {$total_resorts}" . ($resort_errors > 0 ? " ({$resort_errors} errors)" : '') . " | ";
            echo "DB: {$total_hotels} | ";
            echo "With prices: <span class='success'><strong>{$with_prices_count}</strong></span> | ";
            echo "No prices: {$no_prices} | Resort did not answer (unchanged): " . count($split['unanswered']) . "<br>";

            if (!empty($unknown_hotels)) {
                echo "<span class='error'><strong>" . count($unknown_hotels) . " hotels with prices NOT in database:</strong></span><br>";
                foreach ($unknown_hotels as $hid => $resort_name) {
                    echo "<span class='error'>  Hotel ID " . htmlspecialchars((string)$hid) . " (resort: " . htmlspecialchars((string)$resort_name) . ") - not synced</span><br>\n";
                }
            }

            echo "<br>\n";

        } catch (\Throwable $e) {
            echo "<span class='error'>Error: " . htmlspecialchars($e->getMessage()) . "</span><br>";
        }
    }

    // Grand total summary across all countries
    if (count($selected_countries) > 1) {
        echo "<div class='country-header' style='background:#28a745;'>Grand Total (" . count($selected_countries) . " countries)</div>\n";
        echo "Resorts queried: {$grand_resorts}" . ($grand_resort_errors > 0 ? " ({$grand_resort_errors} errors)" : '') . "<br>";
        echo "Hotels in DB: {$grand_total_hotels}<br>";
        echo "With prices: <span class='success'><strong>{$grand_with_prices}</strong></span><br>";
        echo "No prices: {$grand_no_prices}<br>";
        if ($grand_unknown > 0) {
            echo "Unknown hotels (not in DB): <span class='error'><strong>{$grand_unknown}</strong></span><br>";
        }
    }

    echo '</div>';
    fn_novoton_holidays_stream_page_close();
    exit;
}

/**
 * Mode: check_prices_hotel
 * Check which hotels have active prices using hotel-based queries.
 *
 * Unlike check_prices (resort-based), this queries each hotel individually
 * by hotel_id. This ensures we don't miss hotels due to:
 * - Missing or empty city names in database
 * - City name mismatches between database and API
 * - Hotels assigned to unknown resorts
 *
 * Slower (N API calls instead of M calls where M << N), but more accurate.
 * Use this to compare with resort-based results and identify discrepancies.
 */
if ($mode === 'check_prices_hotel') {
    if (!fn_check_permissions('manage_catalog', 'update', 'admin')) {
        return [CONTROLLER_STATUS_DENIED];
    }

    // Default dates: the next 7-14 July (PriceCheckFlags::nextPeakCheckIn)
    $default_check_in = PriceCheckFlags::nextPeakCheckIn(new \DateTimeImmutable('today'));
    $default_check_out = date('Y-m-d', (int) strtotime($default_check_in . ' +7 days'));

    // Parse countries: support both multi-select array and legacy single string
    $selected_countries = [];
    $rawCountries = RequestCoerce::list($_REQUEST, 'countries');
    if (!empty($rawCountries)) {
        foreach ($rawCountries as $rc) {
            $selected_countries[] = strtoupper(trim(TypeCoerce::toString($rc)));
        }
    } elseif (!empty($_REQUEST['country'])) {
        $selected_countries = [strtoupper(trim(RequestCoerce::string($_REQUEST, 'country')))];
    }

    $check_in = RequestCoerce::string($_REQUEST, 'check_in', $default_check_in);
    $check_out = RequestCoerce::string($_REQUEST, 'check_out', $default_check_out);
    $limit = RequestCoerce::int($_REQUEST, 'limit'); // 0 = all hotels
    $run = isset($_REQUEST['run']);

    // All available countries from addon constants
    $available_countries = Constants::COUNTRIES;
    // Pre-select addon's configured countries when nothing is selected yet
    if (empty($selected_countries) && !$run) {
        $selected_countries = ConfigProvider::getSelectedCountries();
    }

    fn_novoton_holidays_stream_page_open('Checking Hotel Prices (Per-Hotel)');

    echo '<div class="info-box">';
    echo '<strong>Hotel-based query:</strong> Queries each hotel by hotel_id individually.<br>';
    echo 'Use this to compare with <a href="' . TypeCoerce::toString(fn_url('novoton_holidays.check_prices')) . '">resort-based check</a> and find discrepancies.<br>';
    echo 'Slower but more accurate - catches hotels with missing/mismatched city names.';
    echo '</div>';

    // Date / settings form
    $form = fn_novoton_holidays_stream_form_fields(TypeCoerce::toString(fn_url('novoton_holidays.check_prices_hotel')));
    echo '<form method="get" action="' . $form['action'] . '">';
    echo $form['hidden_fields'];
    echo '<input type="hidden" name="run" value="1">';
    echo '<div class="form-row">';
    echo '<div class="form-group"><label>Check-in</label><input type="text" name="check_in" value="' . htmlspecialchars($check_in) . '" style="width:130px"></div>';
    echo '<div class="form-group"><label>Check-out</label><input type="text" name="check_out" value="' . htmlspecialchars($check_out) . '" style="width:130px"></div>';
    echo '<div class="form-group"><label>Limit per country (0=all)</label><input type="number" name="limit" value="' . $limit . '" style="width:80px"></div>';
    echo '<div class="form-group"><label>&nbsp;</label><button type="submit" class="btn-run">Check Prices</button></div>';
    echo '</div>';
    echo '<div class="form-group" style="margin-bottom:15px;"><label>Countries</label>';
    echo '<div class="country-checkboxes">';
    foreach ($available_countries as $c) {
        $checked = in_array($c, $selected_countries) ? ' checked' : '';
        $label = ucwords(strtolower($c));
        echo '<label><input type="checkbox" name="countries[]" value="' . htmlspecialchars($c) . '"' . $checked . '> ' . htmlspecialchars($label) . '</label>';
    }
    echo '</div></div>';
    echo '</form>';

    if (!$run || empty($selected_countries)) {
        echo '<p class="hint">Select countries, set check-in / check-out dates and click <strong>Check Prices</strong> to start.<br>';
        echo 'This method queries each hotel individually by hotel_id (slower but complete).</p>';
        fn_novoton_holidays_stream_page_close('', [
            ['url' => TypeCoerce::toString(fn_url('novoton_holidays.check_prices')), 'label' => 'Resort-based Check']
        ]);
        exit;
    }

    echo '<div class="log">';

    $api = new NovotonApi();
    $hotelRepo = Container::getInstance()->hotelRepository();

    $grand_total_hotels = 0;
    $grand_with_prices = 0;
    $grand_no_prices = 0;
    $grand_errors = 0;
    $grand_no_city = 0;
    $start_time = microtime(true);

    foreach ($selected_countries as $country) {
        echo "<div class='country-header'>Country: " . htmlspecialchars($country) . "</div>\n";
        flush();

        // Get all hotels for this country
        $all_hotels = $hotelRepo->findByCountryWithLimit($country, $limit);

        $total_hotels = count($all_hotels);
        $grand_total_hotels += $total_hotels;
        $hotels_with_no_city = 0;
        foreach ($all_hotels as $h) {
            if (empty($h['city'])) {
                $hotels_with_no_city++;
            }
        }
        $grand_no_city += $hotels_with_no_city;

        echo "Total Hotels: " . (int)$total_hotels . " | Empty city: " . (int)$hotels_with_no_city . "<br>";
        echo "Check-in: " . htmlspecialchars($check_in) . " | Check-out: " . htmlspecialchars($check_out) . "<br>\n";
        flush();

        $with_prices = 0;
        $no_prices = 0;
        $errors = 0;
        $now = date('Y-m-d H:i:s');

        foreach ($all_hotels as $idx => $hotel) {
            $hotel_num = $idx + 1;
            $hotel_id = TypeCoerce::toString($hotel['hotel_id'] ?? '');
            $hotel_name = htmlspecialchars(TypeCoerce::toString($hotel['hotel_name'] ?? ''));
            $city = htmlspecialchars(TypeCoerce::toString($hotel['city'] ?? '') ?: '(no city)');

            // Progress indicator every 50 hotels
            if ($hotel_num % 50 === 0 || $hotel_num === 1) {
                $elapsed = round(microtime(true) - $start_time, 1);
                echo "<div class='progress'>[{$hotel_num}/{$total_hotels}] Elapsed: {$elapsed}s | With prices: {$with_prices} | No prices: {$no_prices}</div>\n";
                flush();
            }

            try {
                // Use nocache to get fresh results
                $result = $api->pricing()->getRoomPrice([
                    'hotel_id'  => $hotel_id,
                    'check_in'  => $check_in,
                    'check_out' => $check_out,
                    'adults'    => 2,
                    'nocache'   => true,
                ]);

                // Check if result has any prices
                $has_room_price = false;
                $min_price = 0;
                if ($result instanceof \SimpleXMLElement) {
                    $prices = $result->xpath('//Price');
                    $has_room_price = !empty($prices);
                    if ($has_room_price) {
                        foreach ($prices as $p) {
                            $pval = (float)((string)$p);
                            if ($pval > 0 && ($min_price === 0 || $pval < $min_price)) {
                                $min_price = $pval;
                            }
                        }
                    }
                }

                // Update database. No answer ($result === false) keeps the stored flag (PriceCheckFlags).
                if ($result !== false) {
                    $hotelRepo->update($hotel_id, ['has_room_price' => $has_room_price ? 'Y' : 'N', 'last_price_check' => $now]);
                }

                if ($has_room_price) {
                    $with_prices++;
                    $price_display = $min_price > 0 ? ' | <strong>' . number_format($min_price, 2) . ' EUR</strong>' : '';
                    echo "<span class='success'>✓ [{$hotel_num}] {$hotel_name} ({$city}){$price_display}</span><br>\n";
                } elseif ($result === false) {
                    $errors++;
                    if ($errors <= 20) {
                        echo "<span class='skip'>○ [{$hotel_num}] {$hotel_name} ({$city}) - invalid API response, flag unchanged</span><br>\n";
                    } elseif ($errors === 21) {
                        echo "<span class='skip'>... (more invalid responses)</span><br>\n";
                    }
                } else {
                    $no_prices++;
                    if ($no_prices <= 20) {
                        echo "<span class='skip'>○ [{$hotel_num}] {$hotel_name} ({$city}) - no prices</span><br>\n";
                    } elseif ($no_prices === 21) {
                        echo "<span class='skip'>... (more hotels without prices)</span><br>\n";
                    }
                }

            } catch (\Throwable $e) {
                $errors++;
                echo "<span class='error'>✗ [{$hotel_num}] {$hotel_name}: " . htmlspecialchars($e->getMessage()) . "</span><br>\n";
            }

            // Flush periodically
            if ($hotel_num % 10 === 0) {
                flush();
            }
        }

        $grand_with_prices += $with_prices;
        $grand_no_prices += $no_prices;
        $grand_errors += $errors;

        echo "<br><strong>{$country} summary:</strong> ";
        echo "Hotels: {$total_hotels} | Empty city: {$hotels_with_no_city} | ";
        echo "With prices: <span class='success'><strong>{$with_prices}</strong></span> | ";
        echo "No prices: {$no_prices} | Errors: {$errors}<br><br>\n";
    }

    $elapsed = round(microtime(true) - $start_time, 1);

    // Grand total summary across all countries
    if (count($selected_countries) > 1) {
        echo "<div class='country-header' style='background:#28a745;'>Grand Total (" . count($selected_countries) . " countries)</div>\n";
        echo "Hotels checked: {$grand_total_hotels}<br>";
        echo "Hotels with empty city: {$grand_no_city}<br>";
        echo "With prices: <span class='success'><strong>{$grand_with_prices}</strong></span><br>";
        echo "No prices: {$grand_no_prices}<br>";
        echo "Errors: {$grand_errors}<br>";
    } else {
        echo "<strong>Total:</strong> ";
        echo "Hotels: {$grand_total_hotels} | Empty city: {$grand_no_city} | ";
        echo "With prices: <span class='success'><strong>{$grand_with_prices}</strong></span> | ";
        echo "No prices: {$grand_no_prices} | Errors: {$grand_errors}<br>";
    }
    echo "Time: {$elapsed}s<br>";
    echo "<br><strong>Compare this with resort-based check to see discrepancies.</strong><br>";

    echo '</div>';
    fn_novoton_holidays_stream_page_close('', [
        ['url' => TypeCoerce::toString(fn_url('novoton_holidays.check_prices')), 'label' => 'Resort-based Check']
    ]);
    exit;
}

/**
 * Mode: room_price
 * Check room prices for specific hotel
 */
if ($mode === 'room_price') {
    if (!fn_check_permissions('manage_catalog', 'update', 'admin')) {
        return [CONTROLLER_STATUS_DENIED];
    }

    $hotel_id = RequestCoerce::string($_REQUEST, 'hotel_id');
    $check_in = RequestCoerce::string($_REQUEST, 'check_in', date('Y-m-d', strtotime('+' . Constants::DEFAULT_CHECKIN_DAYS_AHEAD . ' days')));
    $check_out = RequestCoerce::string($_REQUEST, 'check_out', date('Y-m-d', strtotime('+' . (Constants::DEFAULT_CHECKIN_DAYS_AHEAD + Constants::DEFAULT_STAY_NIGHTS) . ' days')));

    /** @var \Smarty $view */
    $view = Tygh::$app['view'];
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
                'children' => RequestCoerce::list($_REQUEST, 'children')
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

/**
 * Mode: download_active_prices_csv
 * Download CSV with hotels that have active prices
 */
if ($mode === 'download_active_prices_csv') {
    if (!fn_check_permissions('manage_catalog', 'view', 'admin')) {
        return [CONTROLLER_STATUS_DENIED];
    }

    $country = (string) preg_replace('/[^A-Z]/', '', strtoupper(RequestCoerce::string($_REQUEST, 'country', 'BULGARIA')));

    $hotelRepo = Container::getInstance()->hotelRepository();
    $hotels = $hotelRepo->findWithPricesForExport($country);

    $csv = "Hotel ID;Hotel Name;City;Hotel Type;Product ID;Last Check\n";

    foreach ($hotels as $hotel) {
        $csv .= implode(';', [
            TypeCoerce::toString($hotel['hotel_id'] ?? ''),
            '"' . str_replace('"', '""', TypeCoerce::toString($hotel['hotel_name'] ?? '')) . '"',
            TypeCoerce::toString($hotel['city'] ?? ''),
            TypeCoerce::toString($hotel['hotel_type'] ?? ''),
            TypeCoerce::toString($hotel['product_id'] ?? ''),
            TypeCoerce::toString($hotel['last_price_check'] ?? '')
        ]) . "\n";
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="novoton_active_prices_' . strtolower($country) . '_' . date('Y-m-d') . '.csv"');
    echo $csv;
    exit;
}

/**
 * Mode: cron_offers_update
 * Cron job to update offers/prices
 */
if ($mode === 'cron_offers_update') {
    // Verify access key
    $access_key = RequestCoerce::string($_REQUEST, 'access_key');
    $expected_key = ConfigProvider::getCronAccessKey();

    if (empty($expected_key) || !hash_equals($expected_key, $access_key)) {
        header('HTTP/1.1 403 Forbidden');
        echo 'Invalid access key';
        exit;
    }

    header('Content-Type: text/plain; charset=utf-8');

    echo "=== NOVOTON OFFERS UPDATE ===\n";
    echo "Started: " . date('Y-m-d H:i:s') . "\n\n";

    try {
        $api = new NovotonApi();
        $hotelRepo = Container::getInstance()->hotelRepository();
        $syncLogRepo = Container::getInstance()->syncLogRepository();

        $updated = 0;
        $errors = 0;
        $start_time = time();

        // Get hotels that need price update
        $hotels = $hotelRepo->findNeedingPriceUpdate(24, 100);

        echo "Processing " . count($hotels) . " hotels\n\n";

        foreach ($hotels as $hotel) {
            $hotelProductId = TypeCoerce::toInt($hotel['product_id'] ?? 0);
            $hotelName = TypeCoerce::toString($hotel['hotel_name'] ?? '');
            $hotelId = TypeCoerce::toString($hotel['hotel_id'] ?? '');
            try {
                if ($hotelProductId > 0) {
                    $result = fn_novoton_holidays_update_product_prices($hotelProductId);
                    if ($result === true) {
                        $updated++;
                        echo "✓ {$hotelName}\n";
                    } else {
                        echo "○ {$hotelName} (no data)\n";
                    }
                } else {
                    // Just update the price check timestamp
                    $hotelRepo->update($hotelId, ['last_price_check' => date('Y-m-d H:i:s')]);
                }
            } catch (\Throwable $e) {
                $errors++;
                echo "✗ {$hotelName}: " . $e->getMessage() . "\n";
            }
        }

        $duration = time() - $start_time;

        // Log sync
        $syncLogRepo->logSync('offers_update', count($hotels), $updated, $errors, $duration);

        echo "\n=== SUMMARY ===\n";
        echo "Updated: {$updated}\n";
        echo "Errors: {$errors}\n";
        echo "Duration: {$duration}s\n";
        echo "Completed: " . date('Y-m-d H:i:s') . "\n";

    } catch (\Throwable $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
    }

    exit;
}
