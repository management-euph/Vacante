<?php

declare(strict_types=1);

use Tygh\Addons\EuphProfileTypes\Cast;
use Tygh\Addons\EuphProfileTypes\Enum\FieldArea;
use Tygh\Addons\EuphProfileTypes\Enum\UserKind;
use Tygh\Addons\EuphProfileTypes\Service\CodeValidator;
use Tygh\Addons\EuphProfileTypes\Service\TypeCatalog;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

/*
 * Customers → Customers: the customer card shows the fields of the
 * customer's type with the type choice first (changing it redraws the
 * card); the list filters by type and shows it.
 */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    return;
}

$catalog = fn_euph_profile_types_container()->catalog();
if (!$catalog->isEnabled(UserKind::Customer)) {
    return;
}

if ($mode === 'update' || $mode === 'add') {
    $userData = Cast::array(fn_euph_profile_types_template_var('user_data'));
    $userType = Cast::string($userData['user_type'] ?? '')
        ?: (Cast::string(fn_euph_profile_types_template_var('user_type')) ?: Cast::string($_REQUEST['user_type'] ?? 'C'));
    if ($userType !== UserKind::Customer->value) {
        return;
    }
    $langCode = fn_euph_profile_types_lang();
    $requested = $catalog->assignableByAdmin(UserKind::Customer, fn_euph_profile_types_requested_code(), $langCode);
    $code = $requested !== null
        ? $requested->code
        : CodeValidator::normalize(Cast::string($userData['euph_profile_type'] ?? ''));
    $type = fn_euph_profile_types_type_or_default(UserKind::Customer, $code, $langCode);
    if ($type === null) {
        return;
    }
    $userData['euph_profile_type'] = $type->code;
    fn_euph_profile_types_assign('user_data', $userData);

    $fields = fn_euph_profile_types_get_fields(
        'C',
        UserKind::Customer,
        $type->code,
        FieldArea::Profile,
        fn_euph_profile_types_admin_options(UserKind::Customer, $type->code),
        $langCode,
    );
    fn_euph_profile_types_assign('profile_fields', $fields);
    if (function_exists('fn_check_shipping_billing')) {
        fn_euph_profile_types_assign('ship_to_another', fn_check_shipping_billing($userData, $fields));
    }
}

if ($mode === 'manage') {
    fn_euph_profile_types_assign('euph_pt_filter_types', fn_euph_profile_types_admin_options(UserKind::Customer));
    fn_euph_profile_types_assign('euph_pt_type_names', TypeCatalog::options(
        fn_euph_profile_types_container()->types()->ofKind(UserKind::Customer, fn_euph_profile_types_lang()),
    ));
}
