<?php

/**
 * NETOPIA Payments — Admin controller for generating and sending payment links.
 *
 * Routes:
 *   POST dispatch=netopia_payment_link.generate  — Generate a payment link for an order
 *   POST dispatch=netopia_payment_link.send      — Generate + email the link to the customer
 *
 * @package NetopiaPayments
 */

if (!defined('BOOTSTRAP')) {
    die('Access denied');
}

$order_id = isset($_REQUEST['order_id']) && is_numeric($_REQUEST['order_id']) ? (int) $_REQUEST['order_id'] : 0;

if (empty($order_id)) {
    fn_set_notification('E', __('error'), __('netopia_payment_link_no_order'));
    return [CONTROLLER_STATUS_REDIRECT, fn_url('orders.manage')];
}

// Security: verify the admin has permission to manage this order
$order_info = fn_get_order_info($order_id);
if (empty($order_info)) {
    fn_set_notification('E', __('error'), __('netopia_order_not_found'));
    return [CONTROLLER_STATUS_REDIRECT, fn_url('orders.manage')];
}

if ($mode === 'generate' || $mode === 'send') {
    $result = fn_netopia_generate_payment_link($order_id);

    if (!$result['success']) {
        fn_set_notification('E', __('error'), __('netopia_payment_link_error', ['[error]' => $result['error']]));
        return [CONTROLLER_STATUS_REDIRECT, fn_url('orders.details?order_id=' . $order_id)];
    }

    $payment_url = $result['payment_url'];

    fn_set_notification('N', __('notice'), __('netopia_payment_link_generated', ['[url]' => $payment_url]));

    // If mode is 'send', also email the link to the customer
    if ($mode === 'send') {
        $email_sent = fn_netopia_send_payment_link_email($order_id, $payment_url);

        if ($email_sent) {
            $email = is_scalar($order_info['email']) ? (string) $order_info['email'] : '';
            fn_set_notification('N', __('notice'), __('netopia_payment_link_sent', ['[email]' => $email]));
        } else {
            fn_set_notification('W', __('warning'), __('netopia_payment_link_email_failed'));
        }
    }

    return [CONTROLLER_STATUS_REDIRECT, fn_url('orders.details?order_id=' . $order_id)];
}

return [CONTROLLER_STATUS_NO_PAGE];
