<?php
declare(strict_types=1);
/**
 * Novoton Holidays - Main Functions File
 * 
 * This file includes all organized function files.
 * Functions are split into domain-specific files for better maintainability.
 * 
 * @package NovotonHolidays
 * @since 2.8.0
 * 
 * Function files included:
 * - functions/helpers.php        - Core utility functions (API, debug, price update)
 * - functions/install.php        - Installation, uninstall, upgrade functions
 * - functions/formatting.php     - Room type, board name, terms formatting
 * - functions/hotels.php         - Hotel data, sync, facilities
 * - functions/bookings.php       - Booking management, reservations, alternatives
 * - functions/email.php          - Email notifications, CSV reports
 */

use Tygh\Registry;

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

// Get addon directory
$addon_dir_raw = Registry::get('config.dir.addons');
$addon_dir = (is_scalar($addon_dir_raw) ? (string) $addon_dir_raw : '') . 'novoton_holidays/';

// Include organized function files
$function_files = [
    'functions/helpers.php',
    'functions/install.php',
    'functions/formatting.php',
    'functions/hotels.php',
    'functions/bookings.php',
    'functions/email.php',
    'functions/exchange_rates.php',
];

foreach ($function_files as $file) {
    $path = $addon_dir . $file;
    if (file_exists($path)) {
        require_once $path;
    }
}

/**
 * Merge the runtime language keys (lang_keys.php) so already-installed stores
 * pick up NEW or CHANGED labels — CS-Cart imports the .po files only at install
 * time. addon.xml <language_variables>, if present, is merged on top.
 *
 * @return array<string, array<string, string>> key => [lang_code => value]
 */
function fn_novoton_holidays_language_variables(): array
{
    // Delegates to a class init.php can reach whatever the file load order —
    // see LanguageSeeder's docblock.
    return \Tygh\Addons\NovotonHolidays\Install\LanguageSeeder::variables();
}

/**
 * Fingerprint of the language sources — the value stamped onto every installed
 * language once its labels are verified in the database. stat-based (size +
 * mtime), not a content hash: this runs on every request and the sources are
 * hundreds of KB. Whether the ROWS are present is a separate question that
 * LanguageDelivery::isCurrent() asks the database directly.
 */
function fn_novoton_holidays_language_seed_hash(): string
{
    return \Tygh\Addons\NovotonHolidays\Install\LanguageSeeder::seedHash();
}

/**
 * Force a full language (re)seed, ignoring both the stamp and the probe.
 *
 * Delivery rules (which installed language gets which source values, and the
 * read-back that must succeed before a language is stamped as done) are shared
 * with the other travel addons in travel_core's LanguageDelivery. The
 * self-healing entry point is fn_travel_core_heal_language_keys().
 */
function fn_novoton_holidays_seed_language_keys(): void
{
    \Tygh\Addons\TravelCore\Install\LanguageDelivery::seed(
        'novoton_holidays._lang_seed_hash',
        fn_novoton_holidays_language_variables(),
        fn_novoton_holidays_language_seed_hash(),
    );
}

/**
 * Variants function for the api_currency addon setting.
 * Pulls currencies from CS-Cart's configured currencies.
 * Called only from admin settings page where Registry is always populated.
 * @return array<string, string>
 */
function fn_settings_variants_addons_novoton_holidays_api_currency(): array
{
    $currencies = Registry::get('currencies');
    $result = [];

    if (empty($currencies) || !is_array($currencies)) {
        return $result;
    }

    foreach ($currencies as $code => $currency) {
        $currencyRow = \Tygh\Addons\TravelCore\Helpers\TypeCoerce::toStringMap($currency);
        $symbol = \Tygh\Addons\TravelCore\Helpers\TypeCoerce::toString($currencyRow['symbol'] ?? '');
        $result[(string) $code] = (string) $code . ($symbol !== '' ? ' (' . $symbol . ')' : '');
    }

    return $result;
}

/**
 * The placeholders ProductFactory::buildNovotonPlaceholders() fills, as the
 * SEO Templates page lists them (travel_core labels each bare key).
 *
 * @return array<string, list<string>>
 */
function fn_novoton_holidays_seo_placeholders(): array
{
    return [
        'hotel'    => ['name', 'raw_name', 'star_rating', 'stars_emoji', 'hotel_type', 'property_type', 'facilities', 'description'],
        'location' => ['city', 'country', 'region', 'latitude', 'longitude'],
        'other'    => ['year'],
    ];
}

/**
 * This add-on's tab in the SEO Templates provider row.
 *
 * @return array{name: string, dispatch: string}
 */
function fn_novoton_holidays_seo_page(): array
{
    return ['name' => 'Novoton', 'dispatch' => 'novoton_seo_templates.manage'];
}

/**
 * Canonical default SEO template strings + field toggles for Novoton products.
 *
 * Single source of truth shared by the seed routine, the SEO Templates admin
 * page, and the travel_core runtime renderer (which uses these as a fallback
 * when no admin-configured template is stored). Keeping it here in func.php
 * (loaded in every AREA, including the storefront cron context) is what lets
 * cron-created products get rendered metadata even when the settings were
 * never persisted to the DB.
 *
 * @return array<string, string>
 */
function fn_novoton_holidays_seo_defaults(): array
{
    return [
        'seo_overwrite_mode'         => 'override_all',
        'seo_product_name'           => '{{name}}',
        'seo_page_title'             => '{{name}} - {{city}}, {{country}} {{year}}',
        'seo_meta_description'       => 'Book {{name}} in {{city}}, {{country}}. {{star_rating}}-star hotel with {{facilities}}.',
        'seo_meta_keywords'          => '{{name}}, {{city}}, {{country}}, {{property_type}}, {{star_rating}} star',
        'seo_name_slug'              => '{{name}}-{{city}}-{{country}}',
        'seo_full_description'       => '',
        // Per-language defaults: a "__<lang>" suffix targets that storefront
        // language; languages without one fall back to the base template
        // above (see _travel_core_seo_template_for). Only fields whose COPY
        // differs need a variant — names/slugs are proper nouns.
        'seo_meta_description__ro'   => 'Rezervă {{name}} în {{city}}, {{country}}. Hotel de {{star_rating}} stele cu {{facilities}}.',
        'seo_meta_keywords__ro'      => '{{name}}, {{city}}, {{country}}, {{property_type}}, {{star_rating}} stele',
        'seo_field_product_name'     => 'Y',
        'seo_field_page_title'       => 'Y',
        'seo_field_meta_description' => 'Y',
        'seo_field_meta_keywords'    => 'Y',
        'seo_field_name_slug'        => 'Y',
        'seo_field_full_description' => 'Y',
    ];
}

function fn_novoton_holidays_seed_seo_defaults(): void
{
    $defaults = fn_novoton_holidays_seo_defaults();

    $currentRaw = \Tygh\Registry::get('addons.novoton_holidays');
    $current  = is_array($currentRaw) ? $currentRaw : [];
    $settings = \Tygh\Settings::instance();
    if (!is_object($settings) || !method_exists($settings, 'updateValue')) {
        return;
    }
    $toMerge  = [];

    foreach ($defaults as $key => $value) {
        $stored = $current[$key] ?? null;
        // Only write if the key is absent or blank (never overwrite admin edits)
        if ($stored === null || ($stored === '' && $value !== '')) {
            $settings->updateValue($key, $value, 'novoton_holidays', true);
            $toMerge[$key] = $value;
        }
    }

    if (!empty($toMerge)) {
        \Tygh\Registry::set('addons.novoton_holidays', array_merge($current, $toMerge));
    }
}

// ============================================================================
// FUNCTION LOCATIONS
// ============================================================================
// All functions use the fn_novoton_holidays_* prefix per CS-Cart convention.
//
// HELPERS (functions/helpers.php):
//   fn_novoton_holidays_parse_countries()
//   fn_novoton_holidays_is_debug()
//   fn_novoton_holidays_get_api()
//   fn_novoton_holidays_update_product_prices()
//
// INSTALLATION (functions/install.php):
//   fn_novoton_holidays_uninstall()
//   fn_novoton_holidays_fix_tab_name()
//   fn_novoton_holidays_post_install()
//   fn_novoton_holidays_setup_db()
//
// FORMATTING (functions/formatting.php):
//   fn_novoton_holidays_format_date()
//   fn_novoton_holidays_format_board_name()
//   fn_novoton_holidays_format_room_type()
//   fn_novoton_holidays_normalize_room_code()
//   fn_novoton_holidays_normalize_resort_name()
//   fn_novoton_holidays_parse_xml_string()
//   fn_novoton_holidays_parse_payment_terms()
//   fn_novoton_holidays_parse_cancellation_terms()
//   fn_novoton_holidays_format_payment_terms()
//   fn_novoton_holidays_format_payment_terms_with_amounts()
//   fn_novoton_holidays_format_cancellation_terms()
//   fn_novoton_holidays_get_free_cancellation_date()
//   fn_novoton_holidays_build_hotel_title()
//   fn_novoton_holidays_xml_to_array()
//
// HOTELS (functions/hotels.php):
//   fn_novoton_holidays_normalize_package()
//   fn_novoton_holidays_get_hotel_data()
//   fn_novoton_holidays_get_hotel_prices()
//   fn_novoton_holidays_get_package_priceinfo()
//   fn_novoton_holidays_get_package_priceinfo_by_name()
//   fn_novoton_holidays_get_hotels_count()
//   fn_novoton_holidays_get_hotels_no_packages_count()
//   fn_novoton_holidays_get_hotels_no_packages_by_country()
//   fn_novoton_holidays_get_hotel_id_by_product()
//   fn_novoton_holidays_get_or_create_category()
//   fn_novoton_holidays_sync_resorts_list()
//   fn_novoton_holidays_sync_facilities_list()
//   fn_novoton_holidays_sync_hotel_facilities()
//   fn_novoton_holidays_get_hotel_facilities()
//   fn_novoton_holidays_get_resorts_for_settings()
//   fn_novoton_holidays_assign_property_rating_feature()
//   fn_novoton_holidays_add_product_image()
//
// BOOKINGS (functions/bookings.php):
//   fn_novoton_holidays_decrypt_request_pii()
//   fn_novoton_holidays_decrypt_requests_pii()
//   fn_novoton_holidays_check_reservation_status()
//   fn_novoton_holidays_request_alternatives()
//   fn_novoton_holidays_get_alternatives()
//   fn_novoton_holidays_get_order_bookings()
//   fn_novoton_holidays_cron_resinfo()
//
// EMAIL (functions/email.php):
//   fn_novoton_holidays_csv_escape()
//   fn_novoton_holidays_generate_import_csv_report()
//   fn_novoton_holidays_send_import_report_email()
//   fn_novoton_holidays_send_price_alert_email()
//   fn_novoton_holidays_send_price_discrepancy_email()
//   fn_novoton_holidays_cleanup_old_reports()
//   fn_novoton_holidays_generate_hotel_features_csv()
//   fn_novoton_holidays_generate_hotel_features_xml()
// ============================================================================
