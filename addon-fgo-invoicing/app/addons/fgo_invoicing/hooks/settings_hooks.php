<?php

declare(strict_types=1);

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

/**
 * Hook: dispatch_before_display — settings self-heal.
 *
 * Here and not in init.php because by dispatch time CS-Cart is fully
 * initialised (CART_LANGUAGE defined), which the Settings API needs. The heal
 * gates itself (admin area, travel_core present, stamp), so the steady state
 * costs one ?:storage_data read. It lives in func.php's functions/, which
 * CS-Cart has loaded by now; function_exists() keeps a partial bootstrap safe.
 */
function fn_fgo_invoicing_dispatch_before_display(): void
{
    if (function_exists('fn_fgo_invoicing_heal_settings_once')) {
        fn_fgo_invoicing_heal_settings_once();
    }
}
