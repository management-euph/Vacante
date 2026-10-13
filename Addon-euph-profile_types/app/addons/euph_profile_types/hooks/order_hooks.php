<?php

declare(strict_types=1);

use Tygh\Addons\EuphProfileTypes\Cast;
use Tygh\Addons\EuphProfileTypes\Enum\UserKind;
use Tygh\Addons\EuphProfileTypes\Service\CodeValidator;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

/**
 * Hook: create_order — the order keeps the type it is placed with.
 *
 * @param mixed $order
 */
function fn_euph_profile_types_create_order(&$order = []): void
{
    if (!is_array($order)) {
        return;
    }
    try {
        $type = fn_euph_profile_types_order_type($order);
        if ($type !== null) {
            $order['euph_profile_type'] = $type->code;
        }
    } catch (\Throwable $e) {
        error_log('euph_profile_types: ' . $e->getMessage());
    }
}

/**
 * Hook: update_order — an edited order keeps its type, unless the type was
 * changed in the order editor of the admin panel.
 *
 * @param mixed $order
 * @param mixed $order_id
 */
function fn_euph_profile_types_update_order(&$order = [], $order_id = 0): void
{
    if (!is_array($order)) {
        return;
    }
    $catalog = fn_euph_profile_types_container()->catalog();
    $langCode = fn_euph_profile_types_lang();
    $candidates = [$order['euph_profile_type'] ?? ''];
    if (!fn_euph_profile_types_is_storefront()) {
        $cart = fn_euph_profile_types_session_get('cart');
        $candidates[] = Cast::array($cart['user_data'] ?? [])['euph_profile_type'] ?? '';
    }
    foreach ($candidates as $code) {
        $type = $catalog->assignableByAdmin(UserKind::Customer, CodeValidator::normalize(Cast::string($code)), $langCode);
        if ($type !== null) {
            $order['euph_profile_type'] = $type->code;

            return;
        }
    }
    unset($order['euph_profile_type']);
}

/**
 * Hook: get_order_info — the type's name for e-mail templates
 * ({{ order_info.euph_profile_type_name }}) and add-ons.
 *
 * @param mixed $order
 * @param mixed $additional_data
 */
function fn_euph_profile_types_get_order_info(&$order = [], $additional_data = []): void
{
    if (!is_array($order)) {
        return;
    }
    $code = Cast::string($order['euph_profile_type'] ?? '');
    if ($code !== '') {
        $order['euph_profile_type_name'] = fn_euph_profile_types_get_type_name($code, Cast::string($order['lang_code'] ?? ''));
    }
}
