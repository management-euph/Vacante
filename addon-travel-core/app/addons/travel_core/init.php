<?php

declare(strict_types=1);
/***************************************************************************
 *                                                                          *
 *   (c) 2024-2026 VacanteLitoral.ro                                       *
 *                                                                          *
 *   Location: app/addons/travel_core/init.php                             *
 *                                                                          *
 ***************************************************************************/

use Tygh\Registry;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

// Addon version constant
if (!defined('TRAVEL_CORE_VERSION')) {
    $__tvRaw = Registry::get('addons.travel_core.version');
    $__tv = is_scalar($__tvRaw) && $__tvRaw !== '' ? (string) $__tvRaw : '0.0.0';
    define('TRAVEL_CORE_VERSION', preg_replace('/-.*$/', '', $__tv));
    unset($__tv);
}

// React bundle cache version — bump when JS bundles are rebuilt
if (!defined('TRAVEL_CACHE_VER')) {
    define('TRAVEL_CACHE_VER', '8');
}

// Register PSR-4 autoloader for travel_core namespace.
spl_autoload_register(function ($class): void {
    $prefix = 'Tygh\\Addons\\TravelCore\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }

    $relative = str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    $file = __DIR__ . '/src/' . $relative;

    if (file_exists($file)) {
        require $file;
    }
});

// Load shared function libraries
require_once __DIR__ . '/functions/cart_guests.php';
require_once __DIR__ . '/functions/cart_skip.php';
require_once __DIR__ . '/functions/destinations.php';
require_once __DIR__ . '/functions/exchange_rates.php';
require_once __DIR__ . '/functions/geocoding.php';
require_once __DIR__ . '/functions/hotels.php';
require_once __DIR__ . '/functions/seo.php';
require_once __DIR__ . '/functions/self_heal.php';

// Load hook functions
require_once __DIR__ . '/hooks.php';

// Self-heal: ensure the tables that predate a migration get it (alias unique
// key, bookings-grid index, unmapped-values table). The heal is idempotent
// but costs catalog queries + a CREATE IF NOT EXISTS, so it is stamp-gated:
// it runs once per deployed version of func.php (where its migrations live)
// and every later admin request pays one ?:storage_data read.
if (defined('AREA') && AREA === 'A' && function_exists('fn_travel_core_ensure_schema')) {
    $__tc_heal_fp = (string) @md5_file(__DIR__ . '/func.php');
    if (fn_travel_core_self_heal_due('travel_core_schema', $__tc_heal_fp)) {
        fn_travel_core_self_heal_guard('travel_core_schema', 'fn_travel_core_ensure_schema');
        fn_travel_core_self_heal_stamp('travel_core_schema', $__tc_heal_fp);
    }
    unset($__tc_heal_fp);
}

// The SETTINGS self-heal deliberately does NOT run here. This file is
// require'd from fn_init_addons(), which CS-Cart calls from fn_init() BEFORE
// it defines CART_LANGUAGE — and Settings::updateValue() dereferences that
// constant (Settings::getData). Seeding a default from here therefore threw
// "Undefined constant Tygh\CART_LANGUAGE" and 500'd every admin page. It now
// runs from fn_travel_core_heal_settings_once() on the dispatch_before_display
// hook, by which point the framework is fully initialised.

// Self-heal language keys: addon.xml/.po are only imported at install, so new
// or changed labels never reach existing stores on their own. The probe asks
// the database whether every INSTALLED language carries the current source
// fingerprint (LanguageDelivery::isCurrent — one indexed read per request),
// rather than trusting a single stamp row that a failed seed could have
// written anyway. Deliberately NOT gated to the admin area: a deploy followed
// by storefront-only traffic used to show customers raw "_travel_core.…" keys
// until someone opened an admin page.
//
// Guarded: this runs inside fn_init_addons(), so an uncaught throw here is a
// 503 on every page — the failure mode must be "not healed", never "no store".
if (function_exists('fn_travel_core_heal_language_keys')) {
    fn_travel_core_self_heal_guard('travel_core_langs', static function (): void {
        // The class, NOT the fn_travel_core_language_* helpers: those live in
        // func.php, which init.php never loads, and older CS-Cart cores include
        // init.php FIRST. There the call hit an undefined function, the guard
        // swallowed the Error on every request, and no label added after
        // install ever reached the store.
        fn_travel_core_heal_language_keys(
            'travel_core',
            static fn (): array => \Tygh\Addons\TravelCore\Install\LanguageSeeder::variables(),
            \Tygh\Addons\TravelCore\Install\LanguageSeeder::seedHash(),
        );
    });
}

// Register addon hooks
fn_register_hooks(
    'get_cart_product_data_post',      // Format cart items for travel bookings
    'calculate_cart_items_post',       // Ensure rooms_data preserved as array
    'dispatch_before_display',         // CSS loading for booking pages
    'get_order_info',                   // Format booking data in order view
);
