<?php

declare(strict_types=1);

use Tygh\Addons\EuphProfileTypes\Cast;
use Tygh\Addons\EuphProfileTypes\Enum\FieldArea;
use Tygh\Addons\EuphProfileTypes\Enum\UserKind;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

/*
 * Orders → Add order / Edit order: the customer information of the order
 * shows the fields of the order's type, with the type choice first. The
 * type goes with the cart and, when the order is saved, into the order.
 */

$catalog = fn_euph_profile_types_container()->catalog();
if (!$catalog->isEnabled(UserKind::Customer)) {
    return;
}
$langCode = fn_euph_profile_types_lang();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // The type of the customer information dialog: only a type the
    // administrator may give is kept.
    $userData = Cast::array($_REQUEST['user_data'] ?? []);
    if (array_key_exists('euph_profile_type', $userData)) {
        $type = $catalog->assignableByAdmin(UserKind::Customer, Cast::string($userData['euph_profile_type']), $langCode);
        if ($type === null) {
            unset($userData['euph_profile_type']);
        } else {
            $userData['euph_profile_type'] = $type->code;
        }
        $_REQUEST['user_data'] = $userData;
    }

    return;
}

$requested = $catalog->assignableByAdmin(UserKind::Customer, fn_euph_profile_types_requested_code(), $langCode);
if ($requested !== null) {
    $cart = fn_euph_profile_types_session_get('cart');
    $cartUserData = Cast::array($cart['user_data'] ?? []);
    $cartUserData['euph_profile_type'] = $requested->code;
    $cart['user_data'] = $cartUserData;
    // In the order editor the type lives in the customer data only: choosing
    // another customer brings that customer's type along.
    unset($cart['euph_profile_type']);
    fn_euph_profile_types_session_put('cart', $cart);
}

$type = fn_euph_profile_types_current_customer_type();
if ($type !== null) {
    fn_euph_profile_types_set_field_context([
        'kind' => UserKind::Customer->value,
        'code' => $type->code,
        'area' => FieldArea::Checkout->value,
        'choice' => 'admin',
    ]);
}
