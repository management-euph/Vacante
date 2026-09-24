<?php
declare(strict_types=1);
/***************************************************************************
 *                                                                          *
 *   (c) 2024-2026 VacanteLitoral.ro                                       *
 *                                                                          *
 *   Location: app/addons/sphinx_holidays/init.php                         *
 *                                                                          *
 ***************************************************************************/

use Tygh\Registry;

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

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
if (!defined('SPHINX_HOLIDAYS_VERSION')) {
    $__sv = Registry::get('addons.sphinx_holidays.version');
    $__sv = is_scalar($__sv) && (string) $__sv !== '' ? (string) $__sv : '0.0.0';
    define('SPHINX_HOLIDAYS_VERSION', preg_replace('/-.*$/', '', $__sv));
    unset($__sv);
}

// Register PSR-4 autoloader for sphinx_holidays namespace.
spl_autoload_register(function ($class) {
    $prefix = 'Tygh\\Addons\\SphinxHolidays\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }

    $relative = str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    $file = __DIR__ . '/src/' . $relative;

    if (file_exists($file)) {
        require $file;
    }
});

// Register with shared travel provider registry (guard against travel_core not being loaded)
if (class_exists(\Tygh\Addons\TravelCore\Services\TravelProviderRegistry::class)) {
    \Tygh\Addons\TravelCore\Services\TravelProviderRegistry::register(
        'sphinx',
        'Sphinx / Christian Tour',
        new \Tygh\Addons\SphinxHolidays\Api\SphinxNormalizer()
    );
    // This add-on's cron, for Travel Core -> Tools: its job types come from
    // the dispatcher, its runs from the shared CronRunLog, and the row links
    // to the page below. Declared HERE so Travel Core never hard-codes
    // another add-on's jobs — disable this add-on and its row disappears.
    \Tygh\Addons\TravelCore\Services\TravelProviderRegistry::setCron(
        'sphinx',
        'sphinx_holidays',
        \Tygh\Addons\SphinxHolidays\Cron\CronDispatcher::class,
        'sphinx_holidays.manage',
        'sphinx-cron-commands',
    );
    \Tygh\Addons\TravelCore\Services\TravelProviderRegistry::setBookingAdminProvider(
        'sphinx',
        new \Tygh\Addons\SphinxHolidays\Services\BookingAdminProvider()
    );
    \Tygh\Addons\TravelCore\Services\TravelProviderRegistry::setScanConfig(
        'sphinx', 'sphinx_hotels', 'hotel_id', 'facilities_json'
    );
    \Tygh\Addons\TravelCore\Services\TravelProviderRegistry::setHotelProductProvider(
        'sphinx',
        new \Tygh\Addons\SphinxHolidays\Providers\SphinxHotelProductProvider()
    );
    \Tygh\Addons\TravelCore\Services\TravelProviderRegistry::setStatusCallbacks(
        'sphinx',
        function () {
            $api = \Tygh\Addons\SphinxHolidays\Services\Container::getApi();
            $repo = \Tygh\Addons\SphinxHolidays\Services\Container::getBookingRepository();
            $service = new \Tygh\Addons\SphinxHolidays\Services\OrderStatusSyncService($api, $repo);
            return $service->syncAll();
        },
        function (int $bookingId) {
            $provider = new \Tygh\Addons\SphinxHolidays\Services\BookingAdminProvider();
            return $provider->checkStatus((string) $bookingId);
        }
    );
}

// Self-heal language keys. Existing installations don't re-run post_install
// when new settings/labels ship (e.g. require_immediate_availability), so
// labels stay empty until reinstall. The probe asks the database whether every
// INSTALLED language carries the current source fingerprint, rather than
// trusting one stamp row that a failed seed would have written regardless.
// The seeder is idempotent and also mirrors settings labels into
// ?:settings_descriptions.
//
// No AREA gate: it used to be admin-only, so a deploy followed by
// storefront-only traffic left customers looking at raw "_sphinx_holidays.*"
// keys. Guarded, because a throw inside fn_init_addons() is a 503 everywhere.
if (function_exists('fn_travel_core_heal_language_keys')) {
    fn_travel_core_self_heal_guard('sphinx_holidays_langs', static function (): void {
        // The class, NOT the fn_sphinx_holidays_language_* helpers: those live in
        // func.php, which init.php never loads, and older CS-Cart cores include
        // init.php FIRST. There the call hit an undefined function, the guard
        // swallowed the Error on every request, and no label added after
        // install ever reached the store.
        fn_travel_core_heal_language_keys(
            'sphinx_holidays',
            static fn (): array => \Tygh\Addons\SphinxHolidays\Install\LanguageSeeder::variables(),
            \Tygh\Addons\SphinxHolidays\Install\LanguageSeeder::seedHash(),
            static function (array $vars): void {
                \Tygh\Addons\SphinxHolidays\Install\LanguageSeeder::mirrorSettingsDescriptions($vars);
            },
        );
    });
}

// Self-heal SEO template defaults. On fresh installs the templates are empty
// until the admin opens the SEO Templates page and clicks Save. Probe the
// sentinel key and seed defaults if absent so products created via cron get
// rendered metadata from the very first add_products run.
if (defined('AREA') && AREA === 'A' && function_exists('fn_sphinx_holidays_seed_seo_defaults')) {
    if (\Tygh\Registry::get('addons.sphinx_holidays.seo_page_title') === null) {
        fn_sphinx_holidays_seed_seo_defaults();
    }
}

// Self-heal schema deltas on existing installs (mirrors travel_core). The
// addon.xml for="upgrade" ALTERs never auto-apply (version pinned, no upgrade
// scheme), so columns/indexes added after the initial install only reached
// sites via a manual deploy step — until this. Stamp-gated (travel_core
// helpers): the catalog reads + conditional ALTERs run once per deployed
// version of SchemaMigrator.php, then every admin request pays a single
// ?:storage_data read.
if (defined('AREA') && AREA === 'A' && function_exists('fn_sphinx_holidays_ensure_schema')) {
    $__spx_heal_fp = (string) @md5_file(__DIR__ . '/src/Install/SchemaMigrator.php');
    if (!function_exists('fn_travel_core_self_heal_due')
        || fn_travel_core_self_heal_due('sphinx_schema', $__spx_heal_fp)
    ) {
        if (function_exists('fn_travel_core_self_heal_guard')) {
            // Never let a heal 503 the admin — see fn_travel_core_self_heal_guard.
            fn_travel_core_self_heal_guard('sphinx_schema', 'fn_sphinx_holidays_ensure_schema');
        } else {
            fn_sphinx_holidays_ensure_schema();
        }
        if (function_exists('fn_travel_core_self_heal_stamp')) {
            fn_travel_core_self_heal_stamp('sphinx_schema', $__spx_heal_fp);
        }
    }
    unset($__spx_heal_fp);
}

// Register addon hooks
fn_register_hooks(
    'pre_place_order',                         // Re-verify Sphinx offer prices before order
    'place_order_post',                        // Submit booking to Sphinx API after order
    'calculate_cart_items',                     // Preserve stored price for Sphinx bookings
    'get_product_data_post',                   // Attach hotel data to Sphinx products
    'gather_additional_product_data_post',     // Pass booking form config to templates
    'user_login_post',                         // Link session bookings to logged-in user
    'create_user_post',                        // Link bookings to newly registered users
    'get_order_info',                          // Admin notification for failed bookings
    'travel_core_exchange_rates_updated',      // Log exchange rate sync to sphinx_sync_log
    'travel_link_order_bookings'               // Backfill: link historical bookings to their orders (Travel Tools reconcile button)
);
