<?php

declare(strict_types=1);

use Tygh\Addons\EuphProfileTypes\Cast;
use Tygh\Addons\EuphProfileTypes\Enum\UserKind;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

/*
 * The type of the order, chosen at checkout by a guest as well. The choice
 * is drawn first in the contact information block; changing it reloads the
 * checkout with ?euph_profile_type=CODE, and the checkout's own saving of
 * the customer data sends it as user_data[euph_profile_type]. Without the
 * choice (setting "Profile type choice at checkout") a signed-in customer
 * orders with the type of the profile, a guest with the default type.
 */

$container = fn_euph_profile_types_container();
$catalog = $container->catalog();
if (!$catalog->isEnabled(UserKind::Customer)) {
    return;
}
$langCode = fn_euph_profile_types_lang();
$storefrontId = fn_euph_profile_types_storefront_id();
$cart = fn_euph_profile_types_session_get('cart');
$cartUserData = Cast::array($cart['user_data'] ?? []);
$offered = $container->settings()->checkoutChoice->offeredTo(fn_euph_profile_types_signed_in_customer_id() > 0);

$chosen = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedData = Cast::array($_REQUEST['user_data'] ?? []);
    if (array_key_exists('euph_profile_type', $postedData)) {
        $chosen = Cast::string($postedData['euph_profile_type']);
        // The type is the cart's: what the choice may not change is dropped.
        unset($postedData['euph_profile_type']);
        $_REQUEST['user_data'] = $postedData;
    }
} else {
    $chosen = fn_euph_profile_types_requested_code();
}

$type = null;
if ($chosen !== '' && $offered) {
    $type = $catalog->assignableOnStorefront(UserKind::Customer, $chosen, $storefrontId, $langCode);
}
if ($type === null && !$offered) {
    // No choice: the type of the profile, else the default.
    unset($cart['euph_profile_type'], $cartUserData['euph_profile_type']);
    $cart['user_data'] = $cartUserData;
    fn_euph_profile_types_session_put('cart', $cart);
}
$type ??= fn_euph_profile_types_current_customer_type();
if ($type === null) {
    return;
}

if (Cast::string($cart['euph_profile_type'] ?? '') !== $type->code
    || Cast::string($cartUserData['euph_profile_type'] ?? '') !== $type->code
) {
    $cart = fn_euph_profile_types_session_get('cart');
    $cartUserData = Cast::array($cart['user_data'] ?? []);
    $cart['euph_profile_type'] = $type->code;
    $cartUserData['euph_profile_type'] = $type->code;
    $cart['user_data'] = $cartUserData;
    fn_euph_profile_types_session_put('cart', $cart);
    if (function_exists('fn_save_cart_content')) {
        fn_save_cart_content($cart, fn_euph_profile_types_auth_user_id());
    }
}
