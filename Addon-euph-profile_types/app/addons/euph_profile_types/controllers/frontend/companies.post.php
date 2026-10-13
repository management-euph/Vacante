<?php

declare(strict_types=1);

use Tygh\Addons\EuphProfileTypes\Cast;
use Tygh\Addons\EuphProfileTypes\Enum\UserKind;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

// The vendor application: the type choice shows the type the form is drawn for.
if ($_SERVER['REQUEST_METHOD'] === 'POST' || $mode !== 'apply_for_vendor' || !fn_euph_profile_types_vendor_types_on()) {
    return;
}
$context = fn_euph_profile_types_storefront_context(UserKind::Vendor, 'C');
if ($context === null) {
    return;
}
foreach (['company_data', 'user_data'] as $var) {
    $data = fn_euph_profile_types_template_var($var);
    if (is_array($data) || $var === 'company_data') {
        $data = Cast::array($data);
        $data['euph_profile_type'] = $context->type->code;
        fn_euph_profile_types_assign($var, $data);
    }
}
