<?php

declare(strict_types=1);

/**
 * Eurosite Touring — addon functions.
 *
 * MVP keeps this thin: the API/booking logic lives in src/ (Api\* + Services\*).
 * This file holds only the CS-Cart procedural boundary the platform calls by
 * name. As the addon grows, hook bodies (place_order_post, etc.) belong in
 * src/Hooks and delegate from here — the pattern the sphinx addon uses.
 *
 * Location: app/addons/eurosite/func.php
 */

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Registry;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

require_once __DIR__ . '/functions/install.php';

/**
 * The ?: table prefix, read here at the boundary — Registry access is
 * banned inside src/ class bodies (phpstan-disallowed-calls.neon).
 */
function fn_eurosite_table_prefix(): string
{
    $prefixRaw = Registry::get('config.table_prefix');

    return is_scalar($prefixRaw) ? (string) $prefixRaw : 'cscart_';
}

/**
 * Idempotent schema self-heal (missing tables/columns on installed stores);
 * body in Install\SchemaMigrator. Safe on every request — one catalog read
 * after the first call.
 */
function fn_eurosite_ensure_schema(): void
{
    \Tygh\Addons\Eurosite\Install\SchemaMigrator::ensure(fn_eurosite_table_prefix());
    fn_eurosite_remove_seeded_menu_once();
}

/**
 * Force-seed every eurosite label, ignoring the init.php probe's stamp.
 * Entry point for dev/tools/seed-langs.php `force`.
 */
function fn_eurosite_seed_language_keys(): void
{
    \Tygh\Addons\Eurosite\Install\LanguageSeeder::seed();
}

/**
 * The runtime language keys for the init.php self-heal. Thin wrapper over
 * LanguageSeeder, which is what init.php itself calls — this exists for the
 * dev tools and for parity with the sibling addons' fn_*_language_variables().
 *
 * @return array<string, array<string, string>> key => [lang_code => value]
 */
function fn_eurosite_language_variables(): array
{
    return \Tygh\Addons\Eurosite\Install\LanguageSeeder::variables();
}

/**
 * Fingerprint of the language sources. Wrapper over LanguageSeeder::seedHash().
 */
function fn_eurosite_language_seed_hash(): string
{
    return \Tygh\Addons\Eurosite\Install\LanguageSeeder::seedHash();
}

/**
 * Options of the "CS-Cart category ID for Eurosite hotels" setting: every
 * category, labelled with its full path. CS-Cart's settings page calls this
 * by name for the hotels_category_id selectbox.
 *
 * @return array<int, string>
 */
function fn_settings_variants_addons_eurosite_hotels_category_id(): array
{
    return \Tygh\Addons\TravelCore\Helpers\CategoryOptions::build();
}

/**
 * The placeholders EurositeProductFactory::placeholders() fills, as the SEO
 * Templates page lists them (travel_core labels each bare key).
 *
 * @return array<string, list<string>>
 */
function fn_eurosite_seo_placeholders(): array
{
    return [
        'hotel'    => ['name', 'classification', 'stars_emoji', 'property_type', 'rooms', 'code', 'description', 'image_url'],
        'location' => ['city', 'country', 'city_code', 'country_code', 'latitude', 'longitude'],
        'price'    => ['min_price', 'currency'],
        'other'    => ['year'],
    ];
}

/**
 * This add-on's tab in the SEO Templates provider row.
 *
 * @return array{name: string, dispatch: string}
 */
function fn_eurosite_seo_page(): array
{
    return ['name' => 'Eurosite', 'dispatch' => 'eurosite.seo_templates'];
}

/**
 * Built-in SEO templates for the hotel products EurositeProductFactory
 * creates. Travel Core's fn_travel_core_apply_seo_fields() falls back to
 * these (by the fn_<addon>_seo_defaults name) for every template not saved
 * on Eurosite → SEO Templates; they are also what "Restore default" puts
 * back.
 *
 * @return array<string, string>
 */
function fn_eurosite_seo_defaults(): array
{
    return [
        'seo_overwrite_mode'         => 'override_all',
        'seo_product_name'           => '{{name}}',
        'seo_page_title'             => '{{name}} {{stars_emoji}} - {{city}}, {{country}}',
        'seo_meta_description'       => 'Book {{name}} in {{city}}, {{country}}.',
        'seo_meta_keywords'          => '{{name}}, {{city}}, {{country}}, hotel',
        'seo_name_slug'              => '{{name}}-{{city}}',
        'seo_full_description'       => '',
        'seo_meta_description__ro'   => 'Rezervă {{name}} în {{city}}, {{country}}.',
        'seo_meta_keywords__ro'      => '{{name}}, {{city}}, {{country}}, hotel',
        'seo_field_product_name'     => 'Y',
        'seo_field_page_title'       => 'Y',
        'seo_field_meta_description' => 'Y',
        'seo_field_meta_keywords'    => 'Y',
        'seo_field_name_slug'        => 'Y',
        'seo_field_full_description' => 'Y',
    ];
}

// ── Order pipeline hooks (registered in init.php) ────────────────────────────

/**
 * pre_place_order — price-tamper guard: every eurosite cart line's price must
 * match the server-side booking row (converted to the cart currency the same
 * way add_to_cart did). Mismatch blocks the order.
 *
 * @param array<string, mixed> $cart
 * @param bool $allow
 * @param array<int, mixed> $product_groups
 */
function fn_eurosite_pre_place_order(array &$cart, &$allow, &$product_groups): void
{
    $products = is_array($cart['products'] ?? null) ? $cart['products'] : [];
    foreach ($products as $item) {
        if (!is_array($item)) {
            continue;
        }
        $extra = is_array($item['extra'] ?? null) ? $item['extra'] : [];
        $bookingId = TypeCoerce::toInt($extra['eurosite_booking_id'] ?? 0);
        if ($bookingId <= 0) {
            continue;
        }
        $booking = \Tygh\Addons\Eurosite\Services\Container::bookings()->findById($bookingId);
        if ($booking === null) {
            $allow = false;
            fn_set_notification('E', __('error'), 'Eurosite booking not found — please rebuild your cart.');
            continue;
        }
        $coefficient = TypeCoerce::toFloat(db_get_field(
            'SELECT coefficient FROM ?:currencies WHERE currency_code = ?s',
            TypeCoerce::toString($booking['currency'] ?? 'EUR'),
        ));
        $expected = $coefficient > 0
            ? round(TypeCoerce::toFloat($booking['total_price'] ?? 0) * $coefficient, 2)
            : TypeCoerce::toFloat($booking['total_price'] ?? 0);
        if (abs(TypeCoerce::toFloat($item['price'] ?? 0) - $expected) > 0.01) {
            $allow = false;
            fn_set_notification('E', __('error'), 'The booking price changed — please rebuild your cart.');
            fn_log_event('general', 'runtime', [
                'message' => "Eurosite pre_place_order price mismatch for booking {$bookingId}: cart "
                    . TypeCoerce::toString($item['price'] ?? 0) . " vs expected {$expected}",
            ]);
        }
    }
}

/**
 * place_order_post — link the order's eurosite bookings and submit them to
 * the Eurosite API (AddBookingRequest); idempotent on re-fire.
 *
 * The nine parameters are anonymous, optional and by-reference ON PURPOSE:
 * CS-Cart 4.20 passes ($cart, $auth, $action, $issuer_id, $parent_order_id,
 * $order_id, $order_status, $short_order_data, $notification_rules), older
 * builds ($order_id, $action, $order_status, $cart, $auth). Declared in the
 * old order, this hook took the 4.20 cart for the order id and walked the
 * cart's VALUES as order ids (a non-empty array casts to 1), so the placed
 * order's bookings were never submitted and unrelated orders were looked up.
 * travel_core's PlaceOrderPostArgs reads either order; every order id it finds
 * (Multi-Vendor: the parent, then its vendor sub-orders) is handled as before.
 *
 * Nothing may escape from here: this runs inside the customer's "Place order"
 * request, after the order row is written. A failed order is logged and the
 * next one still runs; a failed booking stays retryable from the admin grid.
 */
function fn_eurosite_place_order_post(
    mixed &$arg1 = null,
    mixed &$arg2 = null,
    mixed &$arg3 = null,
    mixed &$arg4 = null,
    mixed &$arg5 = null,
    mixed &$arg6 = null,
    mixed &$arg7 = null,
    mixed &$arg8 = null,
    mixed &$arg9 = null,
): void {
    try {
        $ids = \Tygh\Addons\TravelCore\Helpers\PlaceOrderPostArgs::resolve(
            [$arg1, $arg2, $arg3, $arg4, $arg5, $arg6, $arg7, $arg8, $arg9],
        )['order_ids'];
    } catch (\Throwable $e) {
        fn_log_event('general', 'runtime', ['message' => 'Eurosite place_order_post: ' . $e::class . ': ' . $e->getMessage()]);

        return;
    }
    foreach ($ids as $id) {
        try {
            $orderInfo = TypeCoerce::toStringMap(function_exists('fn_get_order_info') ? fn_get_order_info($id) : null);
            if ($orderInfo === []) {
                continue;
            }
            (new \Tygh\Addons\Eurosite\Services\BookingSubmissionService())->submitOrder($id, $orderInfo);
        } catch (\Throwable $e) {
            fn_log_event('general', 'runtime', [
                'message' => "Eurosite place_order_post, order {$id}: " . $e::class . ': ' . $e->getMessage(),
            ]);
        }
    }
}

/**
 * user_login_post — claim guest bookings created in this session.
 */
function fn_eurosite_user_login_post(mixed $user_data, mixed $user_id, mixed $unused = null): void
{
    $uid = (int) (is_scalar($user_id) ? $user_id : 0);
    $sessionId = function_exists('session_id') ? (string) session_id() : '';
    if ($uid > 0 && $sessionId !== '') {
        \Tygh\Addons\Eurosite\Services\Container::bookings()->linkToUserBySession($sessionId, $uid);
    }
}

/**
 * create_user_post — same claim for freshly registered users.
 */
function fn_eurosite_create_user_post(mixed $user_id, mixed $user_data = null, mixed $unused1 = null, mixed $unused2 = null): void
{
    fn_eurosite_user_login_post(null, $user_id);
}

/**
 * travel_link_order_bookings — travel_core's reconcile sweep: link any
 * eurosite bookings referenced by the order's items but still unlinked.
 *
 * @param int $order_id
 * @param int $linked incremented per booking linked
 */
function fn_eurosite_travel_link_order_bookings($order_id, &$linked): void
{
    $orderId = (int) $order_id;
    if ($orderId <= 0 || !function_exists('fn_get_order_info')) {
        return;
    }
    $orderInfo = TypeCoerce::toStringMap(fn_get_order_info($orderId));
    if ($orderInfo === []) {
        return;
    }
    $service = new \Tygh\Addons\Eurosite\Services\BookingSubmissionService();
    $repo = \Tygh\Addons\Eurosite\Services\Container::bookings();
    foreach ($service->bookingIdsFromOrder($orderInfo) as $bookingId) {
        $booking = $repo->findById($bookingId);
        if ($booking !== null && TypeCoerce::toInt($booking['order_id'] ?? 0) === 0) {
            $repo->linkToOrder($bookingId, $orderId, TypeCoerce::toInt($orderInfo['user_id'] ?? 0));
            $linked = (int) $linked + 1;
        }
    }
}
