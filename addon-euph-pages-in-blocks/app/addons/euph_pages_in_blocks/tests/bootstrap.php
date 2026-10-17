<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap for the euph_pages_in_blocks tests.
 *
 * The services reach CS-Cart only through what they are handed (closures,
 * the Database interface), so no CS-Cart is needed. The boundary functions
 * (functions/*.php) call a few CS-Cart functions, stubbed in
 * Support/stubs.php with what the tests put in Support\CsCart. The
 * templates are compiled and rendered by Smarty 5, the engine of CS-Cart
 * 4.19 and later, with CS-Cart's own tags emulated (Support\TemplateHarness).
 */

$vendorAutoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($vendorAutoload)) {
    require_once $vendorAutoload;
} else {
    spl_autoload_register(static function (string $class): void {
        foreach (['Tygh\\Addons\\EuphPagesInBlocks\\Tests\\' => __DIR__ . '/', 'Tygh\\Addons\\EuphPagesInBlocks\\' => dirname(__DIR__) . '/src/'] as $prefix => $dir) {
            if (strncmp($prefix, $class, strlen($prefix)) === 0) {
                $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                if (is_file($file)) {
                    require_once $file;
                }

                return;
            }
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
