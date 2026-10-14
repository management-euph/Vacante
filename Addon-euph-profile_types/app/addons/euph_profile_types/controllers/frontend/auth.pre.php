<?php

declare(strict_types=1);

use Tygh\Addons\EuphProfileTypes\Cast;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

// "Let customers sign in only on the storefronts of their profile type":
// the sign-in form refuses a customer whose type does not work here.
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && $mode === 'login'
    && !fn_euph_profile_types_login_allowed(Cast::string($_REQUEST['user_login'] ?? ''))
) {
    fn_euph_profile_types_notify('E', fn_euph_profile_types_login_denied_message());
    $returnUrl = Cast::string($_REQUEST['return_url'] ?? '');

    return [CONTROLLER_STATUS_REDIRECT, $returnUrl !== '' ? $returnUrl : 'auth.login_form'];
}
