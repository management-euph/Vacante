<?php

declare(strict_types=1);

use Tygh\Addons\EuphProfileTypes\Cast;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

// An order in the customer account: its customer information shows the
// fields of the order's type, the type first.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $mode === 'details') {
    $order = Cast::array(fn_euph_profile_types_template_var('order_info'));
    fn_euph_profile_types_set_order_context(Cast::string($order['euph_profile_type'] ?? ''));
}
