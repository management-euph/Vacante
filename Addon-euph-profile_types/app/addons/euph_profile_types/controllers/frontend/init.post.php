<?php

declare(strict_types=1);

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

// The same rule for every other way in (a social network, a link, a session
// opened before the rule): a customer whose type does not work on this
// storefront is signed out. Checked once per session and storefront.
$signedOut = fn_euph_profile_types_enforce_storefront_login();
if ($signedOut) {
    return [CONTROLLER_STATUS_REDIRECT, 'auth.login_form'];
}
