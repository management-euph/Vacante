<?php

declare(strict_types=1);

/**
 * Eurosite Touring — addon bootstrap.
 *
 * Location: app/addons/eurosite/init.php
 */

use Tygh\Registry;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

// Addon version constant.
//
// Deliberately NOT using TravelCore's TypeCoerce. This file is require'd from
// fn_init_addons(), and the Tygh\Addons\TravelCore\* autoloader is registered
// only by travel_core's OWN init.php — which a disabled travel_core never
// runs. A runtime reference to a Core class here therefore throws "Class not
// found" from inside bootstrap: a 503 on every page, storefront and admin,
// including the admin page you would need to re-enable it from.
//
// Same bug class as the 2026-07-10 install failure that FuncSelfSufficiencyTest
// was written for. Bootstrap code carries no dependencies; a class_exists guard
// would keep the coupling and merely hide it.
if (!defined('EUROSITE_VERSION')) {
    $__ev = Registry::get('addons.eurosite.version');
    $__ev = is_scalar($__ev) && (string) $__ev !== '' ? (string) $__ev : '0.0.0';
    define('EUROSITE_VERSION', preg_replace('/-.*$/', '', $__ev));
    unset($__ev);
}

// PSR-4 autoloader for the Tygh\Addons\Eurosite namespace -> src/.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Tygh\\Addons\\Eurosite\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    $file = __DIR__ . '/src/' . $relative;
    if (file_exists($file)) {
        require $file;
    }
});

// Schema self-heal for installed stores (admin area only; stamp-gated so the
// catalog reads + conditional DDL run once per deployed SchemaMigrator.php,
// then every request pays a single ?:storage_data read). Dev stores symlink
// the addon and never rerun addon.xml — this is how new tables/columns land.
if (defined('AREA') && AREA === 'A' && function_exists('fn_eurosite_ensure_schema')) {
    $__eu_heal_fp = (string) @md5_file(__DIR__ . '/src/Install/SchemaMigrator.php');
    if (!function_exists('fn_travel_core_self_heal_due')
        || fn_travel_core_self_heal_due('eurosite_schema', $__eu_heal_fp)
    ) {
        if (function_exists('fn_travel_core_self_heal_guard')) {
            // Never let a heal 503 the admin — see fn_travel_core_self_heal_guard.
            fn_travel_core_self_heal_guard('eurosite_schema', 'fn_eurosite_ensure_schema');
        } else {
            fn_eurosite_ensure_schema();
        }
        if (function_exists('fn_travel_core_self_heal_stamp')) {
            fn_travel_core_self_heal_stamp('eurosite_schema', $__eu_heal_fp);
        }
    }
    unset($__eu_heal_fp);
}

// Language self-heal: UPSERT lang_keys.php into ?:language_values whenever
// the source fingerprint changes (novoton pattern, all areas). Admin menu
// labels and storefront strings must not depend on the install-time .po
// import — a linked dev store may never have run it.
//
// The sources are reached through LanguageSeeder, NOT through the
// fn_eurosite_language_* wrappers in func.php, so this block does not depend
// on whether CS-Cart includes func.php before or after init.php. That order is
// not stable across cores, and on one that loads init.php first a
// `function_exists('fn_eurosite_language_variables')` guard here is always
// false — which silently disables the heal on every request and leaves the
// admin reading raw keys ("_eurosite.countries"). fgo_invoicing was converted
// away from that guard for the same reason; this was the last addon still
// carrying it. The class resolves either way: the autoloader is registered
// above, in this file.
//
// fn_travel_core_* IS safe to probe: travel_core has a lower priority so it
// loads first, and its own init.php require_once's functions/self_heal.php.
if (
    function_exists('fn_travel_core_heal_language_keys')
    && function_exists('fn_travel_core_self_heal_guard')
) {
    fn_travel_core_self_heal_guard('eurosite_langs', static function (): void {
        fn_travel_core_heal_language_keys(
            'eurosite',
            static fn (): array => \Tygh\Addons\Eurosite\Install\LanguageSeeder::variables(),
            \Tygh\Addons\Eurosite\Install\LanguageSeeder::seedHash(),
        );
    });
}

// Register with the shared travel-provider registry: the normalizer (feature
// mapping / normalization pipeline), the booking-admin provider + status
// callbacks (unified travel_bookings grid), and the hotel-product provider
// (hotel-id ownership; Eurosite has no CS-Cart products yet, so product
// resolution intentionally answers null). Guarded against travel_core not
// being loaded.
if (class_exists(\Tygh\Addons\TravelCore\Services\TravelProviderRegistry::class)) {
    \Tygh\Addons\TravelCore\Services\TravelProviderRegistry::register(
        'eurosite',
        'Eurosite',
        new \Tygh\Addons\Eurosite\Api\EurositeNormalizer(),
    );
    \Tygh\Addons\TravelCore\Services\TravelProviderRegistry::setBookingAdminProvider(
        'eurosite',
        new \Tygh\Addons\Eurosite\Services\BookingAdminProvider(),
    );
    \Tygh\Addons\TravelCore\Services\TravelProviderRegistry::setHotelProductProvider(
        'eurosite',
        new \Tygh\Addons\Eurosite\Providers\EurositeHotelProductProvider(),
    );
    \Tygh\Addons\TravelCore\Services\TravelProviderRegistry::setStatusCallbacks(
        'eurosite',
        static function (): array {
            return (new \Tygh\Addons\Eurosite\Services\BookingStatusService())->syncAll();
        },
        static function (int $bookingId): array {
            return (new \Tygh\Addons\Eurosite\Services\BookingStatusService())->checkSingle($bookingId);
        },
    );
}

// Order-pipeline hooks (bodies in func.php).
fn_register_hooks(
    'pre_place_order',            // price-tamper guard on eurosite cart lines
    'place_order_post',           // link + submit bookings to the Eurosite API
    'user_login_post',            // claim guest bookings by session
    'create_user_post',           // claim bookings for new registrations
    'travel_link_order_bookings', // travel_core reconcile sweep
);
