<?php
declare(strict_types=1);
/**
 * Travel Core - Order Hook Functions
 *
 * Provider-agnostic order hooks for travel bookings.
 * Each provider handles its own booking submission via its own place_order_post hook.
 * This file handles shared post-order enrichment (e.g., attaching booking display data).
 *
 * @package TravelCore
 * @since 1.0.0
 */

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\BalanceService;
use Tygh\Addons\TravelCore\Services\DateHelper;
use Tygh\Addons\TravelCore\Services\MoneyFormatter;
use Tygh\Addons\TravelCore\Services\DepositCartLine;
use Tygh\Addons\TravelCore\Services\GuestDataService;

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

/**
 * Hook: After getting order info — format travel booking data for display.
 *
 * Enriches order products with formatted dates and guest display names.
 * Provider-specific enrichment (terms, hotel locations) remains in provider hooks.
 *
 * @param array<string, mixed> $order
 * @param array<string, mixed> $additional_data
 */
function fn_travel_core_get_order_info(&$order, $additional_data): void
{
    if (empty($order['products']) || !is_array($order['products'])) {
        return;
    }

    $date_format = \Tygh\Registry::get('settings.Appearance.date_format') ?: '%d %b %Y';

    foreach ($order['products'] as &$product) {
        if (!is_array($product)) {
            continue;
        }
        // Support both new and legacy booking flags
        $extra = TypeCoerce::toStringMap($product['extra'] ?? null);
        if (empty($extra['travel_booking'])) {
            continue;
        }

        $check_in  = TypeCoerce::toString($extra['check_in']  ?? '');
        $check_out = TypeCoerce::toString($extra['check_out'] ?? '');

        // Formatted dates
        $ci_ts = !empty($check_in)  ? strtotime($check_in)  : false;
        $co_ts = !empty($check_out) ? strtotime($check_out) : false;
        if ($ci_ts !== false) {
            $extra['check_in_formatted']  = fn_date_format($ci_ts, $date_format);
        }
        if ($co_ts !== false) {
            $extra['check_out_formatted'] = fn_date_format($co_ts, $date_format);
        }

        // Format guests_data for display
        $guests_data = $extra['guests_data'] ?? null;
        if (!empty($guests_data)) {
            $holder_name = TypeCoerce::toString($extra['holder_name'] ?? '');
            $formatted = GuestDataService::formatGuestsForOrderDisplay($guests_data, $holder_name);
            if (!empty($formatted)) {
                $extra['guests_data'] = $formatted;
            }
        }

        $product['extra'] = $extra;
    }
    unset($product);

    // Paid with a deposit: what is still owed, with its pay link while open
    // (order details, emails, admin — components/order_deposit_details.tpl).
    if (fn_travel_core_order_has_deposit($order)) {
        $order['travel_balances'] = (new BalanceService())->forOrder(TypeCoerce::toInt($order['order_id'] ?? 0));
        // The full order total beside what was charged (the order total) —
        // formatted here for the order emails, Twig snippet and Smarty alike.
        $totals = DepositCartLine::totals(is_array($order['products'] ?? null) ? $order['products'] : [], TypeCoerce::toFloat($order['total'] ?? 0));
        if ($totals !== []) {
            $money = MoneyFormatter::forStore();
            $order['travel_deposit_totals'] = $totals + [
                'total_formatted' => $money->format($totals['total']),
                'now_formatted' => $money->format($totals['now']),
                'balance_formatted' => $money->format($totals['balance']),
                'balance_due_formatted' => DateHelper::formatStoreDate($totals['balance_due']),
            ];
        }
    }
}

/**
 * Whether any line of an order (or cart) was paid with a deposit, or pays a
 * balance — the only orders the balance hooks need to look at.
 *
 * @param array<string, mixed> $order
 */
function fn_travel_core_order_has_deposit(array $order): bool
{
    $products = is_array($order['products'] ?? null) ? $order['products'] : [];
    foreach ($products as $item) {
        $extra = is_array($item) && is_array($item['extra'] ?? null) ? $item['extra'] : [];
        if (!empty($extra[DepositCartLine::EXTRA_KEY]) || !empty($extra[BalanceService::EXTRA_BALANCE_ID])) {
            return true;
        }
    }

    return false;
}

/**
 * Hook: place_order_post — record the balance of a deposit order, or link a
 * balance order to the balance it pays (BalanceService).
 *
 * @param int|string $order_id
 * @param string $action
 * @param string $order_status
 * @param array<string, mixed> $cart
 * @param array<string, mixed> $auth
 */
function fn_travel_core_place_order_post(&$order_id, &$action = '', &$order_status = '', &$cart = [], &$auth = []): void
{
    $oid = TypeCoerce::toInt($order_id);
    if ($oid <= 0 || !fn_travel_core_order_has_deposit($cart)) {
        return;
    }
    try {
        $info = fn_get_order_info($oid);
        if (is_array($info)) {
            (new BalanceService())->onOrderPlaced($oid, TypeCoerce::toStringMap($info));
        }
    } catch (\Throwable $e) {
        // Never break checkout over the balance record: log for the admin.
        fn_log_event('general', 'runtime', ['message' => "[TravelBalance] order {$oid}: " . $e->getMessage()]);
    }
}

/**
 * Hook: change_order_status — a paid balance order settles its balance; a
 * cancelled or declined deposit order cancels the open balance.
 *
 * Every parameter past $order_info has a default: the core's arity differs
 * between CS-Cart builds (see fgo_invoicing's hook).
 *
 * @param string $status_to
 * @param string $status_from
 * @param array<string, mixed> $order_info
 * @param bool $force_notification
 * @param array<string, mixed> $order_statuses
 * @param bool $place_order
 * @param string $reason
 */
function fn_travel_core_change_order_status(
    &$status_to,
    &$status_from,
    &$order_info,
    &$force_notification = false,
    &$order_statuses = [],
    &$place_order = false,
    &$reason = '',
): void {
    if (!fn_travel_core_order_has_deposit($order_info)) {
        return;
    }
    try {
        (new BalanceService())->onStatusChanged(TypeCoerce::toString($status_to), TypeCoerce::toStringMap($order_info));
    } catch (\Throwable $e) {
        fn_log_event('general', 'runtime', ['message' => '[TravelBalance] status change: ' . $e->getMessage()]);
    }
}
