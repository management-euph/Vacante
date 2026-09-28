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
