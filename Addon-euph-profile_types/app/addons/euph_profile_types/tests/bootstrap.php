<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap for the euph_profile_types unit tests.
 *
 * The services under test reach the database only through the Database
 * interface, so no CS-Cart is needed: tests hand them a FakeDatabase.
 */

$vendorAutoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($vendorAutoload)) {
    require_once $vendorAutoload;
} else {
    spl_autoload_register(static function (string $class): void {
        foreach (['Tygh\\Addons\\EuphProfileTypes\\Tests\\' => __DIR__ . '/', 'Tygh\\Addons\\EuphProfileTypes\\' => dirname(__DIR__) . '/src/'] as $prefix => $dir) {
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
