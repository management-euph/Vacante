<?php
declare(strict_types=1);
/***************************************************************************
 *                                                                          *
 *   (c) 2024-2025 VacanteLitoral.ro                                       *
 *                                                                          *
 *   Location: app/addons/novoton_holidays/init.php                        *
 *                                                                          *
 ***************************************************************************/

use Tygh\Registry;

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

// Addon version constant — single source of truth from addon.xml via Registry.
// Strips build suffix (e.g. "3.0.0-A86" → "3.0.0") for display purposes.
if (!defined('NOVOTON_VERSION')) {
    $__nvRaw = Registry::get('addons.novoton_holidays.version');
    $__nv = is_scalar($__nvRaw) && $__nvRaw !== '' ? (string) $__nvRaw : '0.0.0';
    define('NOVOTON_VERSION', preg_replace('/-.*$/', '', $__nv) ?? '0.0.0');
    unset($__nv);
}

// Cache-busting version — changes automatically when JS bundle is modified.
// Uses filemtime of the React bundle so every deploy busts browser cache
// even within the same addon version (e.g. hotfixes, rebuilds).
if (!defined('NOVOTON_CACHE_VER')) {
    $__bundle = __DIR__ . '/../../../../js/addons/novoton_holidays/react19-bundle.js';
    $__mtime = file_exists($__bundle) ? (string) filemtime($__bundle) : '0';
    $__ver = defined('NOVOTON_VERSION') && is_string(NOVOTON_VERSION) ? NOVOTON_VERSION : '0.0.0';
    define('NOVOTON_CACHE_VER', substr(md5($__ver . $__mtime), 0, 8));
    unset($__ver);
    unset($__bundle, $__mtime);
}

// Register PSR-4 autoloader for ALL addon namespaces.
// All classes live under src/ — single PSR-4 root.
spl_autoload_register(function ($class) {
    $prefix = 'Tygh\\Addons\\NovotonHolidays\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }

    $relative = str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    $file = __DIR__ . '/src/' . $relative;

    if (file_exists($file)) {
        require $file;
        return;
    }

    // Fallback: addon root for Constants.php (lives next to addon.xml)
    $file = __DIR__ . '/' . $relative;
    if (file_exists($file)) {
        require $file;
    }
});

// ── Schema migrations ──
// Column additions are handled ONLY by setup_db() during addon install/upgrade.
// Runtime migrations in init.php are dangerous — if they crash, the entire
// addon (hooks, modifiers, registration) is killed. Never run DB schema
// changes in init.php.

// Load service accessor functions (_nvt_api, _nvt_hotel_repo, etc.)
// These are plain functions (not class methods), so the PSR-4 autoloader won't load them.
require_once __DIR__ . '/src/Services/ServiceLoader.php';

// Force load hooks.php
require_once __DIR__ . '/hooks.php';

// ── Smarty modifiers: none — deliberately ──────────────────────────────
// Smarty 5 resolves modifiers at COMPILE time from registered plugins and
// real PHP function names only. Auto-discovery of smarty_modifier_* globals
// no longer exists, and registerPlugin() timing is not guaranteed when a
// template recompiles (a |novoton_* pipe threw a CompilerException that
// 500'd novoton_booking.booking_form storewide — a runtime try/catch inside
// the modifier can never catch a compile-time error). Templates call the
// plain helpers directly instead, e.g.
// {fn_novoton_holidays_format_board_name($x|default:'')} — enforced by
// SmartyCompatTest::testNoCustomModifierPipesInTemplates.

// Register with shared travel provider registry (guard against travel_core not being loaded)
if (class_exists(\Tygh\Addons\TravelCore\Services\TravelProviderRegistry::class)) {
    \Tygh\Addons\TravelCore\Services\TravelProviderRegistry::register(
        'novoton',
        'Novoton Holidays',
        new \Tygh\Addons\NovotonHolidays\Api\NovotonNormalizer()
    );
    // This add-on's cron, for Travel Core -> Tools: its job types come from
    // the dispatcher, its runs from the shared CronRunLog, and the row links
    // to the page below. Declared HERE so Travel Core never hard-codes
    // another add-on's jobs — disable this add-on and its row disappears.
    \Tygh\Addons\TravelCore\Services\TravelProviderRegistry::setCron(
        'novoton',
        'novoton_holidays',
        \Tygh\Addons\NovotonHolidays\Cron\CronDispatcher::class,
        'novoton_holidays.manage',
        'novoton-cron-jobs',
    );
    \Tygh\Addons\TravelCore\Services\TravelProviderRegistry::setBookingAdminProvider(
        'novoton',
        new \Tygh\Addons\NovotonHolidays\Services\BookingAdminProvider()
    );
    \Tygh\Addons\TravelCore\Services\TravelProviderRegistry::setStatusCallbacks(
        'novoton',
        function () {
            return fn_novoton_holidays_cron_resinfo();
        },
        function (int $bookingId) {
            $provider = new \Tygh\Addons\NovotonHolidays\Services\BookingAdminProvider();
            return $provider->checkStatus((string) $bookingId);
        }
    );
    \Tygh\Addons\TravelCore\Services\TravelProviderRegistry::setHotelProductProvider(
        'novoton',
        new \Tygh\Addons\NovotonHolidays\Providers\NovotonHotelProductProvider()
    );
    // Cancellation & payment terms of novoton cart lines, for travel_core's
    // cart / checkout booking card.
    \Tygh\Addons\TravelCore\Services\TravelProviderRegistry::setCartTermsResolver(
        'novoton',
        static fn (array $extra): array => !empty($extra['novoton_booking'])
            ? \Tygh\Addons\NovotonHolidays\ViewModels\NovotonBookingSidebarBuilder::cartTerms($extra)
            : []
    );
}

// Seed SEO defaults on first admin load (mirrors sphinx pattern)
if (defined('AREA') && AREA === 'A' && function_exists('fn_novoton_holidays_seed_seo_defaults')) {
    if (\Tygh\Registry::get('addons.novoton_holidays.seo_page_title') === null) {
        fn_novoton_holidays_seed_seo_defaults();
    }
}

// Repair: the RO .po shipped without the blank line between ph_facilities and
// location_show_map, so the import glued the admin SEO-placeholder hint
// ("Facilități principale (separate prin virgulă)") onto the storefront map
// link. Fingerprint-guarded so a merchant's own label is never overwritten;
// once repaired the guard no longer matches and this is a single cheap SELECT.
if (defined('AREA') && AREA === 'A') {
    $__mapLabel = db_get_field(
        "SELECT value FROM ?:language_values WHERE name = 'novoton_holidays.location_show_map' AND lang_code = 'ro' LIMIT 1"
    );
    if (is_string($__mapLabel) && str_contains($__mapLabel, 'virgul')) {
        db_query(
            "INSERT INTO ?:language_values (name, lang_code, value) VALUES ('novoton_holidays.location_show_map', 'ro', ?s)
             ON DUPLICATE KEY UPDATE value = ?s",
            'Locație - arată pe hartă', 'Locație - arată pe hartă'
        );
        if (function_exists('fn_clear_cache')) {
            fn_clear_cache();
        }
    }
    unset($__mapLabel);
}

// Language self-seed: reseed ?:language_values from lang_keys.php + addon.xml
// whenever an installed language is missing the current source fingerprint —
// CS-Cart imports .po only at install time, so labels added afterwards would
// otherwise render as raw "_novoton_holidays.*" forever.
//
// No AREA gate: it used to be admin-only, which meant a deploy followed by
// storefront-only traffic left CUSTOMERS looking at the raw keys until someone
// happened to open the admin panel. Guarded, because a throw inside
// fn_init_addons() is a 503 on every page.
if (function_exists('fn_travel_core_heal_language_keys')) {
    fn_travel_core_self_heal_guard('novoton_holidays_langs', static function (): void {
        // The class, NOT the fn_novoton_holidays_language_* helpers: those live in
        // func.php, which init.php never loads, and older CS-Cart cores include
        // init.php FIRST. There the call hit an undefined function, the guard
        // swallowed the Error on every request, and no label added after
        // install ever reached the store.
        fn_travel_core_heal_language_keys(
            'novoton_holidays',
            static fn (): array => \Tygh\Addons\NovotonHolidays\Install\LanguageSeeder::variables(),
            \Tygh\Addons\NovotonHolidays\Install\LanguageSeeder::seedHash(),
        );
    });
}

// Alias self-heal: re-seed Novoton's ?:travel_api_alias rows once per
// deployed version of the seed data. Seeding otherwise ran only at install
// and on the product syncs, so rows lost to the old (api_source, api_value)
// key — Novoton's star ratings '1'-'5' collided with its facility ids — were
// never written back, and Novoton hotels got no star rating.
if (defined('AREA') && AREA === 'A' && function_exists('fn_travel_core_self_heal_due')
    && class_exists(\Tygh\Addons\TravelCore\Services\FeatureMapper::class)
) {
    $__nvt_alias_fp = \Tygh\Addons\NovotonHolidays\Install\AliasSeeder::fingerprint();
    if (fn_travel_core_self_heal_due('novoton_aliases', $__nvt_alias_fp)) {
        fn_travel_core_self_heal_guard('novoton_aliases', static function (): void {
            $prefix = \Tygh\Registry::get('config.table_prefix');
            \Tygh\Addons\NovotonHolidays\Install\AliasSeeder::seed(is_scalar($prefix) ? (string) $prefix : 'cscart_');
        });
        fn_travel_core_self_heal_stamp('novoton_aliases', $__nvt_alias_fp);
    }
    unset($__nvt_alias_fp);
}

// Register addon hooks
fn_register_hooks(
    'get_product_data_post',                   // Add hotel data to products
    'gather_additional_product_data_post',     // Pass data to templates (for tabs)
    'get_product_tabs_post',                   // Hide Hotel Prices tab on non-Novoton products
    'delete_product_post',                     // Cleanup after product deletion
    'pre_place_order',                         // Real-time price verification before order
    'place_order_post',                        // Create bookings on order (post — needs order_id)
    'get_orders_post',                         // Add booking info to orders
    'get_order_info',                          // Format terms on order detail page
    'dispatch_before_display',                 // Ensure meta variables are set
    'get_cart_product_data_post',              // Add booking info to cart items
    'calculate_cart_items',                    // After cart calculation
    'calculate_cart_items_post',              // After cart items calculation - for rooms_data
    'user_login_post',                         // Link session bookings to logged-in user
    'create_user_post',                        // Link bookings to newly registered users
    'checkout_pre_dispatch',                   // Debug info on checkout pages
    'travel_link_order_bookings'               // Backfill: link historical bookings to their orders (Travel Tools reconcile button)
);
