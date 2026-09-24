<?php
// Boots addon init.php files the way an OLDER CS-Cart core does: init.php is
// included and func.php is NOT (older cores include init.php first; func.php
// has not been loaded yet when init.php's self-heals run). Then prints, as
// JSON, which language rows the language heals wrote and every heal failure
// that was reported.
//
// That load order is the one that left the store rendering
// "_travel_core.tools_cron_key_title": the heal called a func.php function
// that did not exist yet, the self-heal guard swallowed the Error, and nothing
// was written — on every request, with no visible error.
//
// argv[1..n]: init.php files to boot, in addon priority order (travel_core
// first — the providers' heals use its fn_travel_core_* helpers).

declare(strict_types=1);

define('BOOTSTRAP', true);
define('AREA', 'C');
define('CART_LANGUAGE', 'en');
define('DESCR_SL', 'en');

$GLOBALS['sim_lang'] = [];     // name => [lang_code => value]
$GLOBALS['sim_storage'] = [];
$GLOBALS['sim_events'] = [];

// ── A tiny ?:language_values / ?:languages, just enough for LanguageDelivery ──
function db_get_fields($q, ...$a) {
    return str_contains($q, '?:languages') ? ['en', 'ro'] : [];
}
function db_get_array($q, ...$a) {
    if (str_contains($q, '?:language_values') && str_contains($q, 'WHERE name = ?s')) {
        $out = [];
        foreach ($GLOBALS['sim_lang'][$a[0]] ?? [] as $code => $value) {
            $out[] = ['lang_code' => $code, 'value' => $value];
        }
        return $out;
    }
    return [];
}
function db_get_field($q, ...$a) {
    if (str_contains($q, '?:language_values') && str_contains($q, 'lang_code = ?s')) {
        return $GLOBALS['sim_lang'][$a[0]][$a[1]] ?? null;
    }
    return null;
}
function db_get_row($q, ...$a) { return []; }
function db_get_hash_array($q, ...$a) { return []; }
function db_query($q, ...$a) {
    if (str_starts_with($q, 'INSERT INTO ?:language_values')) {
        [$name, $lang, $value] = $a;
        $GLOBALS['sim_lang'][$name][$lang] = $value;
    }
    return true;
}
function fn_get_storage_data($k) { return $GLOBALS['sim_storage'][$k] ?? null; }
function fn_set_storage_data($k, $v) { $GLOBALS['sim_storage'][$k] = $v; }
function fn_log_event($type, $action, $data = []) { $GLOBALS['sim_events'][] = $data['message'] ?? json_encode($data); }
function fn_register_hooks(...$a) {}
function fn_clear_cache(...$a) {}
function fn_set_notification(...$a) {}
function __($k, $vars = []) { return is_string($k) ? $k : ''; }

eval('namespace Tygh; class Registry {
    private static array $d = [];
    public static function get($k) { return self::$d[$k] ?? null; }
    public static function set($k, $v): void { self::$d[$k] = $v; }
    public static function del($k): void { unset(self::$d[$k]); }
}');
eval('namespace Tygh; class Tygh { public static $app = ["session" => []]; }');

foreach (array_slice($argv, 1) as $init) {
    require $init;
}

echo json_encode([
    'written' => array_keys($GLOBALS['sim_lang']),
    'heal_failures' => $GLOBALS['sim_events'],
    'func_php_loaded' => function_exists('fn_travel_core_language_variables'),
], JSON_THROW_ON_ERROR);
