<?php

declare(strict_types=1);

use Tygh\Addons\EuphProfileTypes\Cast;
use Tygh\Addons\EuphProfileTypes\Enum\UserKind;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

/*
 * Registration and the customer account: the field list CS-Cart read is
 * already the type's (hooks/field_hooks.php); the type choice drawn first in
 * it shows the type the page is drawn for.
 */

if ($_SERVER['REQUEST_METHOD'] === 'POST' || ($mode !== 'add' && $mode !== 'update')) {
    return;
}
$context = fn_euph_profile_types_storefront_context(UserKind::Customer, 'C');
if ($context === null) {
    return;
}
$userData = Cast::array(fn_euph_profile_types_template_var('user_data'));
$userData['euph_profile_type'] = $context->type->code;
fn_euph_profile_types_assign('user_data', $userData);
