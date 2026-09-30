<?php

declare(strict_types=1);

/**
 * Eurosite Touring — install-time helpers.
 *
 * Location: app/addons/eurosite/functions/install.php (required by func.php).
 */

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

/**
 * Post-install hook (addon.xml <functions><item for="install">).
 */
function fn_eurosite_post_install(): void
{
    fn_eurosite_seed_storefront_menu();
}

/**
 * Seed the storefront menu: a top-level "Eurosite" item with the four module
 * children (Cazari individuale live; Pachete/Transport/Circuite placeholders).
 *
 * There is NO precedent for menu seeding in this repo (novoton/sphinx use
 * Block Manager blocks only), so this is deliberately defensive:
 *  - introspects ?:static_data's real columns and only writes ones that exist;
 *  - idempotent — items are matched by (section, param) and never duplicated;
 *  - any failure degrades to a log line, never a broken install (the menu can
 *    always be created by hand in Design > Menus).
 *
 * Returns the number of items created (0 = nothing to do / skipped).
 */
function fn_eurosite_seed_storefront_menu(): int
{
    try {
        $columns = db_get_fields(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = CONCAT(?s, 'static_data')",
            fn_eurosite_table_prefix(),
        );
        $columns = TypeCoerce::toStringList($columns);
        if (!in_array('param', $columns, true) || !in_array('param_id', $columns, true)) {
            return 0; // no static_data table in this build — manual menu only
        }
        $has = static fn (string $col): bool => in_array($col, $columns, true);

        $langCodes = TypeCoerce::toStringList(db_get_fields("SELECT lang_code FROM ?:languages WHERE status = 'A'"));
        if ($langCodes === []) {
            $langCodes = ['en'];
        }

        $items = [
            ['param' => 'index.php?dispatch=eurosite_booking.search', 'pos' => 10,
             'names' => ['en' => 'Individual stays', 'ro' => 'Cazari individuale']],
            ['param' => 'index.php?dispatch=eurosite_booking.packages', 'pos' => 20,
             'names' => ['en' => 'Tour packages', 'ro' => 'Pachete Touroperator']],
            ['param' => 'index.php?dispatch=eurosite_booking.transport', 'pos' => 30,
             'names' => ['en' => 'Transport', 'ro' => 'Transport Touroperator']],
            ['param' => 'index.php?dispatch=eurosite_booking.circuits', 'pos' => 40,
             'names' => ['en' => 'Tours & circuits', 'ro' => 'Circuite Touroperator']],
        ];

        // Parent "Eurosite" entry, children beneath it.
        $parentId = fn_eurosite_seed_menu_item('eurosite', 0, 100, ['en' => 'Eurosite', 'ro' => 'Eurosite'], $langCodes, $has);
        $created = $parentId > 0 ? 1 : 0;
        foreach ($items as $item) {
            $id = fn_eurosite_seed_menu_item($item['param'], max(0, $parentId), $item['pos'], $item['names'], $langCodes, $has);
            if ($id > 0) {
                $created++;
            }
        }

        return $created;
    } catch (\Throwable $e) {
        if (function_exists('fn_log_event')) {
            fn_log_event('general', 'runtime', [
                'message' => 'Eurosite storefront menu seeding skipped: ' . $e->getMessage(),
            ]);
        }

        return 0;
    }
}

/**
 * Insert one section-'A' static_data row (top menu) if absent; returns the
 * param_id when the row was CREATED, 0 when it already existed or failed.
 *
 * @param array<string, string> $names lang => label
 * @param list<string> $langCodes
 * @param callable(string): bool $has column-exists probe
 */
function fn_eurosite_seed_menu_item(string $param, int $parentId, int $position, array $names, array $langCodes, callable $has): int
{
    $existing = TypeCoerce::toInt(db_get_field(
        "SELECT param_id FROM ?:static_data WHERE section = 'A' AND param = ?s AND parent_id = ?i",
        $param,
        $parentId,
    ));
    if ($existing > 0) {
        return 0;
    }

    $row = ['section' => 'A', 'param' => $param, 'parent_id' => $parentId];
    if ($has('status')) {
        $row['status'] = 'A';
    }
    if ($has('position')) {
        $row['position'] = $position;
    }
    if ($has('param_2')) {
        $row['param_2'] = '';
    }
    db_query('INSERT INTO ?:static_data ?e', $row);
    $paramId = TypeCoerce::toInt(db_get_field('SELECT LAST_INSERT_ID()'));
    if ($paramId <= 0) {
        return 0;
    }

    if ($has('id_path')) {
        db_query(
            'UPDATE ?:static_data SET id_path = ?s WHERE param_id = ?i',
            $parentId > 0 ? $parentId . '/' . $paramId : (string) $paramId,
            $paramId,
        );
    }

    foreach ($langCodes as $lang) {
        $descr = $names[$lang] ?? $names['en'] ?? $param;
        db_query(
            'INSERT INTO ?:static_data_descriptions (param_id, descr, lang_code) VALUES (?i, ?s, ?s)
             ON DUPLICATE KEY UPDATE descr = VALUES(descr)',
            $paramId,
            $descr,
            $lang,
        );
    }

    return $paramId;
}
