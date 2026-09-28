<?php

declare(strict_types=1);
/**
 * Travel Core - Pay the balance of a deposit booking.
 *
 * travel_balance.pay?balance_id=…&key=… (the link on the order page and in
 * the reminder emails; key = BalanceService HMAC, so no account is needed
 * and no other balance can be reached).
 *
 * Puts ONE cart line for the balance (the hotel's product, stored price =
 * the balance, extra.travel_balance_id) and sends the guest to the normal
 * checkout, where they pick how to pay — Netopia card, bank transfer (its
 * instructions come from that CS-Cart payment method), … Placing that order
 * links it to the balance; its P/C status marks the balance paid
 * (BalanceService). The line carries no provider booking flags, so no
 * provider books or re-prices anything for it.
 *
 * @package TravelCore
 */

use Tygh\Addons\TravelCore\Helpers\RequestCoerce;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Repository\BalanceRepository;
use Tygh\Addons\TravelCore\Services\BalanceService;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

if ($mode !== 'pay') {
    return [CONTROLLER_STATUS_NO_PAGE];
}

$balance_id = RequestCoerce::int($_REQUEST, 'balance_id');
$key = RequestCoerce::string($_REQUEST, 'key');
$repo = new BalanceRepository();
$service = new BalanceService($repo);
$balance = $balance_id > 0 ? $repo->find($balance_id) : null;
$order_id = TypeCoerce::toInt($balance['order_id'] ?? 0);

if ($balance === null || !$service->keyMatches($balance_id, $order_id, $key)) {
    fn_set_notification('E', __('error'), __('travel_core.balance_link_invalid'));
    return [CONTROLLER_STATUS_REDIRECT, 'index.index'];
}
if (TypeCoerce::toString($balance['status'] ?? '') !== BalanceRepository::STATUS_OPEN) {
    fn_set_notification('N', __('notice'), __('travel_core.balance_already_settled'));
    return [CONTROLLER_STATUS_REDIRECT, 'index.index'];
}

$order = fn_get_order_info($order_id);
$order = is_array($order) ? TypeCoerce::toStringMap($order) : [];
if ($order === [] || in_array(TypeCoerce::toString($order['status'] ?? ''), ['I', 'D', 'F', 'B'], true)) {
    fn_set_notification('E', __('error'), __('travel_core.balance_order_inactive'));
    return [CONTROLLER_STATUS_REDIRECT, 'index.index'];
}

$product_id = TypeCoerce::toInt($balance['product_id'] ?? 0);
$amount = TypeCoerce::toFloat($balance['amount'] ?? 0);
if ($product_id <= 0 || $amount <= 0) {
    fn_set_notification('E', __('error'), __('travel_core.balance_link_invalid'));
    return [CONTROLLER_STATUS_REDIRECT, 'index.index'];
}

$extra = [
    BalanceService::EXTRA_BALANCE_ID => $balance_id,
    'travel_balance' => true,
    'parent_order_id' => $order_id,
    'hotel_name' => TypeCoerce::toString($balance['hotel_name'] ?? ''),
    'check_in' => TypeCoerce::toString($balance['check_in'] ?? ''),
    'check_out' => TypeCoerce::toString($balance['check_out'] ?? ''),
    'balance_due' => TypeCoerce::toString($balance['due_date'] ?? ''),
];

// $_SESSION is the authoritative session store (travel_core SessionAccessor);
// narrowing through the references lands the arrays in the live session.
$cart = &$_SESSION['cart'];
$auth = &$_SESSION['auth'];
if (!is_array($cart)) {
    $cart = [];
}
if (!is_array($auth)) {
    $auth = [];
}
if (empty($cart)) {
    fn_clear_cart($cart);
}
$cart['products'] = is_array($cart['products'] ?? null) ? $cart['products'] : [];

// One line per balance: a second click replaces it rather than doubling it.
foreach ($cart['products'] as $existing_id => $existing) {
    $existing_extra = is_array($existing) && is_array($existing['extra'] ?? null) ? $existing['extra'] : [];
    if (TypeCoerce::toInt($existing_extra[BalanceService::EXTRA_BALANCE_ID] ?? 0) === $balance_id) {
        unset($cart['products'][$existing_id]);
    }
}
$cart_id = TypeCoerce::toString(fn_generate_cart_id($product_id, $extra));
$cart['products'][$cart_id] = [
    'product_id' => $product_id,
    'amount' => 1,
    'price' => $amount,
    'base_price' => $amount,
    'original_price' => $amount,
    'extra' => $extra,
    'stored_price' => 'Y',
];

// A guest paying from the email: prefill checkout with the deposit order's
// contact and address, so they only choose how to pay.
$user_data = is_array($cart['user_data'] ?? null) ? $cart['user_data'] : [];
if (empty($user_data['email'])) {
    foreach ($order as $field => $value) {
        if (is_scalar($value) && preg_match('/^(email|firstname|lastname|phone|company|[bs]_(firstname|lastname|address|address_2|city|county|state|country|zipcode|phone))$/', (string) $field)) {
            $user_data[$field] = $value;
        }
    }
    $cart['user_data'] = $user_data;
}

fn_calculate_cart_content($cart, $auth, 'S', true, 'F', true);
fn_save_cart_content($cart, TypeCoerce::toInt($auth['user_id'] ?? 0));

fn_set_notification('N', __('notice'), __('travel_core.balance_added_to_cart', ['[order_id]' => $order_id]));

return [CONTROLLER_STATUS_REDIRECT, 'checkout.checkout'];
