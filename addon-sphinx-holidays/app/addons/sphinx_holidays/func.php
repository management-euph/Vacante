<?php
declare(strict_types=1);
/***************************************************************************
 *                                                                          *
 *   (c) 2024-2026 VacanteLitoral.ro                                       *
 *                                                                          *
 *   Location: app/addons/sphinx_holidays/func.php                         *
 *                                                                          *
 ***************************************************************************/

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

use Tygh\Registry;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

// =========================================================================
// CS-Cart calls every fn_* below BY NAME (hook dispatch, settings variants,
// install/uninstall). The names must live here; the bodies live in src/
// (Hooks\OrderHooks, Install\*, Api\ImageHelper, Repository\*) so func.php
// stays a thin dispatch boundary — see GodFileRatchetTest.
// =========================================================================

/**
 * Variants function for the default_currency addon setting.
 * Pulls currencies from CS-Cart's configured currencies.
 *
 * @return array<string, string>
 */
function fn_settings_variants_addons_sphinx_holidays_default_currency(): array
{
    $currencies = Registry::get('currencies');
    $result = [];

    if (empty($currencies) || !is_array($currencies)) {
        return $result;
    }

    foreach ($currencies as $code => $currency) {
        $symbol = is_array($currency) ? TypeCoerce::toString($currency['symbol'] ?? '') : '';
        $label = (string) $code;
        $result[$label] = $label . ($symbol !== '' ? ' (' . $symbol . ')' : '');
    }

    return $result;
}

/**
 * Dynamic variants for the "Product languages" multiple checkboxes setting.
 * Lists all active CS-Cart languages.
 *
 * @return array<string, string>
 */
function fn_settings_variants_addons_sphinx_holidays_product_languages(): array
{
    $languages = TypeCoerce::toRowList(
        db_get_array("SELECT lang_code, name FROM ?:languages WHERE status = 'A' ORDER BY name"),
    );
    $result = [];
    foreach ($languages as $lang) {
        $code = TypeCoerce::toString($lang['lang_code'] ?? '');
        if ($code === '') {
            continue;
        }
        $result[$code] = TypeCoerce::toString($lang['name'] ?? '') . ' (' . strtoupper($code) . ')';
    }
    return $result;
}

/**
 * All CS-Cart categories as selectbox options ("Parent / Child / …").
 *
 * @return array<int, string>
 */
function fn_sphinx_holidays_build_category_options(): array
{
    return \Tygh\Addons\SphinxHolidays\Install\CategoryOptionsBuilder::build();
}

/**
 * Variants for the "hotels_category_id" selectbox setting.
 *
 * @return array<int, string>
 */
function fn_settings_variants_addons_sphinx_holidays_hotels_category_id(): array
{
    return fn_sphinx_holidays_build_category_options();
}

/**
 * Variants for the "packages_category_id" selectbox setting.
 *
 * @return array<int, string>
 */
function fn_settings_variants_addons_sphinx_holidays_packages_category_id(): array
{
    return fn_sphinx_holidays_build_category_options();
}

/**
 * Variants for the "circuits_category_id" selectbox setting.
 *
 * @return array<int, string>
 */
function fn_settings_variants_addons_sphinx_holidays_circuits_category_id(): array
{
    return fn_sphinx_holidays_build_category_options();
}

/**
 * Variants for the "experiences_category_id" selectbox setting.
 *
 * @return array<int, string>
 */
function fn_settings_variants_addons_sphinx_holidays_experiences_category_id(): array
{
    return fn_sphinx_holidays_build_category_options();
}

/**
 * Addon uninstall function.
 * Drops Sphinx-specific tables and cleans up.
 */
function fn_sphinx_holidays_uninstall(): bool
{
    // Remove Sphinx aliases from shared feature mapping (table may not exist if travel_core already uninstalled)
    $tablePrefix = fn_sphinx_holidays_table_prefix();
    $aliasTableExists = db_get_field(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?s",
        $tablePrefix . 'travel_api_alias'
    );
    if ($aliasTableExists) {
        // Region feature-map rows seeded by fn_sphinx_holidays_seed_region_mappings:
        // join-scoped to sphinx's own aliases (must run BEFORE the alias delete)
        $featureMapExists = db_get_field(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?s",
            $tablePrefix . 'travel_feature_map'
        );
        if ($featureMapExists) {
            db_query(
                "DELETE fm FROM ?:travel_feature_map fm
                 JOIN ?:travel_api_alias a ON a.map_id = fm.map_id AND a.api_source = 'sphinx'
                 WHERE fm.feature_type = 'region'"
            );
        }
        db_query("DELETE FROM ?:travel_api_alias WHERE api_source = 'sphinx'");
    }

    // Remove language variables
    db_query("DELETE FROM ?:language_values WHERE name LIKE 'sphinx_holidays.%'");

    // Clean up block manager blocks (dedicated types sphinx_booking_engine /
    // sphinx_best_deals) together with their descriptions and layout placements
    $sphinx_block_ids = db_get_fields(
        "SELECT block_id FROM ?:bm_blocks WHERE type IN ('sphinx_booking_engine', 'sphinx_best_deals')"
    );
    if (!empty($sphinx_block_ids)) {
        db_query("DELETE FROM ?:bm_blocks_descriptions WHERE block_id IN (?n)", $sphinx_block_ids);
        db_query("DELETE FROM ?:bm_snapping WHERE block_id IN (?n)", $sphinx_block_ids);
        db_query("DELETE FROM ?:bm_blocks WHERE block_id IN (?n)", $sphinx_block_ids);
    }

    // Drop Sphinx-specific tables (order matters for FK constraints)
    db_query("DROP TABLE IF EXISTS ?:sphinx_image_sync_queue");
    db_query("DROP TABLE IF EXISTS ?:sphinx_destination_whitelist");
    db_query("DROP TABLE IF EXISTS ?:sphinx_cache");
    db_query("DROP TABLE IF EXISTS ?:sphinx_sync_log");
    db_query("DROP TABLE IF EXISTS ?:sphinx_bookings");
    db_query("DROP TABLE IF EXISTS ?:sphinx_package_routes");
    db_query("DROP TABLE IF EXISTS ?:sphinx_experiences");
    db_query("DROP TABLE IF EXISTS ?:sphinx_circuits");
    db_query("DROP TABLE IF EXISTS ?:sphinx_destinations");
    db_query("DROP TABLE IF EXISTS ?:sphinx_hotels");

    return true;
}

/**
 * Post-install function.
 * Seeds Sphinx-specific aliases into the shared feature mapping.
 */
function fn_sphinx_holidays_post_install(): bool
{
    fn_sphinx_holidays_seed_aliases();
    fn_sphinx_holidays_seed_region_mappings();
    fn_sphinx_holidays_seed_language_keys();
    fn_sphinx_holidays_seed_seo_defaults();
    return true;
}

/**
 * Seed / re-seed language keys (addon.xml + lang_keys.php merged) into
 * ?:language_values and mirror settings labels into settings_descriptions.
 * Called from post_install and the init.php self-heal probe.
 */
function fn_sphinx_holidays_seed_language_keys(): void
{
    \Tygh\Addons\SphinxHolidays\Install\LanguageSeeder::seed();
}

/**
 * All sphinx language variables from both sources, merged.
 *
 * @return array<string, array<string, string>> name => [lang_code => value]
 */
function fn_sphinx_holidays_language_variables(): array
{
    return \Tygh\Addons\SphinxHolidays\Install\LanguageSeeder::variables();
}

/**
 * Content hash of the language-variable sources — the self-heal seed stamp.
 */
function fn_sphinx_holidays_language_seed_hash(): string
{
    return \Tygh\Addons\SphinxHolidays\Install\LanguageSeeder::seedHash();
}

/**
 * The SEO Templates page's Sphinx pieces: built-in templates (also the
 * runtime fallback, so they must load in every AREA — func.php does), the
 * placeholder list and the tab entry. Data in Seo\SeoTemplateDefinitions.
 *
 * @return array<string, string>
 */
function fn_sphinx_holidays_seo_defaults(): array
{
    return \Tygh\Addons\SphinxHolidays\Seo\SeoTemplateDefinitions::defaults();
}

/** @return array<string, list<string>> */
function fn_sphinx_holidays_seo_placeholders(): array
{
    return \Tygh\Addons\SphinxHolidays\Seo\SeoTemplateDefinitions::placeholders();
}

/** @return array{name: string, dispatch: string} */
function fn_sphinx_holidays_seo_page(): array
{
    return \Tygh\Addons\SphinxHolidays\Seo\SeoTemplateDefinitions::page();
}

/**
 * Idempotently seed default SEO template values into the sphinx_holidays
 * settings. Called from the init.php self-heal probe on admin page-load when
 * the sentinel key is missing, and from post_install. Existing non-empty
 * values are preserved.
 */
function fn_sphinx_holidays_seed_seo_defaults(): void
{
    $defaults = fn_sphinx_holidays_seo_defaults();

    $currentRaw = \Tygh\Registry::get('addons.sphinx_holidays');
    $current  = is_array($currentRaw) ? $currentRaw : [];
    $settings = \Tygh\Settings::instance();
    if (!is_object($settings) || !method_exists($settings, 'updateValue')) {
        return;
    }
    $toMerge  = [];

    foreach ($defaults as $key => $value) {
        $stored = $current[$key] ?? null;
        // Only write if the key is absent or blank (never overwrite admin edits)
        if ($stored === null || ($stored === '' && $value !== '')) {
            $settings->updateValue($key, $value, 'sphinx_holidays', true);
            $toMerge[$key] = $value;
        }
    }

    if (!empty($toMerge)) {
        \Tygh\Registry::set('addons.sphinx_holidays', array_merge($current, $toMerge));
    }
}

/**
 * The ?: table prefix, read here at the boundary — Registry access is
 * banned inside src/ class bodies (phpstan-disallowed-calls.neon).
 */
function fn_sphinx_holidays_table_prefix(): string
{
    $prefixRaw = Registry::get('config.table_prefix');
    return is_scalar($prefixRaw) ? (string) $prefixRaw : 'cscart_';
}

/**
 * Seed Sphinx API aliases into the shared travel_api_alias table.
 * Idempotent; body in Install\FeatureAliasSeeder.
 */
function fn_sphinx_holidays_seed_aliases(): void
{
    \Tygh\Addons\SphinxHolidays\Install\FeatureAliasSeeder::seedAliases(fn_sphinx_holidays_table_prefix());
}

/**
 * Seed whitelisted regions into travel_feature_map.
 * Idempotent; body in Install\FeatureAliasSeeder.
 */
function fn_sphinx_holidays_seed_region_mappings(): void
{
    \Tygh\Addons\SphinxHolidays\Install\FeatureAliasSeeder::seedRegionMappings(fn_sphinx_holidays_table_prefix());
}

// =========================================================================
// HOOK FUNCTIONS
// =========================================================================

/**
 * Hook: pre_place_order
 * Re-verify Sphinx offer prices before the order is placed; unavailable
 * offers are removed (their stranded booking rows deleted) instead of
 * blocking mixed-provider orders. Body in Hooks\OrderHooks.
 *
 * @param mixed $cart           Cart array (by ref)
 * @param mixed $allow          Set to false to block placement (by ref)
 * @param mixed $product_groups Unused (hook signature)
 */
function fn_sphinx_holidays_pre_place_order(&$cart, &$allow, &$product_groups): void
{
    \Tygh\Addons\SphinxHolidays\Hooks\OrderHooks::prePlaceOrder($cart, $allow);
}

/**
 * Hook: place_order_post — submit the order's bookings to the Sphinx API and self-heal
 * booking–order links (body in Hooks\OrderHooks). CS-Cart 4.20 passes ($cart, $auth, $action,
 * $issuer_id, $parent_order_id, $order_id, ...), older cores ($order_id, $action, $order_status,
 * $cart, $auth): nine optional by-ref slots, PlaceOrderPostArgs reads either. Never throws.
 */
function fn_sphinx_holidays_place_order_post(mixed &$a1 = null, mixed &$a2 = null, mixed &$a3 = null, mixed &$a4 = null, mixed &$a5 = null, mixed &$a6 = null, mixed &$a7 = null, mixed &$a8 = null, mixed &$a9 = null): void
{
    try { // inside the customer's "Place order" request, after the order row is written
        ['order_id' => $order_id, 'cart' => $cart] = \Tygh\Addons\TravelCore\Helpers\PlaceOrderPostArgs::resolve([$a1, $a2, $a3, $a4, $a5, $a6, $a7, $a8, $a9]);
        \Tygh\Addons\SphinxHolidays\Hooks\OrderHooks::placeOrderPost($order_id, $cart);
    } catch (\Throwable $e) {
        fn_log_event('general', 'runtime', ['message' => 'Sphinx place_order_post, order ' . ($order_id ?? 0) . ': ' . $e::class . ': ' . $e->getMessage()]);
    }
}

/**
 * Link any unlinked sphinx bookings referenced by an order's items to that
 * order (idempotent reconciler). Body in Hooks\OrderHooks.
 *
 * @return int Number of bookings newly linked
 */
function fn_sphinx_holidays_link_order_bookings(int $order_id): int
{
    return \Tygh\Addons\SphinxHolidays\Hooks\OrderHooks::linkOrderBookings($order_id);
}

/**
 * Hook: travel_link_order_bookings — fired by travel_core's
 * travel_tools "Reconcile booking–order links" backfill.
 *
 * @param int $order_id
 * @param int $linked Accumulator across providers
 */
function fn_sphinx_holidays_travel_link_order_bookings($order_id, &$linked): void
{
    $linked += fn_sphinx_holidays_link_order_bookings((int) $order_id);
}

/**
 * Hook: calculate_cart_items
 * Preserve stored price for Sphinx bookings. Body in Hooks\OrderHooks.
 *
 * @param mixed $cart          Cart array (by ref — prices rewritten in place)
 * @param mixed $cart_products Unused (hook signature)
 * @param mixed $auth          Unused (hook signature)
 */
function fn_sphinx_holidays_calculate_cart_items(&$cart, &$cart_products, &$auth): void
{
    \Tygh\Addons\SphinxHolidays\Hooks\OrderHooks::preserveStoredPrices($cart);
}

/**
 * Hook: get_product_data_post
 * Attach booking engine config to Sphinx hotel products.
 *
 * @param mixed $product_data Untouched (see body note)
 * @param mixed $auth         Unused (hook signature)
 * @param mixed $preview      Unused (hook signature)
 * @param mixed $lang_code    Unused (hook signature)
 */
function fn_sphinx_holidays_get_product_data_post(&$product_data, &$auth, $preview, $lang_code): void
{
    // Complete no-op for Smarty 5 compatibility.
    // Do NOT modify $product_data during template rendering — any modification
    // corrupts Smarty 5's Variable wrapper, causing Data::getVariable()
    // infinite recursion (Data.php:265) that exhausts 256MB memory.
    // Templates detect Sphinx products from $product.product_code prefix (SPX).
}

/**
 * Hook: gather_additional_product_data_post
 * Complete no-op for Smarty 5 compatibility.
 * Templates detect Sphinx products from $product.product_code prefix (SPX).
 *
 * @param mixed $product Untouched (see body note)
 * @param mixed $auth    Unused (hook signature)
 * @param mixed $params  Unused (hook signature)
 */
function fn_sphinx_holidays_gather_additional_product_data_post(&$product, $auth, $params): void
{
    // Do NOT modify $product or call $view->assign() here.
    // This hook runs during Smarty 5 template rendering — any $product
    // modification corrupts Smarty's scope chain (Data.php:265 crash).
}

/**
 * Hook: user_login_post
 * Link session-based sphinx bookings to the logged-in user.
 * Body in Hooks\UserHooks.
 *
 * @param mixed $user_data Unused (hook signature)
 * @param mixed $auth      Auth array (by ref)
 */
function fn_sphinx_holidays_user_login_post($user_data, &$auth): void
{
    \Tygh\Addons\SphinxHolidays\Hooks\UserHooks::linkSessionBookings(
        is_array($auth) ? ($auth['user_id'] ?? 0) : 0,
    );
}

/**
 * Hook: create_user_post
 * Link session-based sphinx bookings to the newly created user.
 * Body in Hooks\UserHooks.
 *
 * @param mixed $user_id   New user's id
 * @param mixed $user_data Unused (hook signature)
 * @param mixed $auth      Unused (hook signature)
 */
function fn_sphinx_holidays_create_user_post($user_id, $user_data, &$auth): void
{
    \Tygh\Addons\SphinxHolidays\Hooks\UserHooks::linkSessionBookings($user_id);
}

/**
 * Hook: get_order_info
 * Admin-panel decoration for orders containing sphinx bookings (surrogate
 * "View Booking" ids + failed-booking warning). Body in Hooks\OrderHooks.
 *
 * @param mixed $order           Order array (by ref)
 * @param mixed $additional_data Unused (hook signature)
 */
function fn_sphinx_holidays_get_order_info(&$order, $additional_data): void
{
    \Tygh\Addons\SphinxHolidays\Hooks\OrderHooks::orderInfoLoaded($order);
}

/**
 * Hook: travel_core_exchange_rates_updated
 *
 * Logs the exchange rate update result from travel_core to sphinx_sync_log
 * so the admin panel can display "last updated" timestamps.
 *
 * @param array<string, mixed> $result Full result from fn_travel_core_update_exchange_rates()
 */
function fn_sphinx_holidays_travel_core_exchange_rates_updated(array &$result): void
{
    if (empty($result['success'])) {
        return;
    }

    $updates = is_array($result['updates'] ?? null) ? $result['updates'] : [];
    $total = count($updates);
    $synced = count(array_filter($updates, static fn($u): bool => is_array($u) && !empty($u['success'])));

    db_query(
        "INSERT INTO ?:sphinx_sync_log (sync_type, status, items_total, items_synced, items_failed, error_message, started_at, completed_at) VALUES (?s, ?s, ?i, ?i, ?i, ?s, NOW(), NOW())",
        'exchange_rates',
        'completed',
        $total,
        $synced,
        $total - $synced,
        ''
    );
}

/**
 * Download an external image URL and attach it to a CS-Cart product.
 * Body in Api\ImageHelper (which also exposes $lastDownloadError for cron
 * callers).
 *
 * @param int    $product_id CS-Cart product ID
 * @param string $image_url  External image URL to download
 * @param bool   $is_main    True for main product image, false for additional
 * @return bool True on success
 */
function fn_sphinx_holidays_add_product_image(int $product_id, string $image_url, bool $is_main = false): bool
{
    return \Tygh\Addons\SphinxHolidays\Api\ImageHelper::attachToProduct($product_id, $image_url, $is_main);
}

/**
 * Get hotels with filtering, sorting, and pagination (fn_get_products
 * pattern). Body in Repository\HotelAdminListingRepository.
 *
 * @param array<array-key, mixed> $params Search/filter/sort parameters from $_REQUEST
 * @return array{0: list<array<string, mixed>>, 1: array<string, mixed>} [$hotels, $search_params]
 */
function fn_sphinx_holidays_get_hotels(array $params = []): array
{
    $perPageRaw = Registry::get('settings.Appearance.admin_elements_per_page');
    $perPage = is_numeric($perPageRaw) ? (int) $perPageRaw : 0;

    return (new \Tygh\Addons\SphinxHolidays\Repository\HotelAdminListingRepository($perPage > 0 ? $perPage : 50))
        ->getListing($params);
}

/**
 * Write a single pre-assembled cart-product row into the current session cart,
 * then trigger CS-Cart's calculate + save trio.
 *
 * Thin wrapper that absorbs the reference-based `$cart`/`$auth` handling
 * required by CS-Cart's procedural cart API. Binds $_SESSION directly — the
 * authoritative session store (see travel_core's SessionAccessor): the
 * `Tygh::$app['session']` binding is just an ArrayAccess wrapper over it.
 *
 * @param array<string, mixed> $row Pre-assembled cart-product entry (shape:
 *                                  product_id, amount, price, extra, …).
 */
function fn_sphinx_holidays_write_cart_row(string $cartId, array $row): void
{
    $cart = &$_SESSION['cart'];
    $auth = &$_SESSION['auth'];

    if (!is_array($cart) || $cart === []) {
        fn_clear_cart($cart);
    }
    if (!is_array($cart)) {
        return; // cart bootstrap failed — nothing safe to write into
    }

    $products = is_array($cart['products'] ?? null) ? $cart['products'] : [];
    $products[$cartId] = $row;
    $cart['products'] = $products;

    $user_id = is_array($auth) ? TypeCoerce::toInt($auth['user_id'] ?? 0) : 0;
    fn_calculate_cart_content($cart, $auth, 'S', true, 'F', true);
    fn_save_cart_content($cart, $user_id);
}

/**
 * Idempotent schema-delta applier for EXISTING installs (admin page load,
 * once per request). Body in Install\SchemaMigrator — keep its lists in
 * sync with addon.xml's `for="upgrade"` items.
 */
function fn_sphinx_holidays_ensure_schema(): void
{
    \Tygh\Addons\SphinxHolidays\Install\SchemaMigrator::ensure(fn_sphinx_holidays_table_prefix());
}
