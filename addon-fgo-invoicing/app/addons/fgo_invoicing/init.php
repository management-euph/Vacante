<?php

declare(strict_types=1);

use Tygh\Registry;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

if (!defined('FGO_INVOICING_VERSION')) {
    $__fgo_v_raw = Registry::get('addons.fgo_invoicing.version');
    $__fgo_v = is_string($__fgo_v_raw) && $__fgo_v_raw !== '' ? $__fgo_v_raw : '0.0.0';
    define('FGO_INVOICING_VERSION', preg_replace('/-.*$/', '', $__fgo_v) ?? '0.0.0');
    unset($__fgo_v, $__fgo_v_raw);
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'Tygh\\Addons\\FgoInvoicing\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    $file = __DIR__ . '/src/' . $relative;
    if (file_exists($file)) {
        require $file;
        return;
    }
    $file = __DIR__ . '/' . $relative;
    if (file_exists($file)) {
        require $file;
    }
});

require_once __DIR__ . '/hooks.php';

// Self-heal language keys. CS-Cart imports addon language variables only at
// INSTALL time, so a store installed while the .po was missing or unparseable
// renders every settings label blank and every admin string as a raw key, and
// no amount of fixing the files afterwards repairs it on its own.
//
// Routed through travel_core's healer when that addon is present, so we
// inherit its stamp throttle and per-language read-back verification. fgo is
// horizontal and must also install without travel_core, hence function_exists
// rather than a hard <dependencies> edge — see LanguageSeeder's docblock for
// what still protects us on a travel_core-less store.
//
// No AREA gate (the e-mail strings are needed on the storefront too) and
// guarded, because a throw inside fn_init_addons() is a 503 everywhere.
//
// The seed sources are reached through LanguageSeeder, NOT through the
// fn_fgo_invoicing_* wrappers in func.php, so this block does not depend on
// whether CS-Cart loads func.php before or after init.php. That order is not
// stable across cores: fn_load_addon() in older CS-Cart includes init.php
// FIRST, which would make a `function_exists('fn_fgo_invoicing_*')` guard here
// always false and silently disable the heal on every request. The sibling
// addons call their own func.php helpers from init.php and work on 4.20, so
// 4.20 evidently loads func.php first — but relying on that is a portability
// trap we simply avoid. The class is reachable either way because the
// autoloader is registered above, in this file.
//
// fn_travel_core_* IS safe to probe here: travel_core has a lower priority so
// it loads first, and its own init.php require_once's functions/self_heal.php.
if (
    function_exists('fn_travel_core_heal_language_keys')
    && function_exists('fn_travel_core_self_heal_guard')
) {
    fn_travel_core_self_heal_guard('fgo_invoicing_langs', static function (): void {
        fn_travel_core_heal_language_keys(
            'fgo_invoicing',
            static fn (): array => \Tygh\Addons\FgoInvoicing\Install\LanguageSeeder::variables(),
            \Tygh\Addons\FgoInvoicing\Install\LanguageSeeder::seedHash(),
            static function (array $vars): void {
                \Tygh\Addons\FgoInvoicing\Install\LanguageSeeder::mirrorSettingsDescriptions($vars);
            },
        );
    });
}

// dispatch_before_display runs the SETTINGS self-heal (see
// functions/settings_heal.php): it cannot run from this file, which CS-Cart
// requires before CART_LANGUAGE exists and the Settings API needs it.
fn_register_hooks(
    'place_order_post',
    'change_order_status',
    'get_order_info',
    'get_orders_post',
    'profile_fields_get_fields',
    'update_profile_fields_post',
    'dispatch_before_display',
);
