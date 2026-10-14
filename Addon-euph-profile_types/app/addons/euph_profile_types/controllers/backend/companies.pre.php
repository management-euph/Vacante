<?php

declare(strict_types=1);

use Tygh\Addons\EuphProfileTypes\Cast;
use Tygh\Addons\EuphProfileTypes\Enum\UserKind;
use Tygh\Addons\EuphProfileTypes\Service\CodeValidator;
use Tygh\Registry;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

/*
 * Vendors → Vendors (Multi-Vendor): the posted vendor type is checked before
 * CS-Cart saves the vendor. A vendor working in its own panel cannot change
 * its type. The type the vendor had is kept for update_company, which moves
 * the vendor's administrators to the groups of the new type.
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($mode !== 'update' && $mode !== 'add') || !fn_euph_profile_types_vendor_types_on()) {
    return;
}

$companyData = Cast::array($_REQUEST['company_data'] ?? []);
if (array_key_exists('euph_profile_type', $companyData)) {
    $type = Cast::int(Registry::get('runtime.company_id')) > 0
        ? null
        : fn_euph_profile_types_container()->catalog()->assignableByAdmin(
            UserKind::Vendor,
            CodeValidator::normalize(Cast::string($companyData['euph_profile_type'])),
            fn_euph_profile_types_lang(),
        );
    if ($type === null) {
        unset($companyData['euph_profile_type']);
    } else {
        $companyData['euph_profile_type'] = $type->code;
    }
    $_REQUEST['company_data'] = $companyData;
}

$companyId = Cast::int($_REQUEST['company_id'] ?? 0);
if ($companyId > 0) {
    Registry::set('runtime.euph_profile_types.company_previous.' . $companyId, fn_euph_profile_types_company_code($companyId));
}
