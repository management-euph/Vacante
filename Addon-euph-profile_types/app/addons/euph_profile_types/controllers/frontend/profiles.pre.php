<?php

declare(strict_types=1);

use Tygh\Addons\EuphProfileTypes\Cast;
use Tygh\Addons\EuphProfileTypes\Enum\UserKind;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

// The registration form opened: the type it was sent with last and the type
// of a locked link count only while the form comes back with an error.
if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    && $mode === 'add'
    && fn_euph_profile_types_signed_in_customer_id() === 0
    && fn_euph_profile_types_container()->catalog()->isEnabled(UserKind::Customer)
) {
    fn_euph_profile_types_open_form(UserKind::Customer);
}

// The registration form remembers the type it was sent with: when it comes
// back with an error, it opens on that type again.
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && $mode === 'update'
    && fn_euph_profile_types_signed_in_customer_id() === 0
    && fn_euph_profile_types_container()->catalog()->isEnabled(UserKind::Customer)
) {
    $posted = Cast::string(Cast::array($_REQUEST['user_data'] ?? [])['euph_profile_type'] ?? '');
    if ($posted !== '') {
        fn_euph_profile_types_remember_form_type(UserKind::Customer, $posted);
    }
}
