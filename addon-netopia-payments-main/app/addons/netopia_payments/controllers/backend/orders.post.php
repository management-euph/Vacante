<?php

/**
 * NETOPIA Payments — Post-controller hook for orders.
 *
 * Adds the $netopia_payment_link_available flag to the order details template
 * so the "Send payment link" button is shown for NETOPIA orders.
 *
 * @package NetopiaPayments
 */

use Netopia\CsCart\Ipn\IpnHandler;
use Netopia\CsCart\Payment\RefundBasis;
use Netopia\CsCart\Support\Arr;
use Tygh\Tygh;

if (!defined('BOOTSTRAP')) {
    die('Access denied');
}

if ($mode === 'details') {
    $view = Tygh::$app['view'];
    if (!is_object($view) || !method_exists($view, 'getTemplateVars') || !method_exists($view, 'assign')) {
        return;
    }

    $order_info = $view->getTemplateVars('order_info');
    if (!is_array($order_info) || empty($order_info['payment_id'])) {
        return;
    }

    $processor = db_get_row(
        'SELECT pp.processor_script FROM ?:payment_processors pp '
        . 'JOIN ?:payments p ON p.processor_id = pp.processor_id '
        . 'WHERE p.payment_id = ?i',
        $order_info['payment_id'],
    );

    if (!empty($processor) && $processor['processor_script'] === 'netopia_payments.php') {
        $view->assign('netopia_payment_link_available', true);
        // The panel offers a payment link only while the order is unpaid.
        $view->assign(
            'netopia_payment_link_allowed',
            in_array(is_string($order_info['status'] ?? null) ? $order_info['status'] : '', ['F', 'O', 'I', 'N', 'D'], true),
        );

        // Refund-eligibility flag drives the "Refund via NETOPIA" button on
        // the order details template hook. The trigger (and the modal that
        // shows full refund history + paid/refunded summary) is rendered
        // whenever three independent gates hold:
        //
        //   (a) The order is in a CS-Cart status that means "we received the
        //       money" — Paid (default P), Confirmed (default P), or one of
        //       the two refund statuses (default P / B). A Failed/Declined
        //       order has a `transaction_id` set by IpnHandler on the
        //       failed-IPN write but no money ever moved, so refunding is
        //       impossible — NETOPIA's API would reject and the customer
        //       would (rightly) get confused.
        //
        //   (b) `transaction_id` is set so we have an ntpID to refund
        //       against.
        //
        //   (c) The refund basis (RefundBasis: paid amount + currency) is
        //       known, the same check the refund controller makes.
        //
        // Refundable balance > 0 is a SEPARATE gate (`netopia_refund_can_submit`)
        // — when the order has been fully refunded the modal still opens
        // (admin can review the refund history) but the amount input and
        // submit button are hidden.
        $payment_info = is_array($order_info['payment_info'] ?? null) ? $order_info['payment_info'] : [];
        $netopia_payment_id = is_string($payment_info['transaction_id'] ?? null) ? $payment_info['transaction_id'] : '';

        // Source amounts from the refund basis the refund controller caps
        // and sends in (RefundBasis): the START-REQUEST charge
        // (`netopia_start_amount`, e.g. "68,70 EUR"), falling back to the
        // IPN's `netopia_amount` for orders that pre-date it. NOT the IPN's
        // post-FX amount (e.g. "361,78 RON") and NOT CS-Cart's display
        // currency: the panel's paid / refunded / remaining figures, the
        // currency label, "Refund all" and the bar must all be in the
        // currency NETOPIA refunds in — otherwise the admin types "50"
        // meaning RON and we send 50 EUR. `netopia_refunded_amount` is
        // written by RefundFinalizer on the same basis.
        $basis = RefundBasis::fromPaymentInfo($payment_info);
        $refundable_remaining = $basis->remaining();

        $processor_data = fn_netopia_get_payment_method_data(Arr::int($order_info, 'payment_id'));
        $processor_params = is_array($processor_data['processor_params'] ?? null)
            ? Arr::stringKeys($processor_data['processor_params'])
            : [];
        $paid_status_codes = array_unique(array_filter([
            Arr::string($processor_params, 'status_map_3', 'P'),         // Paid
            Arr::string($processor_params, 'status_map_5', 'P'),         // Confirmed
            Arr::string($processor_params, 'status_map_8_partial', 'P'), // Partial refund (still considered paid)
            Arr::string($processor_params, 'status_map_8_full', 'B'),    // Full refund
        ], static fn (string $s): bool => $s !== ''));
        $current_status = is_string($order_info['status'] ?? null) ? $order_info['status'] : '';
        $is_paid_status = $current_status !== '' && in_array($current_status, $paid_status_codes, true);

        if ($is_paid_status && $netopia_payment_id !== '' && $basis->isKnown()) {
            $refund_log = is_string($payment_info['netopia_refund_log'] ?? null)
                ? $payment_info['netopia_refund_log']
                : '';
            $view->assign('netopia_refund_available', true);
            $view->assign('netopia_refund_can_submit', $refundable_remaining > 0.0);
            $view->assign('netopia_refund_remaining', $refundable_remaining);
            $view->assign('netopia_refund_remaining_display', IpnHandler::formatAmount($refundable_remaining, $basis->currency));
            $view->assign('netopia_refund_already_refunded', $basis->alreadyRefunded);
            $view->assign('netopia_refund_already_refunded_display', IpnHandler::formatAmount($basis->alreadyRefunded, $basis->currency));
            $view->assign('netopia_refund_paid_display', IpnHandler::formatAmount($basis->paid, $basis->currency));
            $view->assign('netopia_refund_currency', $basis->currency);
            // The order panel's bar: the refunded share of the paid amount.
            $view->assign('netopia_refund_paid', $basis->paid);
            $view->assign('netopia_refund_refunded_pct', $basis->refundedPercent());
            $view->assign('netopia_refund_history', fn_netopia_parse_refund_log($refund_log));
            // The original payment's NETOPIA-side order id (the
            // `<csCartId>-<retrySuffix>` value shown in NETOPIA's merchant
            // dashboard). Same for every refund row of this order, so
            // assigned once at the view level and rendered repeatedly per
            // row in the modal table.
            $view->assign(
                'netopia_refund_history_payment_id',
                is_string($payment_info['netopia_order_id'] ?? null) ? $payment_info['netopia_order_id'] : '',
            );

            // Strip the verbose refund-history string from the order
            // detail's right-sidebar payment_info section. CS-Cart
            // renders every payment_info key/value pair generically;
            // `netopia_refund_log` is a one-line audit string ("[date]
            // X RON — partial refund (ntpID) [origin]" repeated) that
            // duplicates — and is harder to read than — the structured
            // table inside the "Refund via NETOPIA" modal. The raw
            // value remains in the database (RefundFinalizer keeps
            // appending to it; the IPN dedup substring-matches against
            // it), but it's hidden from the sidebar render path.
            unset($payment_info['netopia_refund_log']);
            $order_info['payment_info'] = $payment_info;
            $view->assign('order_info', $order_info);
        }
    }
}
