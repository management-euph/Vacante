<?php
// Simulates CS-Cart's INSTALL request environment for travel_core:
// func.php is loaded, init.php is NOT (addon not active => no PSR-4
// autoloader), then the settings-variants callbacks are invoked.
define('BOOTSTRAP', true);
define('DESCR_SL', 'en');
function __($k, $vars = []) { return is_string($k) ? $k : 'L'; }
function db_get_array($q, ...$a) { return [['feature_id' => 5, 'feature_type' => 'S', 'description' => 'Stars']]; }
function db_get_fields($q, ...$a) { return []; }
function db_get_field($q, ...$a) { return null; }
function db_query($q, ...$a) { return true; }
function fn_set_notification(...$a) {}
// Tygh\\Registry stub (eval keeps this file single-namespace)
// Tygh\Registry stub with currencies so default_currency exercises its loop
eval('namespace Tygh; class Registry { public static function get($k){ return $k === "currencies" ? ["EUR"=>["symbol"=>"€"],"RON"=>["symbol"=>"lei"]] : null; } public static function set($k,$v):void{} public static function del($k):void{} }');
require $argv[1]; // the func.php under test
$v1 = fn_settings_variants_addons_travel_core_default_currency();
$v2 = fn_settings_variants_addons_travel_core_feature_id_property_rating();
$v3 = fn_settings_variants_addons_travel_core_skip_cart_for();
echo "OK variants: currency=" . count($v1) . " features=" . count($v2) . " skip_cart_for=" . implode(',', array_keys($v3)) . "\n";
