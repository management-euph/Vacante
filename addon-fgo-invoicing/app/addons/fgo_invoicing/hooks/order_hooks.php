<?php

declare(strict_types=1);

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

use Tygh\Addons\FgoInvoicing\Helpers\TypeCoerce;
use Tygh\Addons\FgoInvoicing\Services\ConfigProvider;
use Tygh\Addons\FgoInvoicing\Services\Container;

/**
 * Hook: place_order_post — issue the invoice immediately when configured for "onOrder".
 *
 * @param int|string $order_id
 * @param string $action
 * @param string $order_status
 * @param array<string, mixed> $cart
 * @param array<string, mixed> $auth
 */
function fn_fgo_invoicing_place_order_post(
    &$order_id,
    &$action = '',
    &$order_status = '',
    &$cart = [],
    &$auth = [],
): void
{
    $oid = TypeCoerce::toInt($order_id);
    if (ConfigProvider::apiCall() !== 'onOrder' || $oid <= 0) {
        return;
    }
    Container::getInstance()->issuer()->issueForOrder($oid);
}

/**
 * Hook: change_order_status — issue (or attempt to issue) when the order
 * transitions into a payment-confirmed or completion status.
 *
 * EVERY parameter past $order_info carries a default ON PURPOSE. CS-Cart
 * passes a different number of arguments to this hook depending on the build:
 * 4.x stores call fn_set_hook('change_order_status', $status_to, $status_from,
 * $order_info, $force_notification, $order_statuses, $place_order) — six — while
 * newer ones append $reason. A hook that requires all seven dies with
 *
 *     ArgumentCountError: Too few arguments to function
 *     fn_fgo_invoicing_change_order_status(), 6 passed in
 *     app/functions/fn.control.php on line 124 and exactly 7 expected
 *
 * from inside fn_place_order() — i.e. the customer's checkout blows up on
 * "Place order", after the order row is written. Defaults make the hook
 * tolerant of any arity the core happens to use; extra arguments are harmless
 * (PHP passes them to user functions without complaint). The first three are
 * required in every known CS-Cart version, so they stay mandatory.
 *
 * @param string $status_to New status code
 * @param string $status_from Previous status code
 * @param array<string, mixed> $order_info
 * @param bool $force_notification
 * @param array<string, mixed> $order_statuses
 * @param bool $place_order
 * @param string $reason
 */
function fn_fgo_invoicing_change_order_status(
    &$status_to,
    &$status_from,
    &$order_info,
    &$force_notification = false,
    &$order_statuses = [],
    &$place_order = false,
    &$reason = '',
): void {
    $orderId = TypeCoerce::toInt($order_info['order_id'] ?? 0);
    if ($orderId <= 0) {
        return;
    }

    $trigger = ConfigProvider::apiCall();
    $statusTo = TypeCoerce::toString($status_to);
    $isPay = ($trigger === 'onPayment' && in_array($statusTo, ['P', 'C'], true));
    $isDone = ($trigger === 'onCompleted' && $statusTo === 'C');

    if (!$isPay && !$isDone) {
        return;
    }

    Container::getInstance()->issuer()->issueForOrder($orderId, $order_info);
}

/**
 * Hook: get_order_info — attach the persisted FGO invoice row onto $order
 * so the admin order-details template can render the panel.
 *
 * @param array<string, mixed> $order
 * @param array<string, mixed> $additional_data
 */
function fn_fgo_invoicing_get_order_info(&$order, $additional_data = []): void
{
    $orderId = TypeCoerce::toInt($order['order_id'] ?? 0);
    if ($orderId <= 0) {
        return;
    }
    $row = Container::getInstance()->repository()->findByOrderId($orderId);
    if ($row !== null) {
        $order['fgo_invoice'] = $row;
    }
}
