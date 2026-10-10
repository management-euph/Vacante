<?php
// Simulates CS-Cart's INSTALL request for sphinx_holidays.
//
// travel_core is a declared dependency, so it is installed and active: its
// init.php ran and the Tygh\Addons\TravelCore\* autoloader exists. This
// add-on is not active yet, so ITS init.php has not run. CS-Cart loads
// func.php alone, calls the settings-variants callbacks while it builds the
// settings, then the post-install function.
//
// Mirrors travel_core's tests/Fixtures/install_environment_sim.php.
//
// argv[1] = the func.php under test, argv[2] = travel_core's src/ folder.
define('BOOTSTRAP', true);
define('DESCR_SL', 'en');

$coreSrc = rtrim($argv[2], '/') . '/';
spl_autoload_register(static function (string $class) use ($coreSrc): void {
    $prefix = 'Tygh\\Addons\\TravelCore\\';
    if (strncmp($prefix, $class, strlen($prefix)) === 0) {
        $file = $coreSrc . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

// CS-Cart core stubs.
function __($k, $vars = []) { return is_string($k) ? $k : 'L'; }
function db_get_array($q, ...$a) { return [['category_id' => 7, 'id_path' => '7']]; }
function db_get_hash_single_array($q, ...$a) { return [7 => 'Hotels']; }
function db_get_field($q, ...$a) { return null; }
function db_get_fields($q, ...$a) { return []; }
function db_query($q, ...$a) { return true; }
function fn_set_notification(...$a) {}
eval('namespace Tygh; class Registry { public static function get($k){ return $k === "currencies" ? ["EUR"=>["symbol"=>"€"]] : null; } public static function set($k,$v):void{} public static function del($k):void{} }');

require $argv[1];

$counts = [];
foreach (['default_currency', 'product_languages', 'hotels_category_id', 'packages_category_id',
    'circuits_category_id', 'experiences_category_id'] as $setting) {
    $counts[] = $setting . '=' . count(('fn_settings_variants_addons_sphinx_holidays_' . $setting)());
}
echo 'OK variants: ' . implode(' ', $counts) . "\n";

// Every class of this add-on that func.php names, post_install's included,
// must resolve without init.php. Loading a class runs no queries.
preg_match_all('/\\\\?(Tygh\\\\Addons\\\\SphinxHolidays\\\\[A-Za-z0-9_\\\\]+)/', (string) file_get_contents($argv[1]), $m);
$classes = array_unique($m[1]);
foreach ($classes as $class) {
    if (!class_exists($class) && !interface_exists($class)) {
        echo "MISSING {$class}\n";
        exit(1);
    }
}
echo 'OK classes: ' . count($classes) . "\n";
