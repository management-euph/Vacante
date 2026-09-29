<?php

declare(strict_types=1);

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

use Tygh\Addons\FgoInvoicing\Helpers\TypeCoerce;
use Tygh\Addons\FgoInvoicing\Services\ConfigProvider;
use Tygh\Addons\FgoInvoicing\Services\Container;
use Tygh\Addons\FgoInvoicing\Services\OrderInvoiceColumn;
use Tygh\Registry;

/**
 * Whether FGO invoice data may be attached to orders in this request: the
 * admin panel only (not the storefront, not the REST API), not for a
 * restricted admin (whom the FGO pages deny) and not while a storefront /
 * vendor is selected (runtime.company_id), where the FGO pages are not what
 * the admin is looking at. Both order hooks below answer to it, and the
 * order templates hide the FGO column and panel on the same terms.
 */
function fn_fgo_invoicing_shows_invoice_data(): bool
{
    if (!defined('AREA') || AREA !== 'A' || defined('API')) {
        return false;
    }
    if (defined('RESTRICTED_ADMIN') && RESTRICTED_ADMIN) {
        return false;
    }

    return TypeCoerce::toInt(Registry::get('runtime.company_id')) === 0;
}

/**
 * Hook: place_order_post — issue the invoice immediately when configured for "onOrder".
 *
 * The signature is CS-Cart 4.20's (app/functions/fn.cart.php, fn_place_order):
 *
 *     fn_set_hook('place_order_post', $cart, $auth, $action, $issuer_id,
 *                 $parent_order_id, $order_id, $order_status,
 *                 $short_order_data, $notification_rules);
 *
 * $cart comes FIRST and $order_id is the SIXTH argument. This hook used to be
 * declared ($order_id, $action, $order_status, $cart, $auth) — the argument
 * order of the separate 'place_order' hook — so it received the cart array as
 * $order_id, read 0 and returned: "Place order" mode never issued anything.
 *
 * Every parameter after the first has a default, for the same reason as
 * fn_fgo_invoicing_change_order_status() below: a build that passes fewer
 * arguments must not turn checkout into an ArgumentCountError. And nothing
 * may escape from here: this runs inside the customer's "Place order"
 * request, after the order row is written; the invoice can be issued again
 * from the admin, a crashed checkout cannot be undone.
 *
 * @param array<string, mixed> $cart
 * @param array<string, mixed> $auth
 * @param string $action
 * @param int|null $issuer_id
 * @param int $parent_order_id
 * @param int|string $order_id
 * @param string $order_status
 * @param array<string, mixed> $short_order_data
 * @param array<string, mixed> $notification_rules
 */
function fn_fgo_invoicing_place_order_post(
    &$cart,
    &$auth = [],
    &$action = '',
    &$issuer_id = null,
    &$parent_order_id = 0,
    &$order_id = 0,
    &$order_status = '',
    &$short_order_data = [],
    &$notification_rules = [],
): void {
    $oid = TypeCoerce::toInt($order_id);
    if (ConfigProvider::apiCall() !== 'onOrder' || $oid <= 0) {
        return;
    }
    try {
        Container::getInstance()->issuer()->issueForOrder($oid);
    } catch (\Throwable $e) {
        if (function_exists('fn_log_event')) {
            fn_log_event('fgo_invoicing', 'runtime', [
                'message' => '[error] place-order-post',
                'context' => ['order_id' => $oid, 'exception' => $e::class, 'message' => $e->getMessage()],
            ]);
        }
    }
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

    // Runs inside the status change, often the customer's payment callback:
    // nothing may escape (see fn_fgo_invoicing_place_order_post()). An order
    // another request is issuing right now answers `in_progress`; the issuer
    // logs it and there is nothing else to do here.
    try {
        Container::getInstance()->issuer()->issueForOrder($orderId, $order_info);
    } catch (\Throwable $e) {
        if (function_exists('fn_log_event')) {
            fn_log_event('fgo_invoicing', 'runtime', [
                'message' => '[error] change-order-status',
                'context' => ['order_id' => $orderId, 'exception' => $e::class, 'message' => $e->getMessage()],
            ]);
        }
    }
}

/**
 * Hook: get_order_info — attach the order's FGO invoice summary as
 * $order['fgo_invoice'] for the admin order-details panel
 * (hooks/orders/details.post.tpl).
 *
 * fn_get_order_info() serves the storefront, the REST API, e-mails and
 * every add-on too, so only in fn_fgo_invoicing_shows_invoice_data()'s
 * context, and only the summary columns (OrderInvoiceColumn::summary):
 * the stored request / response payloads never leave the FGO pages. One
 * indexed read; nothing may escape, the order page must render without it.
 *
 * @param array<string, mixed> $order
 * @param array<string, mixed> $additional_data
 */
function fn_fgo_invoicing_get_order_info(&$order, $additional_data = []): void
{
    if (!fn_fgo_invoicing_shows_invoice_data()) {
        return;
    }
    $orderId = TypeCoerce::toInt($order['order_id'] ?? 0);
    if ($orderId <= 0) {
        return;
    }
    try {
        $row = Container::getInstance()->repository()->findByOrderIds([$orderId])[$orderId] ?? null;
    } catch (\Throwable $e) {
        if (function_exists('fn_log_event')) {
            fn_log_event('fgo_invoicing', 'runtime', [
                'message' => '[error] order-details-panel',
                'context' => ['order_id' => $orderId, 'exception' => $e::class, 'message' => $e->getMessage()],
            ]);
        }

        return;
    }
    if ($row !== null) {
        $order['fgo_invoice'] = OrderInvoiceColumn::summary($row);
    }
}

/**
 * Hook: get_orders_post — the FGO column of the admin orders list.
 *
 * CS-Cart 4.20 (fn.cart.php, fn_get_orders()) fires
 *
 *     fn_set_hook('get_orders_post', $params, $orders);
 *
 * right after the SELECT, for EVERY fn_get_orders() caller, storefront and
 * REST API included: hence fn_fgo_invoicing_shows_invoice_data() (admin
 * panel, no API, no restricted admin, no selected storefront; the column
 * templates hide the column on the same terms) and one query for the whole
 * page (OrderInvoiceColumn / InvoiceRepository::findByOrderIds).
 *
 * $orders is the only argument touched, and it gets a default like every
 * parameter after the first (see fn_fgo_invoicing_change_order_status()).
 * Nothing may escape: a missing ?:fgo_invoices table must not take the
 * orders list down with it. The cell then shows "—" (no fgo_invoice key)
 * instead of a wrong "Not invoiced".
 *
 * @param array<string, mixed> $params
 * @param mixed $orders fn_get_orders() rows; checked, as hook arguments arrive untyped
 */
function fn_fgo_invoicing_get_orders_post($params, &$orders = []): void
{
    if (!fn_fgo_invoicing_shows_invoice_data()) {
        return;
    }
    if (!is_array($orders) || $orders === []) {
        return;
    }
    try {
        $orders = OrderInvoiceColumn::attach($orders, Container::getInstance()->repository());
    } catch (\Throwable $e) {
        if (function_exists('fn_log_event')) {
            fn_log_event('fgo_invoicing', 'runtime', [
                'message' => '[error] orders-list-column',
                'context' => ['exception' => $e::class, 'message' => $e->getMessage()],
            ]);
        }
    }
}
