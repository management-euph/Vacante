<?php

/**
 * NETOPIA Payments — Admin controller for issuing refunds via the NETOPIA API.
 *
 * Routes:
 *   POST dispatch=netopia_refund.process — Refund (full or partial) the order
 *
 * Flow:
 *   1. Validate the order belongs to the NETOPIA processor and has a stored
 *      payment ntpID to refund against.
 *   2. Validate the requested amount is > 0 and ≤ remaining refundable.
 *   3. Call NETOPIA's `operation/credit` endpoint via RefundService.
 *   4. On API success, run RefundFinalizer synchronously so the order updates
 *      immediately. The trailing IPN from NETOPIA short-circuits at the
 *      ntpID-aware idempotency guard in IpnHandler.
 *   5. Surface the outcome via fn_set_notification.
 *
 * @package NetopiaPayments
 */

use Netopia\CsCart\Bootstrap;
use Netopia\CsCart\Ipn\IpnHandler;
use Netopia\CsCart\Payment\ClaimResult;
use Netopia\CsCart\Payment\RefundAttemptStore;
use Netopia\CsCart\Payment\RefundReplay;
use Netopia\CsCart\Payment\RefundService;
use Netopia\CsCart\Support\Arr;
use Tygh\Tygh;

if (!defined('BOOTSTRAP')) {
    die('Access denied');
}

$order_id = isset($_REQUEST['order_id']) && is_numeric($_REQUEST['order_id']) ? (int) $_REQUEST['order_id'] : 0;

if (empty($order_id)) {
    fn_set_notification('E', __('error'), __('netopia_payment_link_no_order'));
    return [CONTROLLER_STATUS_REDIRECT, fn_url('orders.manage')];
}

$order_info = fn_get_order_info($order_id);
if (empty($order_info)) {
    fn_set_notification('E', __('error'), __('netopia_order_not_found'));
    return [CONTROLLER_STATUS_REDIRECT, fn_url('orders.manage')];
}

$redirect_url = fn_url('orders.details?order_id=' . $order_id);

if ($mode !== 'process') {
    return [CONTROLLER_STATUS_NO_PAGE];
}

// --- Resolve processor + validate this is a NETOPIA order ---------------

$payment_id = is_numeric($order_info['payment_id'] ?? null) ? (int) $order_info['payment_id'] : 0;
$processor_row = db_get_row(
    'SELECT pp.processor_script FROM ?:payment_processors pp '
    . 'JOIN ?:payments p ON p.processor_id = pp.processor_id '
    . 'WHERE p.payment_id = ?i',
    $payment_id,
);
$processor_script_raw = is_array($processor_row) ? ($processor_row['processor_script'] ?? '') : '';
$processor_script = is_string($processor_script_raw) ? $processor_script_raw : '';
if ($processor_script !== 'netopia_payments.php') {
    fn_set_notification('E', __('error'), __('netopia_refund_not_netopia_order'));
    return [CONTROLLER_STATUS_REDIRECT, $redirect_url];
}

$processor_data = fn_get_payment_method_data($payment_id);
$processor_params = is_array($processor_data['processor_params'] ?? null) ? $processor_data['processor_params'] : [];
$processor_params = Arr::stringKeys($processor_params);
if ($processor_params === []) {
    fn_set_notification('E', __('error'), __('netopia_refund_processor_unconfigured'));
    return [CONTROLLER_STATUS_REDIRECT, $redirect_url];
}

// --- Resolve refund target -----------------------------------------------

$payment_info = is_array($order_info['payment_info'] ?? null) ? $order_info['payment_info'] : [];
$netopia_payment_id = is_string($payment_info['transaction_id'] ?? null) ? $payment_info['transaction_id'] : '';
if ($netopia_payment_id === '') {
    fn_set_notification('E', __('error'), __('netopia_refund_missing_payment_id'));
    return [CONTROLLER_STATUS_REDIRECT, $redirect_url];
}

// Source the cap amounts + currency from the START-REQUEST charge
// (`payment_info.netopia_start_amount`, e.g. "68,70 EUR"), NOT from
// `netopia_amount` which is the post-FX amount NETOPIA echoes in the IPN
// (e.g. "361,78 RON"). NETOPIA's /operation/credit cap is enforced against
// the original charge we sent to /payment/card/start; capping locally
// against the IPN amount lets the admin overshoot on FX-converted orders
// and triggers an error.code=99 "Invalid Credit amount" from NETOPIA.
//
// Backwards compat: orders placed before `netopia_start_amount` was
// introduced fall back to `netopia_amount`. Same-currency orders
// (start currency == IPN currency) are unaffected — the two values agree.
$netopia_start_amount_str = is_string($payment_info['netopia_start_amount'] ?? null)
    ? $payment_info['netopia_start_amount']
    : '';
$netopia_amount_str = $netopia_start_amount_str !== ''
    ? $netopia_start_amount_str
    : (is_string($payment_info['netopia_amount'] ?? null) ? $payment_info['netopia_amount'] : '');
$paid = IpnHandler::parseFormattedAmount($netopia_amount_str);
if ($paid['currency'] === '' || $paid['value'] <= 0.0) {
    // Distinct from `missing_payment_id` above: that path means the
    // order's NETOPIA payment ntpID hasn't been recorded; this path
    // means the order has no recorded *paid amount* (or its currency
    // suffix is missing) so we can't decide which currency to refund in.
    fn_set_notification('E', __('error'), __('netopia_refund_missing_paid_currency'));
    return [CONTROLLER_STATUS_REDIRECT, $redirect_url];
}
$currency = $paid['currency'];
$already_refunded_str = is_string($payment_info['netopia_refunded_amount'] ?? null)
    ? $payment_info['netopia_refunded_amount']
    : '';
$already_refunded = $already_refunded_str === '' ? 0.0 : IpnHandler::parseAmount($already_refunded_str);
$remaining_refundable = max(0.0, $paid['value'] - $already_refunded);

if ($remaining_refundable <= 0.0) {
    fn_set_notification('E', __('error'), __('netopia_refund_nothing_left'));
    return [CONTROLLER_STATUS_REDIRECT, $redirect_url];
}

// --- Validate inputs -----------------------------------------------------

$amount_raw = $_REQUEST['amount'] ?? null;
// Accept either dot- or comma- decimal input from the modal.
$amount_normalised = is_string($amount_raw) ? str_replace(',', '.', $amount_raw) : '';
$amount = is_numeric($amount_normalised) ? (float) $amount_normalised : 0.0;
if ($amount <= 0.0) {
    fn_set_notification('E', __('error'), __('netopia_refund_invalid_amount'));
    return [CONTROLLER_STATUS_REDIRECT, $redirect_url];
}
// Tolerate a half-cent overshoot from float math (mirrors the
// RefundFinalizer's full-vs-partial threshold).
if ($amount > $remaining_refundable + 0.005) {
    fn_set_notification(
        'E',
        __('error'),
        __('netopia_refund_amount_exceeds_remaining', [
            '[remaining]' => IpnHandler::formatAmount($remaining_refundable, $currency),
        ]),
    );
    return [CONTROLLER_STATUS_REDIRECT, $redirect_url];
}

// --- Issue the API call --------------------------------------------------

// Derive a stable refund-attempt id from (order, prior refund count,
// amount) so accidental duplicate clicks — admin double-click, transparent
// reload — collapse on the local PRIMARY KEY in `refund_attempts`. The
// prior count comes from the refund_log: each line written by
// RefundFinalizer is one prior refund event, so a non-empty log of N lines
// means refundIndex = N. The id is NOT transmitted to NETOPIA —
// `operation/credit` accepts no client-side idempotency key.
$prior_log = is_string($payment_info['netopia_refund_log'] ?? null)
    ? $payment_info['netopia_refund_log']
    : '';
$refund_index = $prior_log === ''
    ? 0
    : substr_count($prior_log, "\n") + 1;
$refund_request_id = RefundService::deriveRequestId($order_id, $refund_index, $amount);

// Build the finalizer ONCE above the claim branch so both the granted
// path AND the already_completed/self-heal path share the same instance.
// Previously the two paths each defined their own copy of the closures
// inline; the replay copy silently drifted from the granted-path version
// (the mailer-unavailable log was missing on one side). One instance,
// one source of truth.
$mailSender = static function (array $config, string $langCode): bool {
    $mailer = Tygh::$app['mailer'] ?? null;
    if (!is_object($mailer) || !method_exists($mailer, 'send')) {
        Bootstrap::instance()->logger->error(
            'NETOPIA refund email failed: Tygh::$app[\'mailer\'] is not available or has no send() method',
            ['mailer_type' => is_object($mailer) ? $mailer::class : gettype($mailer)],
        );
        return false;
    }
    return (bool) $mailer->send($config, 'C', $langCode);
};

$refundFinalizer = Bootstrap::instance()->refundFinalizer(
    paymentInfoUpdater: static function (int $orderId, array $info): void {
        fn_update_order_payment_info($orderId, Arr::stringKeys($info));
    },
    orderStatusChanger: static function (int $orderId, string $status, string $reason, bool $notify): void {
        // CS-Cart's fn_change_order_status signature is
        // ($order_id, $status_to, $status_from, $force_notification, ...).
        // The 3rd arg is the PREVIOUS status code (single letter), NOT a
        // reason text. Passing $reason there triggered "Undefined array
        // key '<reason text>'" warnings because CS-Cart looks the value
        // up in the order_statuses array. The reason is still recorded
        // in payment_info.reason_text and netopia_refund_log; we just
        // can't thread it through fn_change_order_status (which doesn't
        // accept reason text at all).
        unset($reason);
        fn_change_order_status($orderId, $status, '', $notify);
    },
    refundEmailSender:  static function (
        array $orderInfo,
        float $refund,
        float $cumulative,
        float $original,
        string $currency,
        string $ntpId,
        bool $isFullRefund,
    ) use ($mailSender): bool {
        return Bootstrap::instance()
            ->refundEmailSender($mailSender)
            ->send(Arr::stringKeys($orderInfo), $refund, $cumulative, $original, $currency, $ntpId, $isFullRefund);
    },
);

// Atomic claim against the refund_attempts table — gates against a second
// admin session firing the same intent. The PRIMARY KEY on request_id is
// the only synchronization primitive; PHP never makes a decision based on
// state it merely read.
$attempt_store = Bootstrap::instance()->refundAttemptStore();
$claim = $attempt_store->claim($refund_request_id, $order_id, $amount);

if ($claim->kind === ClaimResult::KIND_IN_FLIGHT) {
    $existing = $claim->existing ?? [];
    $age_seconds = max(0, time() - Arr::int($existing, 'created_at'));
    fn_set_notification(
        'W',
        __('warning'),
        __('netopia_refund_in_flight', ['[seconds]' => $age_seconds]),
    );
    return [CONTROLLER_STATUS_REDIRECT, $redirect_url];
}

if ($claim->kind === ClaimResult::KIND_ALREADY_COMPLETED) {
    $existing = $claim->existing ?? [];
    $existing_status = Arr::string($existing, 'status');

    if ($existing_status === RefundAttemptStore::STATUS_FAILED) {
        fn_set_notification(
            'E',
            __('error'),
            __('netopia_refund_already_failed', [
                '[error]' => Arr::string($existing, 'error'),
            ]),
        );
        return [CONTROLLER_STATUS_REDIRECT, $redirect_url];
    }

    // Self-heal: NETOPIA processed the refund earlier, but if the original
    // attempt crashed between markSucceeded and the local apply DB write,
    // the order's refund_log won't yet show the stored ntpID. Re-run apply
    // with the stored ntpID to reconcile. `RefundReplay::needsLocalReapply`
    // gates this on the stored ntpID being absent from the log so a
    // redundant heal is skipped before any write happens.
    $stored_ntp_id = Arr::string($existing, 'ntp_id');
    $existing_log = is_string($payment_info['netopia_refund_log'] ?? null)
        ? $payment_info['netopia_refund_log']
        : '';

    if (RefundReplay::needsLocalReapply($stored_ntp_id, $existing_log)) {
        try {
            $refundFinalizer->apply(
                orderId:         $order_id,
                orderInfo:       Arr::stringKeys($order_info),
                processorParams: $processor_params,
                amount:          $amount,
                currency:        $currency,
                ntpId:           $stored_ntp_id,
                netopiaOrderId:  is_string($payment_info['netopia_order_id'] ?? null) ? $payment_info['netopia_order_id'] : '',
                customerReason:  (string) __('netopia_customer_msg_refunded'),
                adminReason:     (string) __('netopia_refund_admin_reason', [
                    '[amount]' => IpnHandler::formatAmount($amount, $currency),
                ]),
                origin:          'admin-replay',
            );
        } catch (\Throwable $e) {
            Bootstrap::instance()->logger->error(
                'NETOPIA refund replay (self-heal) failed: ' . $e->getMessage(),
                [
                    'order_id' => $order_id,
                    'refund_request_id' => $refund_request_id,
                    'stored_ntp_id' => $stored_ntp_id,
                    'exception_class' => $e::class,
                ],
            );
            fn_set_notification(
                'W',
                __('warning'),
                __('netopia_refund_local_update_failed', [
                    '[amount]' => IpnHandler::formatAmount($amount, $currency),
                    '[error]' => $e->getMessage(),
                ]),
            );
            return [CONTROLLER_STATUS_REDIRECT, $redirect_url];
        }
    }

    fn_set_notification('N', __('notice'), __('netopia_refund_already_succeeded'));
    return [CONTROLLER_STATUS_REDIRECT, $redirect_url];
}

// claim->kind === KIND_GRANTED — we own this attempt.
$refund_service = Bootstrap::instance()->refundService($processor_params);
$response = $refund_service->process(
    orderId:   $order_id,
    paymentId: $netopia_payment_id,
    amount:    $amount,
);

if (!$response->isSuccess()) {
    $attempt_store->markFailed($refund_request_id, $response->message);
    fn_set_notification(
        'E',
        __('error'),
        __('netopia_refund_api_error', ['[error]' => $response->message]),
    );
    return [CONTROLLER_STATUS_REDIRECT, $redirect_url];
}

// --- Apply the refund to the CS-Cart order locally -----------------------

// NETOPIA's `operation/credit` returns a `payment.ntpID` on success —
// in practice the original payment's ntpID, repeated for every credit
// against that payment. We capture it on the refund row purely for
// audit linkage; it has no role in IPN dedup (the trailing IPN is
// acknowledged with no DB writes — see `IpnHandler` for why). Absence
// indicates a malformed response; refuse the local apply rather than
// fabricate a synthetic id, and surface a warning to the operator so
// the order state can be reconciled manually.
$refund_ntp_id = RefundService::refundNtpId($response);
if ($refund_ntp_id === '') {
    $attempt_store->markFailed(
        $refund_request_id,
        'NETOPIA API returned success without an ntpID; cannot reconcile locally.',
    );
    Bootstrap::instance()->logger->error(
        'NETOPIA refund API success but response lacked payment.ntpID — refusing local apply',
        [
            'order_id' => $order_id,
            'refund_request_id' => $refund_request_id,
            'response_code' => $response->code,
            'response_message' => $response->message,
        ],
    );
    fn_set_notification(
        'W',
        __('warning'),
        __('netopia_refund_missing_ntp_id', [
            '[amount]' => IpnHandler::formatAmount($amount, $currency),
        ]),
    );
    return [CONTROLLER_STATUS_REDIRECT, $redirect_url];
}

// NETOPIA has moved the money — record that fact in the attempt store BEFORE
// the local apply so a crash mid-apply still leaves a `succeeded` row that
// the self-heal replay can pick up on the next admin click.
$attempt_store->markSucceeded($refund_request_id, $refund_ntp_id);

// At this point NETOPIA has moved the money. Any exception below leaves the
// books out of sync until either the trailing IPN catches up or the admin
// re-clicks Refund (which goes through the self-heal replay path).
try {
    $result = $refundFinalizer->apply(
        orderId:         $order_id,
        orderInfo:       Arr::stringKeys($order_info),
        processorParams: $processor_params,
        amount:          $amount,
        currency:        $currency,
        ntpId:           $refund_ntp_id,
        netopiaOrderId:  is_string($payment_info['netopia_order_id'] ?? null) ? $payment_info['netopia_order_id'] : '',
        customerReason:  (string) __('netopia_customer_msg_refunded'),
        adminReason:     (string) __('netopia_refund_admin_reason', [
            '[amount]' => IpnHandler::formatAmount($amount, $currency),
        ]),
        origin:          'admin',
    );
} catch (\Throwable $e) {
    Bootstrap::instance()->logger->error(
        'NETOPIA refund applied at gateway but local order update failed: ' . $e->getMessage(),
        [
            'order_id' => $order_id,
            'refund_request_id' => $refund_request_id,
            'refund_ntp_id' => $refund_ntp_id,
            'amount' => $amount,
            'exception_class' => $e::class,
        ],
    );
    fn_set_notification(
        'W',
        __('warning'),
        __('netopia_refund_local_update_failed', [
            '[amount]' => IpnHandler::formatAmount($amount, $currency),
            '[error]' => $e->getMessage(),
        ]),
    );
    return [CONTROLLER_STATUS_REDIRECT, $redirect_url];
}

fn_set_notification(
    'N',
    __('notice'),
    __('netopia_refund_success', [
        '[amount]' => IpnHandler::formatAmount($result->refundAmount, $currency),
        '[status]' => $result->csStatus,
        '[is_full]' => $result->isFullRefund ? __('netopia_refund_full') : __('netopia_refund_partial'),
    ]),
);

return [CONTROLLER_STATUS_REDIRECT, $redirect_url];
