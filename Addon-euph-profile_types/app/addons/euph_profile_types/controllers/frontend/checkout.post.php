<?php

declare(strict_types=1);

use Tygh\Addons\EuphProfileTypes\Cast;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    return;
}

// The order placed: its customer information shows the fields of its type.
if ($mode === 'complete') {
    $orderId = Cast::int($_REQUEST['order_id'] ?? 0);
    if ($orderId > 0) {
        fn_euph_profile_types_set_order_context(Cast::string(fn_euph_profile_types_container()->db()->value(
            'SELECT euph_profile_type FROM ?:orders WHERE order_id = ?i',
            $orderId,
        )));
    }
}
