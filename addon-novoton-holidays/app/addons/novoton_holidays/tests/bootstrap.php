<?php
declare(strict_types=1);
/**
 * PHPUnit bootstrap for Novoton Holidays unit tests.
 *
 * Provides minimal CS-Cart function stubs so that addon classes can be
 * instantiated without the full CS-Cart framework.
 *
 * @package NovotonHolidays\Tests
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

// ── Cross-addon PSR-4 autoloader ───────────────────────────────────────────
// Travel_core and Sphinx classes referenced from novoton code (e.g.
// CommissionCalculator, RoomType, BoardType) aren't in novoton's
// vendor/autoload. Register a simple PSR-4 loader for the sibling addons.
spl_autoload_register(function (string $class): void {
    $map = [
        'Tygh\\Addons\\TravelCore\\'     => dirname(__DIR__, 5) . '/addon-travel-core/app/addons/travel_core/src/',
        'Tygh\\Addons\\SphinxHolidays\\' => dirname(__DIR__, 5) . '/addon-sphinx-holidays/app/addons/sphinx_holidays/src/',
    ];
    foreach ($map as $prefix => $baseDir) {
        if (str_starts_with($class, $prefix)) {
            $relative = substr($class, strlen($prefix));
            $file = $baseDir . str_replace('\\', '/', $relative) . '.php';
            if (file_exists($file)) {
                require_once $file;
            }
            return;
        }
    }
});

// ── CS-Cart path constants ──────────────────────────────────────────────────
if (!defined('DIR_ROOT')) {
    define('DIR_ROOT', sys_get_temp_dir() . '/novoton_test_root');
    if (!is_dir(DIR_ROOT)) {
        @mkdir(DIR_ROOT, 0755, true);
    }
}

// ── CS-Cart function stubs ──────────────────────────────────────────────────
// Only define stubs for functions that are referenced at class-load time
// or in the specific methods under test. Tests that need different behaviour
// should use function mocking or override via Closure.

if (!function_exists('__')) {
    function __(string $key): string
    {
        return $key;
    }
}

if (!function_exists('fn_log_event')) {
    function fn_log_event(string $type, string $action, array $data = []): void
    {
        // no-op in tests
    }
}

if (!function_exists('fn_csrf_validate_request')) {
    function fn_csrf_validate_request(array $params): bool
    {
        return false;
    }
}

if (!function_exists('fn_get_order_info')) {
    /** @return array<string, mixed> */
    function fn_get_order_info(int $order_id): array
    {
        $fn = \Tygh\Addons\NovotonHolidays\Tests\Support\DbStub::$getOrderInfo;
        return $fn !== null ? $fn($order_id) : [];
    }
}

// db_* helpers route through DbStub when a closure is configured. Defaults
// (null / [] / 0) match the original bare stubs, so tests that don't touch the
// DB keep working unchanged.

if (!function_exists('db_get_field')) {
    function db_get_field(string $query, ...$params)
    {
        $fn = \Tygh\Addons\NovotonHolidays\Tests\Support\DbStub::$getField;
        return $fn !== null ? $fn($query, ...$params) : null;
    }
}

if (!function_exists('db_query')) {
    function db_query(string $query, ...$params)
    {
        $fn = \Tygh\Addons\NovotonHolidays\Tests\Support\DbStub::$query;
        return $fn !== null ? $fn($query, ...$params) : 0;
    }
}

if (!function_exists('db_get_row')) {
    function db_get_row(string $query, ...$params)
    {
        $fn = \Tygh\Addons\NovotonHolidays\Tests\Support\DbStub::$getRow;
        return $fn !== null ? $fn($query, ...$params) : [];
    }
}

if (!function_exists('db_get_array')) {
    function db_get_array(string $query, ...$params)
    {
        $fn = \Tygh\Addons\NovotonHolidays\Tests\Support\DbStub::$getArray;
        return $fn !== null ? $fn($query, ...$params) : [];
    }
}

if (!function_exists('db_get_fields')) {
    function db_get_fields(string $query, ...$params)
    {
        $fn = \Tygh\Addons\NovotonHolidays\Tests\Support\DbStub::$getFields;
        return $fn !== null ? $fn($query, ...$params) : [];
    }
}

if (!function_exists('db_get_hash_single_array')) {
    /** @param array<int, string> $keys */
    function db_get_hash_single_array(string $query, array $keys, ...$params)
    {
        $fn = \Tygh\Addons\NovotonHolidays\Tests\Support\DbStub::$getHashSingleArray;
        return $fn !== null ? $fn($query, $keys, ...$params) : [];
    }
}

// db_quote interpolates parameters into a SQL fragment and returns the string.
// CS-Cart isn't loaded in tests, so emulate enough of it (left-to-right
// placeholder substitution) for repositories that build conditional WHERE
// fragments. Strings are single-quoted; arrays become comma-joined lists.
if (!function_exists('db_quote')) {
    function db_quote(string $query, ...$params): string
    {
        $i = 0;

        return (string) preg_replace_callback(
            '/\?[sidanp]/',
            static function (array $m) use (&$i, $params): string {
                $value = $params[$i] ?? null;
                $i++;
                // ?p is a ready SQL fragment and ?a a list of quoted
                // strings, as in CS-Cart.
                if ($m[0] === '?p') {
                    return is_scalar($value) ? (string) $value : '';
                }
                if ($m[0] === '?a' && is_array($value)) {
                    return implode(', ', array_map(static fn ($v): string => "'" . (is_scalar($v) ? (string) $v : '') . "'", $value));
                }
                if (is_array($value)) {
                    return implode(',', array_map(static fn ($v): string => is_scalar($v) ? (string) $v : '', $value));
                }
                if (is_string($value)) {
                    return "'" . $value . "'";
                }
                return is_scalar($value) ? (string) $value : '';
            },
            $query,
        );
    }
}

// ── CS-Cart Registry stub ───────────────────────────────────────────────────
if (!class_exists(\Tygh\Registry::class)) {
    // Minimal stub — tests that need specific registry values should
    // set them via Registry::set() before exercising the SUT.
    eval('
    namespace Tygh;
    class Registry {
        private static array $data = [];
        public static function get(string $key) {
            return self::$data[$key] ?? null;
        }
        public static function set(string $key, $value): void {
            self::$data[$key] = $value;
        }
        public static function del(string $key): void {
            unset(self::$data[$key]);
        }
    }
    ');
}

// ── Tygh stub (session container) ─────────────────────────────────────────
// PriceChangeDetector stores alerts in Tygh::$app['session'].
if (!class_exists(\Tygh\Tygh::class)) {
    eval('
    namespace Tygh;
    class Tygh {
        /** @var array */
        public static $app = ["session" => []];
    }
    ');
}

// ── ConfigProvider stub ─────────────────────────────────────────────────────
// SecurityService::getEncryptionKey() calls ConfigProvider::getApiKey().
// Provide a minimal stub if the real class isn't autoloaded.
if (!class_exists(\Tygh\Addons\NovotonHolidays\Services\ConfigProvider::class)) {
    eval('
    namespace Tygh\Addons\NovotonHolidays\Services;
    class ConfigProvider {
        public static function getApiKey(): string { return "test-key-for-unit-tests"; }
        public static function isDebugLogging(): bool { return false; }
    }
    ');
}
