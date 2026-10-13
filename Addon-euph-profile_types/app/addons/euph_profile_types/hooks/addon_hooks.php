<?php

declare(strict_types=1);

use Tygh\Addons\EuphProfileTypes\Cast;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

/**
 * Hook: update_addon_status_pre — disabling the add-on gives the checkout
 * blocks their own filling back, so the storefront checkout works as stock.
 * Enabling it again is handled by init.php on the next admin request (the
 * add-on's hooks are not registered while it is off).
 *
 * @param mixed $addon
 * @param mixed $status
 */
function fn_euph_profile_types_update_addon_status_pre($addon = '', $status = ''): void
{
    if (Cast::string($addon) !== 'euph_profile_types' || Cast::string($status) !== 'D') {
        return;
    }
    try {
        fn_euph_profile_types_switch_checkout_blocks(false);
    } catch (\Throwable $e) {
        error_log('euph_profile_types: checkout blocks were not restored: ' . $e->getMessage());
    }
}
