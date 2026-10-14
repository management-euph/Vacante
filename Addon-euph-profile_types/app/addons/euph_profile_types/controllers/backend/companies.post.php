<?php

declare(strict_types=1);

use Tygh\Addons\EuphProfileTypes\Cast;
use Tygh\Addons\EuphProfileTypes\Enum\FieldArea;
use Tygh\Addons\EuphProfileTypes\Enum\UserKind;
use Tygh\Addons\EuphProfileTypes\Service\CodeValidator;
use Tygh\Addons\EuphProfileTypes\Service\TypeCatalog;
use Tygh\Registry;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

/*
 * Vendors → Vendors (Multi-Vendor): the vendor card shows the fields of the
 * vendor's type with the type choice first; in the vendor's own panel the
 * type is only shown. The list filters by type and shows it.
 */

if ($_SERVER['REQUEST_METHOD'] === 'POST' || !fn_euph_profile_types_vendor_types_on()) {
    return;
}
$container = fn_euph_profile_types_container();
$langCode = fn_euph_profile_types_lang();

if ($mode === 'update' || $mode === 'add') {
    $companyData = Cast::array(fn_euph_profile_types_template_var('company_data'));
    $vendorPanel = Cast::int(Registry::get('runtime.company_id')) > 0;
    $requested = $vendorPanel
        ? null
        : $container->catalog()->assignableByAdmin(UserKind::Vendor, fn_euph_profile_types_requested_code(), $langCode);
    $code = $requested !== null
        ? $requested->code
        : CodeValidator::normalize(Cast::string($companyData['euph_profile_type'] ?? ''));
    $type = fn_euph_profile_types_type_or_default(UserKind::Vendor, $code, $langCode);
    if ($type === null) {
        return;
    }
    $companyData['euph_profile_type'] = $type->code;
    fn_euph_profile_types_assign('company_data', $companyData);
    fn_euph_profile_types_assign('profile_fields', fn_euph_profile_types_get_fields(
        'V',
        UserKind::Vendor,
        $type->code,
        FieldArea::Profile,
        $vendorPanel ? [$type->code => $type->displayName()] : fn_euph_profile_types_admin_options(UserKind::Vendor, $type->code),
        $langCode,
        ['skip_email_field' => false],
    ));
}

if ($mode === 'manage') {
    fn_euph_profile_types_assign('euph_pt_filter_types', fn_euph_profile_types_admin_options(UserKind::Vendor));
    fn_euph_profile_types_assign('euph_pt_type_names', TypeCatalog::options($container->types()->ofKind(UserKind::Vendor, $langCode)));
}
