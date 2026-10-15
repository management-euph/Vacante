<?php

declare(strict_types=1);

use Tygh\Addons\EuphProfileTypes\Cast;
use Tygh\Registry;

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

    return;
}

// "Do not ask again for invoice details taken from the profile": the
// "Issue an invoice" payment of the Russian Payments add-on. The details its
// settings map to profile fields are filled from the customer's data, and the
// add-on's script hides the inputs already filled.
if ($mode === 'checkout'
    && fn_euph_profile_types_container()->settings()->invoiceDetailsFromProfile
    && Registry::get('addons.rus_payments.status') === 'A'
) {
    $details = fn_euph_profile_types_invoice_details(fn_euph_profile_types_session_get('cart'));
    if ($details !== []) {
        fn_euph_profile_types_assign('euph_pt_invoice_prefill', fn_euph_profile_types_js_value($details));
    }
}
