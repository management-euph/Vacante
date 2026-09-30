<?php

/**
 * NETOPIA Payments — keep the admin on the NETOPIA settings after Save.
 *
 * The settings form uploads key files, so it is a plain (non-AJAX)
 * multipart post, and CS-Cart's payments controller answers it with a
 * redirect to the payment methods list: the admin never sees whether the
 * keys went in. CS-Cart runs this post controller after the core one; the
 * redirect returned here replaces the core's, and brings the admin back to
 * this payment method's settings, where the notices of the save (key
 * uploaded, POS signature filled in from the key file…) are shown.
 *
 * Only for an existing NETOPIA payment method; anything else keeps the
 * core's behaviour.
 *
 * @package NetopiaPayments
 */

if (!defined('BOOTSTRAP')) {
    die('Access denied');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $mode !== 'update' || defined('AJAX_REQUEST')) {
    return;
}

$netopia_payment_id = isset($_REQUEST['payment_id']) && is_numeric($_REQUEST['payment_id']) ? (int) $_REQUEST['payment_id'] : 0;
if ($netopia_payment_id <= 0) {
    return;
}

$netopia_script = db_get_field(
    'SELECT pp.processor_script FROM ?:payment_processors pp '
    . 'JOIN ?:payments p ON p.processor_id = pp.processor_id '
    . 'WHERE p.payment_id = ?i',
    $netopia_payment_id,
);
if ($netopia_script !== 'netopia_payments.php') {
    return;
}

return [CONTROLLER_STATUS_REDIRECT, 'payments.update?payment_id=' . $netopia_payment_id];
