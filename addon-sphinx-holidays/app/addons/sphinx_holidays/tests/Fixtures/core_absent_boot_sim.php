<?php
// Simulates CS-Cart's bootstrap with travel_core DISABLED.
//
// CS-Cart loads init.php only for ACTIVE addons, and the
// Tygh\Addons\TravelCore\* PSR-4 autoloader is registered by travel_core's
// own init.php — so when Core is disabled, no Core class can be resolved.
// This environment therefore registers NO autoloader at all: any runtime
// reference to a Core class from the file under test is a fatal, exactly as
// it would be inside fn_init_addons() on a real store.
//
// Mirrors travel_core's tests/Fixtures/install_environment_sim.php, which
// does the same job for the install request.
define('BOOTSTRAP', true);
define('AREA', 'C'); // storefront: the area a shop is serving when this bites

// CS-Cart core stubs. Tygh\Registry is core, not travel_core, so it IS
// available when Core is disabled — everything else here is a no-op.
eval('namespace Tygh; class Registry {
    public static function get($k) { return $k === "addons.sphinx_holidays.version" ? "2.4.1-beta" : null; }
    public static function set($k, $v): void {}
    public static function del($k): void {}
}');

function fn_register_hooks(...$a) {}
function fn_log_event(...$a) {}
function __($k, $vars = []) { return is_string($k) ? $k : 'L'; }

require $argv[1]; // the provider init.php under test

// Reached only if nothing fatalled. Report the constant so a silently-skipped
// version block cannot pass as success.
echo 'OK boot: version=' . (defined('SPHINX_HOLIDAYS_VERSION') ? SPHINX_HOLIDAYS_VERSION : '(undefined)') . "\n";
