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
    fn_eurosite_ensure_carrier_product();
}

/** ?:storage_data key holding the carrier's product_id. */
const EUROSITE_CARRIER_STORAGE_KEY = 'eurosite_carrier_product_id';

/**
 * Ensure the hidden booking carrier product exists and can be bought, and
 * return its product_id (0 on failure).
 *
 * The carrier is the last resort for a booking's cart line: only a hotel with
 * no CS-Cart product of its own uses it (BookingCartProduct). It is found by
 * the product_id remembered in ?:storage_data — a product code is optional in
 * CS-Cart — and, on stores set up before that, by its EUROSITE-BOOKING code.
 *
 * Called on install, from the admin dashboard and, when a booking needs it,
 * from add_to_cart: a store where the install step never ran (or failed)
 * heals on the first booking instead of refusing every checkout.
 */
function fn_eurosite_ensure_carrier_product(): int
{
    try {
        $productId = fn_eurosite_find_carrier_product();
        if ($productId > 0) {
            // A disabled product is dropped from carts; hidden is its normal state.
            db_query("UPDATE ?:products SET status = 'H' WHERE product_id = ?i AND status = 'D'", $productId);
            fn_eurosite_remember_carrier_product($productId);

            return $productId;
        }
        if (!function_exists('fn_update_product')) {
            return 0;
        }

        $productData = [
            'product'          => 'Eurosite booking',
            'product_code'     => 'EUROSITE-BOOKING',
            'status'           => 'H',          // reachable by a cart line, absent from catalog/search
            'price'            => 0,
            'amount'           => 100000,
            'tracking'         => 'D',          // no inventory tracking
            'is_edp'           => 'N',
            'shipping_freight' => 0,
            'free_shipping'    => 'Y',
            // As EurositeProductFactory: without a company an Ultimate store
            // refuses the product (e.g. an install run in "All stores").
            'company_id'       => \Tygh\Addons\Eurosite\Services\ConfigProvider::getCompanyId(),
        ];
        $categoryId = fn_eurosite_carrier_category();
        if ($categoryId > 0) {
            $productData['category_ids'] = [$categoryId];
            $productData['main_category'] = $categoryId;
        }
        $productId = TypeCoerce::toInt(fn_update_product($productData));
        if ($productId > 0) {
            fn_eurosite_remember_carrier_product($productId);

            return $productId;
        }
        fn_log_event('general', 'runtime', ['message' => 'Eurosite carrier product creation failed: fn_update_product returned no product']);
    } catch (\Throwable $e) {
        if (function_exists('fn_log_event')) {
            fn_log_event('general', 'runtime', [
                'message' => 'Eurosite carrier product creation failed: ' . $e->getMessage(),
            ]);
        }
    }

    return 0;
}

/** The carrier's product_id if the product still exists: the remembered id first, then its code. */
function fn_eurosite_find_carrier_product(): int
{
    $remembered = function_exists('fn_get_storage_data')
        ? TypeCoerce::toInt(fn_get_storage_data(EUROSITE_CARRIER_STORAGE_KEY))
        : 0;
    if ($remembered > 0) {
        $existing = TypeCoerce::toInt(db_get_field('SELECT product_id FROM ?:products WHERE product_id = ?i', $remembered));
        if ($existing > 0) {
            return $existing;
        }
    }

    return TypeCoerce::toInt(db_get_field(
        "SELECT product_id FROM ?:products WHERE product_code = 'EUROSITE-BOOKING' ORDER BY product_id LIMIT 1",
    ));
}

function fn_eurosite_remember_carrier_product(int $productId): void
{
    if ($productId > 0 && function_exists('fn_set_storage_data')) {
        fn_set_storage_data(EUROSITE_CARRIER_STORAGE_KEY, (string) $productId);
    }
}

/** The hotels category when it is set and exists, else the first active category, else any. */
function fn_eurosite_carrier_category(): int
{
    $hotels = \Tygh\Addons\Eurosite\Services\ConfigProvider::getHotelsCategoryId();
    if ($hotels > 0 && TypeCoerce::toInt(db_get_field('SELECT category_id FROM ?:categories WHERE category_id = ?i', $hotels)) > 0) {
        return $hotels;
    }
    $active = TypeCoerce::toInt(db_get_field("SELECT category_id FROM ?:categories WHERE status = 'A' ORDER BY category_id LIMIT 1"));

    return $active > 0
        ? $active
        : TypeCoerce::toInt(db_get_field('SELECT category_id FROM ?:categories ORDER BY category_id LIMIT 1'));
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
