<?php

/**
 * NETOPIA Payments addon initialization.
 *
 * @package NetopiaPayments
 * @author  NETOPIA Payments
 */

if (!defined('BOOTSTRAP')) {
    die('Access denied');
}

// Register the stand-alone PSR-4 autoloader for Netopia\CsCart\*,
// Netopia\Payment2\* and Psr\Log\* classes bundled under lib/.
require_once __DIR__ . '/autoload.php';

fn_register_hooks(
    'update_payment_post',
    'change_order_status',
);

// Runtime self-heal for merchants whose install lifecycle didn't seed
// the payment_info field labels (typically file-level addon updates
// that bypass CS-Cart's uninstall/reinstall cycle).
//
// We deliberately DO NOT go through Bootstrap::instance() here:
// Bootstrap eagerly calls fn_url() to pre-compute payment URLs, and
// fn_url() reads CART_LANGUAGE — which isn't defined this early in
// the CS-Cart bootstrap order. Using Bootstrap at init.php time
// fatals with "Undefined constant CART_LANGUAGE" and locks the admin
// out of the site. Seeder::forRuntime() needs only the db helpers.
//
// Wrap the whole call in a catch-all so that no failure in our seeder
// — whatever its cause — can ever prevent the rest of the request
// from proceeding.
try {
    Netopia\CsCart\Install\Seeder::forRuntime()->ensureSeeded();
} catch (\Throwable $e) {
    // Swallow deliberately; the Seeder already catches its own errors,
    // this is a belt-and-braces guard against any future regression.
}

// Language variables added by a `git pull` deploy: CS-Cart imports the
// addon's .po only at install, so load any new ones (admin requests only,
// and only when the .po files changed — see LangPackSync).
// @phpstan-ignore identical.alwaysTrue (AREA is per request; the stub pins it to 'A')
if (defined('AREA') && AREA === 'A' && function_exists('fn_get_storage_data') && function_exists('fn_set_storage_data')) {
    try {
        $netopia_langs_dir = Tygh\Registry::get('config.dir.lang_packs');
        if (!is_string($netopia_langs_dir)) {
            $netopia_root = defined('DIR_ROOT') ? constant('DIR_ROOT') : '';
            $netopia_langs_dir = is_string($netopia_root) && $netopia_root !== '' ? $netopia_root . '/var/langs/' : '';
            unset($netopia_root);
        }
        if ($netopia_langs_dir !== '') {
            (new Netopia\CsCart\Install\LangPackSync(
                langsDir:           $netopia_langs_dir,
                dbQuery:            static fn (string $sql, mixed ...$params): mixed => db_query($sql, ...$params),
                installedLanguages: static fn (): array => array_values(array_filter(
                    array_map(static fn (mixed $c): string => is_string($c) ? $c : '', (array) db_get_fields('SELECT lang_code FROM ?:languages')),
                    static fn (string $c): bool => $c !== '',
                )),
                readStamp:          static fn (string $key): string => is_string($v = fn_get_storage_data($key)) ? $v : '',
                writeStamp:         static function (string $key, string $value): void {
                    fn_set_storage_data($key, $value);
                },
            ))->syncIfChanged();
        }
        unset($netopia_langs_dir);
    } catch (\Throwable $e) {
        // Never block the admin over a language sync.
    }
}
