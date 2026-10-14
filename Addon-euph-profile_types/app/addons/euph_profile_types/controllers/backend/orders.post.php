<?php

declare(strict_types=1);

use Tygh\Addons\EuphProfileTypes\Cast;
use Tygh\Addons\EuphProfileTypes\Enum\FieldArea;
use Tygh\Addons\EuphProfileTypes\Enum\UserKind;
use Tygh\Registry;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

/*
 * Orders → View orders → an order: the profile type is the first block of
 * the sidebar (hooks/orders/details_sidebar.pre.tpl) and the customer
 * information shows the fields of that type.
 */

if ($_SERVER['REQUEST_METHOD'] === 'POST' || $mode !== 'details') {
    return;
}
$catalog = fn_euph_profile_types_container()->catalog();
if (!$catalog->isEnabled(UserKind::Customer)) {
    return;
}

$order = Cast::array(fn_euph_profile_types_template_var('order_info'));
$code = Cast::string($order['euph_profile_type'] ?? '');
$type = $code !== '' ? $catalog->byCode($code, fn_euph_profile_types_lang()) : null;

fn_euph_profile_types_assign('euph_pt_order_type', $type !== null ? $type->toArray() : ($code !== '' ? ['code' => $code, 'name' => $code] : null));
fn_euph_profile_types_assign(
    'euph_pt_order_type_options',
    Cast::int(Registry::get('runtime.company_id')) === 0 ? fn_euph_profile_types_admin_options(UserKind::Customer, $code) : [],
);

if ($type !== null) {
    fn_euph_profile_types_set_order_context($type->code);
    fn_euph_profile_types_assign('profile_fields', fn_euph_profile_types_get_fields(
        'I',
        UserKind::Customer,
        $type->code,
        FieldArea::Checkout,
        [$type->code => $type->displayName()],
    ));
}
