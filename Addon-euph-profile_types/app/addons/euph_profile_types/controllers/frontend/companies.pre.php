<?php

declare(strict_types=1);

use Tygh\Addons\EuphProfileTypes\Cast;
use Tygh\Addons\EuphProfileTypes\Enum\FieldArea;
use Tygh\Addons\EuphProfileTypes\Enum\UserKind;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

if (!fn_euph_profile_types_vendor_types_on()) {
    return;
}
$container = fn_euph_profile_types_container();

// The vendor application: the posted type is kept only when it may be
// applied for on this storefront, else the vendor gets the default type.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $mode === 'apply_for_vendor') {
    $companyData = Cast::array($_REQUEST['company_data'] ?? []);
    $posted = Cast::string($companyData['euph_profile_type'] ?? (Cast::array($_REQUEST['user_data'] ?? [])['euph_profile_type'] ?? ''));
    $langCode = fn_euph_profile_types_lang();
    $storefrontId = fn_euph_profile_types_storefront_id();
    $type = $container->catalog()->assignableOnStorefront(UserKind::Vendor, $posted, $storefrontId, $langCode)
        ?? $container->catalog()->defaultType(UserKind::Vendor, $storefrontId, $langCode);
    if ($type !== null) {
        $companyData['euph_profile_type'] = $type->code;
        $_REQUEST['company_data'] = $companyData;
    }
    if ($posted !== '') {
        fn_euph_profile_types_remember_form_type(UserKind::Vendor, $posted);
    }

    return;
}

// The vendor card on the storefront: the fields its type shows there, and,
// with "Use the name of the profile type in the vendor information", the type.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && ($mode === 'view' || $mode === 'products')) {
    $code = fn_euph_profile_types_company_code(Cast::int($_REQUEST['company_id'] ?? 0));
    if ($code !== '') {
        fn_euph_profile_types_set_field_context([
            'kind' => UserKind::Vendor->value,
            'code' => $code,
            'area' => FieldArea::Storefront->value,
            'choice' => $container->settings()->vendorTypeInProduct ? 'display' : '',
        ]);
    }
}
