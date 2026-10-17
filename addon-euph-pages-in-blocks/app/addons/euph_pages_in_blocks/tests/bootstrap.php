<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap for the euph_pages_in_blocks tests.
 *
 * No CS-Cart is needed: the few CS-Cart functions func.php calls are
 * stubbed in Support/stubs.php, with what the tests put in Support\CsCart.
 * The templates are compiled and rendered by Smarty 5, the engine of
 * CS-Cart 4.19 and later, with CS-Cart's own tags emulated
 * (Support\TemplateHarness).
 */

$vendorAutoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($vendorAutoload)) {
    require_once $vendorAutoload;
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'Tygh\\Addons\\EuphPagesInBlocks\\Tests\\';
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (str_starts_with($class, $prefix) && is_file($file)) {
            require_once $file;
        }
    });
}

if (!defined('BOOTSTRAP')) {
    define('BOOTSTRAP', true);
}
if (!defined('CART_LANGUAGE')) {
    define('CART_LANGUAGE', 'en');
}

require_once __DIR__ . '/Support/stubs.php';
require_once dirname(__DIR__) . '/func.php';
