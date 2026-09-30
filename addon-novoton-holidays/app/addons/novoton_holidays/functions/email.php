<?php
declare(strict_types=1);
/**
 * Novoton Holidays - Email Functions
 * 
 * Functions for email notifications and reports.
 * 
 * @package NovotonHolidays
 * @since 2.8.0
 */

use Tygh\Registry;
use Tygh\Tygh;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

/** Star-rating label fallbacks by locale. */
const NOVOTON_STAR_LABELS = [
    'ro' => ['1 stea', '2 stele', '3 stele', '4 stele', '5 stele'],
    'en' => ['1 star', '2 stars', '3 stars', '4 stars', '5 stars'],
];

/**
 * Escape a CSV field to prevent formula injection.
 *
 * Values starting with =, +, -, @, tab or carriage-return are prefixed
 * with a single-quote so spreadsheet applications treat them as text.
 * The field is also double-quote-wrapped with inner quotes escaped.
 *
 * @param string $value Raw field value
 * @return string Safe, quoted CSV field
 */
function fn_novoton_holidays_csv_escape(string $value): string
{
    // Neutralise formula injection characters
    if ($value !== '' && preg_match('/^[=+\-@\t\r]/', $value) === 1) {
        $value = "'" . $value;
    }
    return '"' . str_replace('"', '""', $value) . '"';
}

/**
 * Generate CSV report from import results
 *
 * @param list<array<string, mixed>> $results Array of import results
 * @param string $import_type Type of import (manual/cron)
 * @param array<string, mixed> $summary Summary statistics
 * @return string CSV content
 */
function fn_novoton_holidays_generate_import_csv_report($results, $import_type = 'manual', $summary = []): string
{
    $csv_lines = [];

    // Header row
    $csv_lines[] = implode(';', [
        'Hotel ID',
        'Hotel Name',
        'Action',
        'Product ID',
        'Star Rating',
        'Description',
        'Facilities',
        'Error',
        'Timestamp'
    ]);

    $pif = \Tygh\Addons\NovotonHolidays\Services\PriceInfoFormatter::class;
    // Data rows
    foreach ($results as $row) {
        $csv_lines[] = implode(';', [
            fn_novoton_holidays_csv_escape($pif::toScalar($row['hotel_id'] ?? '')),
            fn_novoton_holidays_csv_escape($pif::toScalar($row['hotel_name'] ?? '')),
            fn_novoton_holidays_csv_escape($pif::toScalar($row['action'] ?? '')),
            fn_novoton_holidays_csv_escape($pif::toScalar($row['product_id'] ?? '')),
            fn_novoton_holidays_csv_escape($pif::toScalar($row['star_rating'] ?? '')),
            fn_novoton_holidays_csv_escape($pif::toScalar($row['description'] ?? '')),
            fn_novoton_holidays_csv_escape($pif::toScalar($row['facilities'] ?? '')),
            fn_novoton_holidays_csv_escape($pif::toScalar($row['error'] ?? '')),
            fn_novoton_holidays_csv_escape($pif::toScalar($row['timestamp'] ?? date('Y-m-d H:i:s')))
        ]);
    }

    return implode("\n", $csv_lines);
}

/**
 * Send cron/import report email via CS-Cart Mailer
 *
 * Uses the 'novoton_holidays_import_report' email template registered in addon.xml.
 * Works for all cron job types — hotel_list, hotel_info, room_price, etc.
 *
 * @param array<string, mixed>  $results     Optional detailed results for CSV attachment (empty = no attachment)
 * @param string $import_type Type identifier: hotel_list, hotel_info, room_price, offers_update, add_products, facilities, resinfo, manual
 * @param array<string, mixed>  $summary     Summary statistics: added, updated, skipped, errors, duration, plus any extra keys
 * @param string $country     Country code or comma-separated list (empty = shown as ALL)
 * @param string $detail_log  Full plain-text run log (the same lines the cron
 *                            prints) — rendered in the email's Details section
 *                            and persisted as the per-run report file
 * @return bool Success
 */
function fn_novoton_holidays_send_import_report_email($results, $import_type, $summary, $country = '', $detail_log = ''): bool
{
    // Respect the admin setting to enable/disable cron report email notifications
    if (!\Tygh\Addons\NovotonHolidays\Services\ConfigProvider::isCronReportEmailEnabled()) {
        return false;
    }

    $pif = \Tygh\Addons\NovotonHolidays\Services\PriceInfoFormatter::class;
    // Get admin email from settings
    $admin_email = $pif::toScalar(Registry::get('settings.Company.company_orders_email'));
    if (empty($admin_email)) {
        $admin_email = $pif::toScalar(Registry::get('settings.Company.company_site_administrator'));
    }
    if (empty($admin_email)) {
        $admin_email = $pif::toScalar(db_get_field("SELECT email FROM ?:users WHERE user_type = 'A' AND status = 'A' ORDER BY user_id LIMIT 1"));
    }

    if (empty($admin_email)) {
        fn_log_event('general', 'runtime', 'Cannot send cron report: no admin email found');
        return false;
    }

    $import_type = TypeCoerce::toString($import_type);

    // Build type label for email subject
    $type_labels = [
        'hotel_list'     => 'Hotel List Sync',
        'hotel_info'     => 'Hotel Accommodation',
        'room_price'     => 'Price Check',
        'add_products'   => 'Add Hotels as Products',
        'offers_update'  => 'Offers Update',
        'facilities'     => 'Facilities Sync',
        'resinfo'        => 'Booking Status Check',
        'check_packages' => 'Check Hotel Packages',
        'manual'         => 'Manual Import',
        'cron'           => 'Cron Sync',
    ];
    $type_label = $type_labels[$import_type] ?? ucfirst(str_replace('_', ' ', $import_type));

    $summary = TypeCoerce::toStringMap($summary);
    $results = TypeCoerce::toRowList($results);
    $detail_log = TypeCoerce::toString($detail_log);
    $country_label = TypeCoerce::toString($country) ?: 'ALL';
    $date_label = date('d.m.Y H:i T');
    $summary_data = [
        'added'    => $pif::toInt($summary['added'] ?? 0),
        'updated'  => $pif::toInt($summary['updated'] ?? 0),
        'skipped'  => $pif::toInt($summary['skipped'] ?? 0),
        'errors'   => $pif::toInt($summary['errors'] ?? 0),
        'duration' => $pif::toScalar($summary['duration'] ?? 'N/A'),
    ];

    $report_dir = TypeCoerce::toString(fn_get_files_dir_path()) . 'novoton_reports/';
    if (!is_dir($report_dir)) {
        fn_mkdir($report_dir);
    }
    // Directory as shown to the admin (relative to the store root)
    $report_dir_label = str_replace(
        rtrim(TypeCoerce::toString(Registry::get('config.dir.root')), '/') . '/',
        '',
        $report_dir,
    );

    // Persist the full run log as the per-run report file — this is what the
    // email footer points to, so it must exist for EVERY reported run.
    $report_file = '';
    if ($detail_log !== '') {
        $report_file = 'novoton_' . $import_type . '_report_' . date('Y-m-d_H-i-s') . '.txt';
        $report_content = "Novoton Holidays - {$type_label}\n"
            . "Country: {$country_label}\n"
            . "Date: {$date_label}\n"
            . "Duration: {$summary_data['duration']}\n"
            . "Added: {$summary_data['added']}, Updated: {$summary_data['updated']}, "
            . "Skipped: {$summary_data['skipped']}, Errors: {$summary_data['errors']}\n"
            . "\n"
            . $detail_log . "\n";
        if (file_put_contents($report_dir . $report_file, $report_content) === false) {
            fn_log_event('general', 'runtime', 'Failed to write cron report file: ' . $report_dir . $report_file);
            $report_file = '';
        }
    }

    // Prepare data for email template
    $email_data = [
        'import_type_label' => $type_label,
        'country' => $country_label,
        'date' => $date_label,
        'summary' => $summary_data,
        'results_count' => count($results),
        'detail_log' => $detail_log,
        'report_dir' => $report_dir_label,
        'report_file' => $report_file,
    ];

    // Generate CSV attachment only if there are detailed results
    $attachments = [];
    if (!empty($results)) {
        $csv_content = fn_novoton_holidays_generate_import_csv_report($results, $import_type, $summary);

        $filename = 'novoton_' . $import_type . '_report_' . date('Y-m-d_H-i-s') . '.csv';

        $file_path = $report_dir . $filename;
        if (file_put_contents($file_path, $csv_content) !== false) {
            $attachments[] = [
                'path' => $file_path,
                'name' => $filename,
            ];
        } else {
            fn_log_event('general', 'runtime', 'Failed to write cron report CSV: ' . $file_path);
        }
    }

    $send_result = false;

    try {
        /** @var \Tygh\Mailer\Mailer $mailer */
        $mailer = Tygh::$app['mailer'];

        $send_result = TypeCoerce::toBool($mailer->send([
            'to' => $admin_email,
            'from' => 'default_company_orders_department',
            'data' => $email_data,
            'template_code' => 'novoton_holidays_import_report',
            'attachments' => $attachments,
        ], 'A', CART_LANGUAGE));

    } catch (\Exception $e) {
        fn_log_event('general', 'runtime', 'Failed to send cron report email: ' . $e->getMessage());
    }

    // Clean up old reports (once a day)
    $temp_path = TypeCoerce::toString(fn_get_files_dir_path()) . 'novoton_reports/';
    if (is_dir($temp_path)) {
        fn_novoton_holidays_cleanup_old_reports($temp_path, 7);
    }

    return $send_result;
}

/**
 * Send a price discrepancy alert email when the form price is lower than the
 * real-time room_price API price.
 *
 * @param array<string, mixed> $data Associative array with keys:
 *   hotel_id, hotel_name, room_id, board_id, check_in, check_out,
 *   adults, children, children_ages, form_price, api_price, api_price_raw, difference
 * @return bool
 */
function fn_novoton_holidays_send_price_alert_email(array $data): bool
{
    $admin_email = \Tygh\Addons\NovotonHolidays\Services\ConfigProvider::getAdminEmail();

    if (empty($admin_email)) {
        fn_log_event('general', 'runtime', 'Cannot send price alert email: no admin email found');
        return false;
    }

    $pif = \Tygh\Addons\NovotonHolidays\Services\PriceInfoFormatter::class;
    $email_data = [
        'hotel_id'       => $pif::toScalar($data['hotel_id'] ?? ''),
        'hotel_name'     => $pif::toScalar($data['hotel_name'] ?? ''),
        'room_id'        => $pif::toScalar($data['room_id'] ?? ''),
        'board_id'       => $pif::toScalar($data['board_id'] ?? ''),
        'check_in'       => $pif::toScalar($data['check_in'] ?? ''),
        'check_out'      => $pif::toScalar($data['check_out'] ?? ''),
        'adults'         => $pif::toInt($data['adults'] ?? 2),
        'children'       => $pif::toInt($data['children'] ?? 0),
        'children_ages'  => $pif::toScalar($data['children_ages'] ?? ''),
        'form_price'     => number_format($pif::toFloat($data['form_price'] ?? 0), 2),
        'api_price'      => number_format($pif::toFloat($data['api_price'] ?? 0), 2),
        'api_price_raw'  => number_format($pif::toFloat($data['api_price_raw'] ?? 0), 2),
        'difference'     => number_format($pif::toFloat($data['difference'] ?? 0), 2),
        'date'           => date('d.m.Y H:i:s'),
    ];

    try {
        /** @var \Tygh\Mailer\Mailer $mailer */
        $mailer = Tygh::$app['mailer'];

        return (bool) $mailer->send([
            'to'            => $admin_email,
            'from'          => 'default_company_orders_department',
            'data'          => $email_data,
            'template_code' => 'novoton_holidays_price_alert',
        ], 'A', CART_LANGUAGE);
    } catch (\Exception $e) {
        fn_log_event('general', 'runtime', 'Failed to send price alert email: ' . $e->getMessage());
        return false;
    }
}

/**
 * Price discrepancy email to the admin, from the pre_place_order hook:
 * price_lower | price_higher use the template, price_absorbed |
 * offer_missing use PriceOutcomeEmail.
 *
 * @param array<string, mixed> $data type, hotel_id, hotel_name, room_id, board_id, package_name, check_in, check_out, adults, children, children_ages, form_price, api_price, api_price_raw, difference, percent
 * @return bool
 */
function fn_novoton_holidays_send_price_discrepancy_email(array $data): bool
{
    $admin_email = \Tygh\Addons\NovotonHolidays\Services\ConfigProvider::getAdminEmail();

    if (empty($admin_email)) {
        fn_log_event('general', 'runtime', 'Cannot send price discrepancy email: no admin email found');
        return false;
    }

    $pif = \Tygh\Addons\NovotonHolidays\Services\PriceInfoFormatter::class;
    $type = $pif::toScalar($data['type'] ?? 'price_lower');
    if ($type === 'offer_missing' || $type === 'price_absorbed') {
        return \Tygh\Addons\NovotonHolidays\Services\PriceOutcomeEmail::send($type, $data, $admin_email, static fn (array $mail): bool => ($mailer = Tygh::$app['mailer']) instanceof \Tygh\Mailer\Mailer && (bool) $mailer->send($mail, 'A', CART_LANGUAGE));
    }

    $isPriceLower = ($type === 'price_lower');

    $subject_prefix = $isPriceLower
        ? 'PRICE CORRECTED - Cart Updated to API Price'
        : 'PRICE ALERT - Form Price Above API';

    $email_data = [
        'type'           => $type,
        'is_price_lower' => $isPriceLower,
        'subject_prefix' => $subject_prefix,
        'hotel_id'       => $pif::toScalar($data['hotel_id'] ?? ''),
        'hotel_name'     => $pif::toScalar($data['hotel_name'] ?? ''),
        'room_id'        => $pif::toScalar($data['room_id'] ?? ''),
        'board_id'       => $pif::toScalar($data['board_id'] ?? ''),
        'check_in'       => $pif::toScalar($data['check_in'] ?? ''),
        'check_out'      => $pif::toScalar($data['check_out'] ?? ''),
        'adults'         => $pif::toInt($data['adults'] ?? 2),
        'children'       => $pif::toInt($data['children'] ?? 0),
        'children_ages'  => $pif::toScalar($data['children_ages'] ?? ''),
        'form_price'     => number_format($pif::toFloat($data['form_price'] ?? 0), 2),
        'api_price'      => number_format($pif::toFloat($data['api_price'] ?? 0), 2),
        'api_price_raw'  => number_format($pif::toFloat($data['api_price_raw'] ?? 0), 2),
        'difference'     => number_format($pif::toFloat($data['difference'] ?? 0), 2),
        'percent'        => $pif::toFloat($data['percent'] ?? 0),
        'date'           => date('d.m.Y H:i:s'),
    ];

    try {
        /** @var \Tygh\Mailer\Mailer $mailer */
        $mailer = Tygh::$app['mailer'];

        return (bool) $mailer->send([
            'to'            => $admin_email,
            'from'          => 'default_company_orders_department',
            'data'          => $email_data,
            'template_code' => 'novoton_holidays_price_discrepancy',
        ], 'A', CART_LANGUAGE);
    } catch (\Exception $e) {
        fn_log_event('general', 'runtime', 'Failed to send price discrepancy email: ' . $e->getMessage());
        return false;
    }
}

/**
 * Clean up old report files
 *
 * @param string $dir Directory path
 * @param int $days Keep files newer than this many days
 */
function fn_novoton_holidays_cleanup_old_reports($dir, $days = 7): void
{
    if (!is_dir($dir)) {
        return;
    }

    $cutoff = time() - ($days * 86400);

    $old_reports = array_merge(glob($dir . '*.csv') ?: [], glob($dir . '*.txt') ?: []);
    foreach ($old_reports as $file) {
        if (filemtime($file) < $cutoff) {
            unlink($file);
        }
    }
}

/**
 * Generate hotel features CSV for CS-Cart import
 * 
 * @return array{success: bool, file_path: string, count: int, error: string}
 */
function fn_novoton_holidays_generate_hotel_features_csv(): array
{
    $result = [
        'success' => false,
        'file_path' => '',
        'count' => 0,
        'error' => ''
    ];

    try {
        // V3: Get all hotels with products (hotel_data is audit/cache only — use parsed fields)
        $hotels = db_get_array(
            "SELECT h.hotel_id, h.hotel_name, h.hotel_type, h.product_id, p.product_code
             FROM ?:novoton_hotels h
             LEFT JOIN ?:products p ON h.product_id = p.product_id
             WHERE h.product_id > 0
             ORDER BY h.hotel_name"
        );

        if (empty($hotels)) {
            $result['error'] = 'No hotels with products found';
            return $result;
        }

        // Resolve feature column headers from mapping table (fallback to hardcoded)
        $featureMapper = null;
        try {
            $container = \Tygh\Addons\NovotonHolidays\Services\Container::getInstance();
            $featureMapper = $container->featureMapper();
        } catch (\Exception $e) {
            fn_log_event('general', 'runtime', 'Feature mapper initialization failed: ' . $e->getMessage());
        }

        $starHeaderEn = $featureMapper !== null ? ($featureMapper->getFeatureName(\Tygh\Addons\NovotonHolidays\Constants::FEATURE_TYPE_PROPERTY_RATING, 'en') ?? 'Stars') : 'Stars';
        $boardHeaderEn = $featureMapper !== null ? ($featureMapper->getFeatureName(\Tygh\Addons\NovotonHolidays\Constants::FEATURE_TYPE_MEALS, 'en') ?? 'Board Type') : 'Board Type';

        // CSV header (use EN feature names as column headers)
        $csv_lines = [];
        $csv_lines[] = implode(';', [
            'Product code',
            'Language',
            'Feature: ' . $starHeaderEn,
            'Feature: ' . $boardHeaderEn
        ]);

        // Star rating labels — use FeatureMapper display names with hardcoded fallback
        $star_labels_fallback = NOVOTON_STAR_LABELS;

        // Pre-fetch hotel data in batch (2 queries) instead of N+1 inside the loop
        $hotels = TypeCoerce::toRowList($hotels);
        $hotel_ids = [];
        foreach ($hotels as $h) {
            $hotel_ids[] = TypeCoerce::toString($h['hotel_id'] ?? '');
        }
        if (!empty($hotel_ids)) {
            fn_novoton_holidays_prefetch_hotel_data($hotel_ids);
        }

        foreach ($hotels as $hotel) {
            $hotel_id_str = TypeCoerce::toString($hotel['hotel_id'] ?? '');
            $product_code = !empty($hotel['product_code'])
                ? TypeCoerce::toString($hotel['product_code'])
                : \Tygh\Addons\NovotonHolidays\Constants::PRODUCT_CODE_PREFIX . $hotel_id_str;
            $stars = TypeCoerce::toInt($hotel['hotel_type'] ?? 0); // "4*" -> 4, "Apart" -> 0

            // V3: Get boards via fn_novoton_holidays_get_hotel_data() (hotel_data is audit/cache only)
            $board_names = [];
            $hotel_full = fn_novoton_holidays_get_hotel_data($hotel_id_str) ?? [];
            if (!empty($hotel_full['boards'])) {
                foreach (TypeCoerce::toList($hotel_full['boards']) as $b) {
                    $code = is_array($b) ? TypeCoerce::toString($b['IdBoard'] ?? $b['Board'] ?? '') : TypeCoerce::toString($b);
                    if ($code !== '') {
                        $board_names[] = fn_novoton_holidays_format_board_name($code);
                    }
                }
            }

            // Fallback: extract board types from priceinfo season_price data
            if (empty($board_names) && !empty($hotel_full['packages'])) {
                foreach (TypeCoerce::toRowList($hotel_full['packages']) as $pkg) {
                    $pi = $pkg['priceinfo_data'] ?? null;
                    if (is_string($pi)) {
                        $pi = json_decode($pi, true);
                    }
                    if ($pi === null) continue;
                    $piMap = TypeCoerce::toStringMap($pi);
                    if (!empty($piMap['season_price'])) {
                        $seasonPrice = TypeCoerce::toStringMap($piMap['season_price']);
                        $sp_list = isset($seasonPrice['IdRoom']) ? [$seasonPrice] : TypeCoerce::toRowList($piMap['season_price']);
                        foreach ($sp_list as $sp) {
                            $bid = TypeCoerce::toString($sp['IdBoard'] ?? '');
                            if ($bid !== '') {
                                $board_names[] = fn_novoton_holidays_format_board_name($bid);
                            }
                        }
                    }
                }
            }
            $boards_str = implode(',', array_unique($board_names));

            // Generate star label from travel_core FeatureMapper or fallback
            foreach (['ro', 'en'] as $lang) {
                $star_label = '';
                if ($stars >= 1 && $stars <= 5) {
                    $coreLabel = class_exists(\Tygh\Addons\TravelCore\Services\FeatureMapper::class)
                        ? \Tygh\Addons\TravelCore\Services\FeatureMapper::getDisplayName('stars', (string) $stars, $lang)
                        : '';
                    $star_label = $coreLabel !== '' ? $coreLabel : $star_labels_fallback[$lang][$stars - 1];
                }

                $csv_lines[] = implode(';', [
                    fn_novoton_holidays_csv_escape($product_code),
                    fn_novoton_holidays_csv_escape($lang),
                    fn_novoton_holidays_csv_escape($star_label),
                    fn_novoton_holidays_csv_escape($boards_str)
                ]);
            }

            $result['count']++;
        }

        // Save to file in novoton_reports directory
        $filename = 'novoton_hotel_features.csv';
        $dir = TypeCoerce::toString(fn_get_files_dir_path()) . 'novoton_reports/';

        if (!is_dir($dir)) {
            fn_mkdir($dir);
        }

        $file_path = $dir . $filename;
        $csv_content = implode("\n", $csv_lines);

        if (file_put_contents($file_path, $csv_content) > 0) {
            $result['success'] = true;
            $result['file_path'] = $file_path;
            $result['filename'] = $filename;
        } else {
            $result['error'] = 'Failed to write CSV file';
        }

    } catch (\Exception $e) {
        $result['error'] = $e->getMessage();
    }

    return $result;
}

/**
 * Generate XML file with hotel features for CS-Cart product import.
 *
 * XML target node: products/product
 * Field mapping:
 *   product_code  → Product code
 *   language      → Language
 *   feature_stele → Feature: Stele
 *   feature_tip_masa → Feature: Tip Masa
 *
 * @return array<string, mixed> ['success' => bool, 'file_path' => string, 'count' => int, 'error' => string, 'filename' => string]
 */
function fn_novoton_holidays_generate_hotel_features_xml(): array
{
    $result = [
        'success'   => false,
        'file_path' => '',
        'count'     => 0,
        'error'     => '',
        'filename'  => '',
    ];

    try {
        $hotels = db_get_array(
            "SELECT h.hotel_id, h.hotel_name, h.hotel_type, h.product_id, p.product_code
             FROM ?:novoton_hotels h
             LEFT JOIN ?:products p ON h.product_id = p.product_id
             WHERE h.product_id > 0
             ORDER BY h.hotel_name"
        );

        if (empty($hotels)) {
            $result['error'] = 'No hotels with products found';
            return $result;
        }

        // Resolve feature mapper for dynamic display names
        try {
            $container = \Tygh\Addons\NovotonHolidays\Services\Container::getInstance();
            $container->featureMapper();
        } catch (\Exception $e) {
            fn_log_event('general', 'runtime', 'Feature mapper initialization failed: ' . $e->getMessage());
        }

        $star_labels_fallback = NOVOTON_STAR_LABELS;

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $root = $dom->createElement('products');
        $dom->appendChild($root);

        // Pre-fetch hotel data in batch to avoid N+1 queries inside the loop
        $hotels = TypeCoerce::toRowList($hotels);
        $hotel_ids = [];
        foreach ($hotels as $h) {
            $hotel_ids[] = TypeCoerce::toString($h['hotel_id'] ?? '');
        }
        if (!empty($hotel_ids)) {
            fn_novoton_holidays_prefetch_hotel_data($hotel_ids);
        }

        foreach ($hotels as $hotel) {
            $hotel_id_str = TypeCoerce::toString($hotel['hotel_id'] ?? '');
            $product_code = !empty($hotel['product_code'])
                ? TypeCoerce::toString($hotel['product_code'])
                : \Tygh\Addons\NovotonHolidays\Constants::PRODUCT_CODE_PREFIX . $hotel_id_str;

            $stars = TypeCoerce::toInt($hotel['hotel_type'] ?? 0); // "4*" -> 4, "Apart" -> 0

            // Boards via hotel data
            $board_names = [];
            $hotel_full  = fn_novoton_holidays_get_hotel_data($hotel_id_str) ?? [];
            if (!empty($hotel_full['boards'])) {
                foreach (TypeCoerce::toList($hotel_full['boards']) as $b) {
                    $code = is_array($b) ? TypeCoerce::toString($b['IdBoard'] ?? $b['Board'] ?? '') : TypeCoerce::toString($b);
                    if ($code !== '') {
                        $board_names[] = fn_novoton_holidays_format_board_name($code);
                    }
                }
            }

            // Fallback: extract board types from priceinfo season_price data
            if (empty($board_names) && !empty($hotel_full['packages'])) {
                foreach (TypeCoerce::toRowList($hotel_full['packages']) as $pkg) {
                    $pi = $pkg['priceinfo_data'] ?? null;
                    if (is_string($pi)) {
                        $pi = json_decode($pi, true);
                    }
                    if ($pi === null) continue;
                    $piMap = TypeCoerce::toStringMap($pi);
                    if (!empty($piMap['season_price'])) {
                        $seasonPrice = TypeCoerce::toStringMap($piMap['season_price']);
                        $sp_list = isset($seasonPrice['IdRoom']) ? [$seasonPrice] : TypeCoerce::toRowList($piMap['season_price']);
                        foreach ($sp_list as $sp) {
                            $bid = TypeCoerce::toString($sp['IdBoard'] ?? '');
                            if ($bid !== '') {
                                $board_names[] = fn_novoton_holidays_format_board_name($bid);
                            }
                        }
                    }
                }
            }
            $boards_str = implode(',', array_unique($board_names));

            // One <product> node per language
            foreach (['ro', 'en'] as $lang) {
                $star_value = '';
                if ($stars >= 1 && $stars <= 5) {
                    $coreLabel = class_exists(\Tygh\Addons\TravelCore\Services\FeatureMapper::class)
                        ? \Tygh\Addons\TravelCore\Services\FeatureMapper::getDisplayName('stars', (string) $stars, $lang)
                        : '';
                    $star_value = $coreLabel !== '' ? $coreLabel : $star_labels_fallback[$lang][$stars - 1];
                }

                $product_node = $dom->createElement('product');

                $product_node->appendChild($dom->createElement('product_code', htmlspecialchars($product_code, ENT_XML1, 'UTF-8')));
                $product_node->appendChild($dom->createElement('language', $lang));
                $product_node->appendChild($dom->createElement('feature_stele', htmlspecialchars($star_value, ENT_XML1, 'UTF-8')));
                $product_node->appendChild($dom->createElement('feature_tip_masa', htmlspecialchars($boards_str, ENT_XML1, 'UTF-8')));

                $root->appendChild($product_node);
            }

            $result['count']++;
        }

        // Save
        $filename  = 'novoton_hotel_features.xml';
        $dir       = TypeCoerce::toString(fn_get_files_dir_path()) . 'novoton_reports/';

        if (!is_dir($dir)) {
            fn_mkdir($dir);
        }

        $file_path = $dir . $filename;

        if ($dom->save($file_path) > 0) {
            $result['success']   = true;
            $result['file_path'] = $file_path;
            $result['filename']  = $filename;
        } else {
            $result['error'] = 'Failed to write XML file';
        }
    } catch (\Exception $e) {
        $result['error'] = $e->getMessage();
    }

    return $result;
}
