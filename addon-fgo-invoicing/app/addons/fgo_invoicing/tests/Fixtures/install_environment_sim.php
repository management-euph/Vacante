<?php

declare(strict_types=1);

/*
 * Simulates the request in which CS-Cart builds the settings form of an
 * add-on that is not active yet (fn_get_schema(..., force_addon_init = true)):
 * func.php is loaded, init.php is NOT — so there is no
 * Tygh\Addons\FgoInvoicing\* autoloader — and then the settings-variants
 * callbacks are called. Prints what they returned as JSON.
 *
 * argv[1]: the func.php under test
 * argv[2]: 'missing-key' (default; __() behaves as for a key the store never
 *          received) or 'translated'
 */

define('BOOTSTRAP', true);
define('DESCR_SL', 'ro');

$GLOBALS['fgo_sim_queries'] = [];
$GLOBALS['fgo_sim_mode'] = $argv[2] ?? 'missing-key';

function __($key, $params = [])
{
    return $GLOBALS['fgo_sim_mode'] === 'translated' ? 'Detectare automată' : '_' . $key;
}

function db_get_array($query, ...$params)
{
    $GLOBALS['fgo_sim_queries'][] = ['query' => $query, 'params' => $params];

    return [
        // a "billing and shipping" field: B row + its S twin
        ['field_id' => '36', 'field_name' => '', 'section' => 'B', 'matching_id' => '37', 'description' => 'CIF'],
        ['field_id' => '37', 'field_name' => '', 'section' => 'S', 'matching_id' => '36', 'description' => 'CIF'],
        // no description in the admin language: the code, then a generic label
        ['field_id' => '40', 'field_name' => 'cnp', 'section' => 'C', 'matching_id' => '0', 'description' => ''],
        ['field_id' => '41', 'field_name' => '', 'section' => 'C', 'matching_id' => '0', 'description' => null],
        // a shipping-only field (no twin) stays listed
        ['field_id' => '42', 'field_name' => '', 'section' => 'S', 'matching_id' => '0', 'description' => 'Nr. Reg. Com.'],
        ['field_id' => '0', 'field_name' => '', 'section' => 'C', 'matching_id' => '0', 'description' => 'broken'],
    ];
}

require $argv[1];

echo json_encode([
    'cif' => fn_settings_variants_addons_fgo_invoicing_cif_field(),
    'reg' => fn_settings_variants_addons_fgo_invoicing_reg_com_field(),
    'cnp' => fn_settings_variants_addons_fgo_invoicing_cnp_field(),
    'queries' => $GLOBALS['fgo_sim_queries'],
    'autoloaded' => class_exists('Tygh\\Addons\\FgoInvoicing\\Helpers\\TypeCoerce', false),
], JSON_UNESCAPED_UNICODE), "\n";
