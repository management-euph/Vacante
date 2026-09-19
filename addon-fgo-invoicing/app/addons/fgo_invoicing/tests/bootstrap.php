<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap for fgo_invoicing unit tests.
 *
 * Provides:
 *   - Composer autoload (PSR-4 for Tygh\Addons\FgoInvoicing\)
 *   - Minimal CS-Cart function stubs so addon classes can be instantiated
 *     without the full CS-Cart framework.
 */

$vendorAutoload = dirname(__DIR__) . '/vendor/autoload.php';
if (file_exists($vendorAutoload)) {
    require_once $vendorAutoload;
} else {
    // Fallback: register PSR-4 manually so unit tests work without composer install
    spl_autoload_register(static function (string $class): void {
        $prefix = 'Tygh\\Addons\\FgoInvoicing\\';
        if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
            return;
        }
        $relative = str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        foreach ([dirname(__DIR__) . '/src/', dirname(__DIR__) . '/'] as $base) {
            $file = $base . $relative;
            if (file_exists($file)) {
                require_once $file;
                return;
            }
        }
    });
    spl_autoload_register(static function (string $class): void {
        $prefix = 'Tygh\\Addons\\FgoInvoicing\\Tests\\';
        if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
            return;
        }
        $relative = str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        $file = __DIR__ . '/' . $relative;
        if (file_exists($file)) {
            require_once $file;
        }
    });
}

if (!defined('BOOTSTRAP')) {
    define('BOOTSTRAP', true);
}

// ── CS-Cart function stubs ───────────────────────────────────────────────
// The db_* stubs delegate to Tests\Support\DbStub so a test can program the
// rows they return and assert on the statements the code under test issued.
// Unprogrammed, they behave exactly like the inert stubs they replaced.
if (!function_exists('fn_log_event')) {
    function fn_log_event(string $type, string $action, array $data = []): void
    {
        // no-op in tests
    }
}
if (!function_exists('db_get_field')) {
    function db_get_field(string $query, ...$params): mixed
    {
        \Tygh\Addons\FgoInvoicing\Tests\Support\DbStub::record($query, $params);

        return \Tygh\Addons\FgoInvoicing\Tests\Support\DbStub::$field;
    }
}
if (!function_exists('db_get_row')) {
    function db_get_row(string $query, ...$params): array|false
    {
        \Tygh\Addons\FgoInvoicing\Tests\Support\DbStub::record($query, $params);

        return \Tygh\Addons\FgoInvoicing\Tests\Support\DbStub::$row;
    }
}
if (!function_exists('db_get_array')) {
    function db_get_array(string $query, ...$params): array
    {
        \Tygh\Addons\FgoInvoicing\Tests\Support\DbStub::record($query, $params);

        return \Tygh\Addons\FgoInvoicing\Tests\Support\DbStub::$rows;
    }
}
if (!function_exists('db_query')) {
    function db_query(string $query, ...$params): int
    {
        \Tygh\Addons\FgoInvoicing\Tests\Support\DbStub::record($query, $params);

        return 0;
    }
}
if (!function_exists('db_replace_into')) {
    function db_replace_into(string $table, array $data): int
    {
        \Tygh\Addons\FgoInvoicing\Tests\Support\DbStub::record('REPLACE INTO ' . $table, [$data]);

        return 1;
    }
}

// ── Tygh\Registry stub ───────────────────────────────────────────────────
if (!class_exists(\Tygh\Registry::class)) {
    eval('
    namespace Tygh;
    class Registry {
        /** @var array<string, mixed> */
        private static array $data = [];
        public static function get(string $key) {
            return self::$data[$key] ?? null;
        }
        public static function set(string $key, $value): void {
            self::$data[$key] = $value;
        }
        public static function clear(): void {
            self::$data = [];
        }
    }
    ');
}
