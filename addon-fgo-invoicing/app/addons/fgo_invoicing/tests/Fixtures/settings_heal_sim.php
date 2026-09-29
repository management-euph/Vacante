<?php

declare(strict_types=1);

/*
 * Simulates the requests in which the settings heal runs: CS-Cart has loaded
 * init.php (autoloader) and func.php, travel_core's functions/self_heal.php is
 * present (recording stand-ins below), and dispatch_before_display fires.
 * It fires TWICE, like two admin page loads, to show the stamp throttle.
 * Prints the recorded calls, the queries and the final settings as JSON.
 *
 * ?:settings_objects is an in-memory table seeded from the real addon.xml.
 * By default it is an existing store from before the CIF / Reg. Com. / CNP
 * selectors: those three rows are missing, and the merchant has cleared
 * shipping_code and api_max_retries and has a type that differs from
 * addon.xml on invoice_series. The stand-in migrator behaves like
 * SettingsMigrator::ensure(): it creates the missing rows AND "repairs" the
 * existing ones (the addon.xml default into every '' value, the declared
 * type) — exactly what the heal has to undo.
 *
 * argv[1]: the add-on root (app/addons/fgo_invoicing)
 * argv[2]: AREA ('A' admin, 'C' storefront)
 * argv[3]: 'ok' | 'no-travel-core' (travel_core not installed) |
 *          'ensure-throws' (the migrator fails) |
 *          'in-sync' (every declared row exists already)
 */

define('BOOTSTRAP', true);
define('AREA', $argv[2] ?? 'A');

$addonRoot = $argv[1];
$mode = $argv[3] ?? 'ok';
$GLOBALS['sim'] = ['calls' => [], 'queries' => [], 'storage' => [], 'settings' => [], 'declared' => []];

$typeMap = ['input' => 'I', 'checkbox' => 'C', 'selectbox' => 'S', 'header' => 'H', 'password' => 'P', 'textarea' => 'T'];
$xml = simplexml_load_file($addonRoot . '/addon.xml');
foreach ($xml->xpath('/addon/settings/sections/section/items/item[@id]') ?: [] as $item) {
    $name = (string) $item['id'];
    $type = $typeMap[(string) $item->type] ?? 'I';
    $default = (string) $item->default_value;
    $GLOBALS['sim']['declared'][$name] = ['type' => $type, 'default' => $default];
    if ($mode === 'in-sync' || !in_array($name, ['cif_field', 'reg_com_field', 'cnp_field'], true)) {
        $GLOBALS['sim']['settings'][$name] = ['type' => $type, 'value' => $default];
    }
}
// What the merchant changed on purpose, and must keep.
$GLOBALS['sim']['settings']['shipping_code']['value'] = '';
$GLOBALS['sim']['settings']['api_max_retries']['value'] = '';
$GLOBALS['sim']['settings']['invoice_series']['type'] = 'T';

if ($mode !== 'no-travel-core') {
    function fn_travel_core_store_path(string $relative): string
    {
        return '/store/' . trim($relative, '/');
    }

    function fn_travel_core_self_heal_due(string $key, string $fingerprint): bool
    {
        $GLOBALS['sim']['calls'][] = ['due', $key];

        return ($GLOBALS['sim']['storage'][$key] ?? null) !== $fingerprint;
    }

    function fn_travel_core_self_heal_stamp(string $key, string $fingerprint): void
    {
        $GLOBALS['sim']['calls'][] = ['stamp', $key];
        $GLOBALS['sim']['storage'][$key] = $fingerprint;
    }

    function fn_travel_core_self_heal_guard(string $key, callable $heal): void
    {
        $GLOBALS['sim']['calls'][] = ['guard', $key];
        try {
            $heal();
        } catch (\Throwable $e) {
            $GLOBALS['sim']['calls'][] = ['guard-caught', $e->getMessage()];
        }
    }

    function fn_travel_core_ensure_settings(string $addon, string $addonDir, string $langsDir): array
    {
        $GLOBALS['sim']['calls'][] = ['ensure', $addon, $addonDir, $langsDir];
        if ($GLOBALS['sim_mode'] === 'ensure-throws') {
            throw new \RuntimeException('Settings API unavailable');
        }

        $touched = [];
        foreach ($GLOBALS['sim']['declared'] as $name => $declared) {
            $row = $GLOBALS['sim']['settings'][$name] ?? null;
            if ($row === null) {
                // Settings::update() (no value) then updateValue(default)
                $GLOBALS['sim']['settings'][$name] = [
                    'type' => $declared['type'],
                    'value' => $declared['default'] !== '' ? $declared['default'] : null,
                ];
                $touched[] = $name;
                continue;
            }
            // repairValue() + repairType()
            if (($row['value'] ?? '') === '' && $declared['default'] !== '') {
                $GLOBALS['sim']['settings'][$name]['value'] = $declared['default'];
                $touched[] = $name;
            }
            if ($row['type'] !== $declared['type']) {
                $GLOBALS['sim']['settings'][$name]['type'] = $declared['type'];
                $touched[] = $name;
            }
        }

        return array_values(array_unique($touched));
    }
}
$GLOBALS['sim_mode'] = $mode;

function db_get_array(string $query, ...$params): array
{
    $query = preg_replace('/\s+/', ' ', trim($query));
    $GLOBALS['sim']['queries'][] = $query;

    if (str_starts_with($query, 'SELECT name, type, value FROM ?:settings_objects') && ($params[0] ?? '') === 'fgo_invoicing') {
        $rows = [];
        foreach ($GLOBALS['sim']['settings'] as $name => $row) {
            $rows[] = ['name' => $name, 'type' => $row['type'], 'value' => $row['value']];
        }

        return $rows;
    }

    return [];
}

function db_query(string $query, ...$params): int
{
    $query = preg_replace('/\s+/', ' ', trim($query));
    $GLOBALS['sim']['queries'][] = $query;

    // SettingsSnapshot::restore()
    if (str_starts_with($query, 'UPDATE ?:settings_objects SET value = ?s, type = ?s')) {
        [$value, $type, $name] = $params;
        $GLOBALS['sim']['settings'][$name] = ['type' => $type, 'value' => $value];

        return 1;
    }
    if (str_starts_with($query, 'UPDATE ?:settings_objects SET value = NULL, type = ?s')) {
        [$type, $name] = $params;
        $GLOBALS['sim']['settings'][$name] = ['type' => $type, 'value' => null];

        return 1;
    }

    return 0;
}

// The autoloader init.php registers.
spl_autoload_register(static function (string $class) use ($addonRoot): void {
    $prefix = 'Tygh\\Addons\\FgoInvoicing\\';
    if (strncmp($prefix, $class, strlen($prefix)) === 0) {
        $file = $addonRoot . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

require $addonRoot . '/func.php';
require $addonRoot . '/hooks/settings_hooks.php';

fn_fgo_invoicing_dispatch_before_display();
fn_fgo_invoicing_dispatch_before_display();

echo json_encode([
    'calls' => $GLOBALS['sim']['calls'],
    'queries' => $GLOBALS['sim']['queries'],
    'settings' => $GLOBALS['sim']['settings'],
]), "\n";
