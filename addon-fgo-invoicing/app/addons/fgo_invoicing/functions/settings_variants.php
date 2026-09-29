<?php

declare(strict_types=1);

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

// SELF-SUFFICIENT ON PURPOSE: CS-Cart builds the settings form of an add-on
// that is not active yet (fn_get_schema('settings', 'variants.functions', ...,
// force_addon_init = true), e.g. right after "Install") by including func.php
// WITHOUT init.php — and init.php is the only registrar of the
// Tygh\Addons\FgoInvoicing\* autoloader. Nothing in this file may therefore
// touch an add-on class: core functions only. travel_core shipped a "click
// Install, nothing happens" fatal from exactly this. Guarded by
// FuncSelfSufficiencyTest.

/**
 * Options of the cif_field / reg_com_field / cnp_field selectors.
 *
 * The store's custom TEXT profile fields as "<description> (#<id>)", after an
 * empty-value "Auto-detect by field name" entry (the default). Text types
 * only (input, textarea — keep in sync with
 * ProfileFieldRepository::TEXT_FIELD_TYPES): a selectbox stores a variant id,
 * a checkbox Y/N, a date a timestamp, none of which can hold a tax id.
 *
 * Customer fields only (profile_type 'U', as ProfileFieldRepository): a
 * Multi-Vendor seller field labelled "CIF" never carries an order's value.
 *
 * Shipping twins are left out. A field created in the "billing and shipping"
 * section exists twice (a B row and an S row pointing at each other through
 * matching_id), and both would show the same label; the billing one is what
 * the customer fills in for the invoice.
 *
 * @return array<int|string, string> field_id => label, '' => auto-detect
 */
function fn_fgo_invoicing_profile_field_variants(): array
{
    // Three selectors render on one settings page: one query, not three.
    /** @var array<int|string, string>|null $variants */
    static $variants = null;
    if (is_array($variants)) {
        return $variants;
    }

    $variants = ['' => fn_fgo_invoicing_auto_detect_label()];
    if (!function_exists('db_get_array')) {
        return $variants;
    }

    // DESCR_SL is the admin's content language; the constants are read with
    // constant() because this file also runs from partial bootstraps.
    $lang = defined('DESCR_SL') ? constant('DESCR_SL') : (defined('CART_LANGUAGE') ? constant('CART_LANGUAGE') : 'en');
    $lang = is_string($lang) && $lang !== '' ? $lang : 'en';

    $rows = db_get_array(
        "SELECT f.field_id, f.field_name, f.section, f.matching_id, d.description
         FROM ?:profile_fields AS f
         LEFT JOIN ?:profile_field_descriptions AS d
                ON d.object_id = f.field_id AND d.object_type = 'F' AND d.lang_code = ?s
         WHERE f.is_default = 'N' AND f.profile_type = 'U' AND f.field_type IN (?a)
         ORDER BY f.position, f.field_id",
        $lang,
        ['I', 'T'],
    );

    foreach (is_array($rows) ? $rows : [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $rawId = $row['field_id'] ?? null;
        $rawMatching = $row['matching_id'] ?? null;
        $rawSection = $row['section'] ?? null;
        $rawDescription = $row['description'] ?? null;
        $rawCode = $row['field_name'] ?? null;

        $id = is_numeric($rawId) ? (int) $rawId : 0;
        $matchingId = is_numeric($rawMatching) ? (int) $rawMatching : 0;
        $section = is_string($rawSection) ? strtoupper($rawSection) : '';
        if ($id <= 0 || ($section === 'S' && $matchingId > 0)) {
            continue;
        }
        $description = is_string($rawDescription) ? trim($rawDescription) : '';
        $code = is_string($rawCode) ? trim($rawCode) : '';
        $label = $description !== '' ? $description : ($code !== '' ? $code : 'Profile field');
        $variants[$id] = $label . ' (#' . $id . ')';
    }

    return $variants;
}

/**
 * Label of the empty "Auto-detect by field name" option.
 *
 * The language key reaches a store installed before it existed only through
 * the travel_core language heal; until then CS-Cart renders a missing key as
 * "_<key>". The English text is a better label than that.
 */
function fn_fgo_invoicing_auto_detect_label(): string
{
    $key = 'fgo_invoicing.profile_field_auto_detect';
    $label = function_exists('__') ? __($key) : null;
    if (!is_string($label) || trim($label) === '' || $label === $key || $label === '_' . $key) {
        return 'Auto-detect by field name';
    }

    return $label;
}

/** @return array<int|string, string> */
function fn_settings_variants_addons_fgo_invoicing_cif_field(): array
{
    return fn_fgo_invoicing_profile_field_variants();
}

/** @return array<int|string, string> */
function fn_settings_variants_addons_fgo_invoicing_reg_com_field(): array
{
    return fn_fgo_invoicing_profile_field_variants();
}

/** @return array<int|string, string> */
function fn_settings_variants_addons_fgo_invoicing_cnp_field(): array
{
    return fn_fgo_invoicing_profile_field_variants();
}
