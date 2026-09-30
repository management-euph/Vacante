<?php

declare(strict_types=1);

/**
 * Eurosite Touring — install/upgrade helpers.
 *
 * Location: app/addons/eurosite/functions/install.php (required by func.php).
 */

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

/**
 * Remove the storefront menu items older versions seeded at install (a
 * "Eurosite" parent with "Individual stays" — a destination search — and
 * three "coming soon" pages). Those pages are gone, so the links would 404.
 * The same DELETEs as addon.xml's uninstall; runs once per store, then a
 * storage_data flag skips it (one cheap read per admin request).
 */
function fn_eurosite_remove_seeded_menu_once(): void
{
    if (!function_exists('fn_get_storage_data') || fn_get_storage_data('eurosite_menu_removed') === 'Y') {
        return;
    }
    try {
        $menuPages = 'index.php?dispatch=eurosite_booking%';
        db_query(
            "DELETE sdd FROM ?:static_data_descriptions sdd JOIN ?:static_data sd ON sd.param_id = sdd.param_id
             WHERE sd.section = 'A' AND (sd.param LIKE ?s OR sd.param = ?s)",
            $menuPages,
            'eurosite',
        );
        db_query("DELETE FROM ?:static_data WHERE section = 'A' AND (param LIKE ?s OR param = ?s)", $menuPages, 'eurosite');
        fn_set_storage_data('eurosite_menu_removed', 'Y');
    } catch (\Throwable $e) {
        fn_log_event('general', 'runtime', ['message' => 'Eurosite: removing the old storefront menu failed: ' . $e->getMessage()]);
    }
}
