<?php

declare(strict_types=1);

/**
 * Sphinx Booking Controller — Update Booking Mode
 *
 * Persists edited tourist names for a cart-stage booking (posted by the
 * edit_booking form). Ownership-checked; touches ONLY guest data on the
 * booking row and the cart extras — the offer, price and dates are never
 * re-verified or changed here.
 */

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\TravelCore\Helpers\RequestCoerce;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Tygh;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    return [CONTROLLER_STATUS_REDIRECT, 'checkout.cart'];
}

$booking_id = RequestCoerce::int($_REQUEST, 'booking_id');
$cart_id = RequestCoerce::string($_REQUEST, 'cart_id');

$session = Tygh::$app['session'];
$auth = (is_array($session) || $session instanceof \ArrayAccess) ? TypeCoerce::toStringMap($session['auth'] ?? []) : [];
$current_user_id = TypeCoerce::toInt($auth['user_id'] ?? 0);

$repo = Container::getBookingRepository();
$booking_record = $booking_id > 0
    ? $repo->findByIdWithOwnership($booking_id, $current_user_id, TypeCoerce::toString(session_id()))
    : null;
if ($booking_record === null) {
    fn_set_notification('E', __('error'), __('sphinx_holidays.invalid_offer', ['[default]' => 'Invalid booking.']));
    return [CONTROLLER_STATUS_REDIRECT, 'checkout.cart'];
}

// Same guest parsing + validation as add_to_cart (shared GuestDataService
// under the hood: names required, child ages, holder detection).
$cartService = Container::getCartService();
$parsed_guests = $cartService->parseGuests(
    RequestCoerce::stringMap($_REQUEST, 'guests'),
    TypeCoerce::toString($booking_record['check_in'] ?? ''),
);
if ($parsed_guests === false) {
    fn_set_notification('E', __('error'), __('travel_core.guest_details_invalid', ['[default]' => 'Please fill in all guest names.']));
    return [CONTROLLER_STATUS_REDIRECT, 'sphinx_booking.edit_booking?' . http_build_query([
        'booking_id' => $booking_id,
        'cart_id' => $cart_id,
    ])];
}

$guests_json = json_encode($parsed_guests['guests_data'] ?? [], JSON_UNESCAPED_UNICODE);
$holder_name = TypeCoerce::toString($parsed_guests['holder_name'] ?? '');
$guest_list = TypeCoerce::toString($parsed_guests['guest_list'] ?? '');

// 1. Booking row — guest fields only.
$repo->update($booking_id, [
    'guest_name' => $guest_list,
    'holder_name' => $holder_name,
    'guests_data' => $guests_json !== false ? $guests_json : '[]',
]);

// 2. Cart extras — so the cart card + order pipeline show the new names.
// Shared travel_core refresh: integrity-checked against the booking id, with
// the rebuilt-cart fallback scan when the cart_id hash went stale.
fn_travel_core_update_cart_guest_extras($cart_id, $booking_id, 'travel_booking_id', [
    'guest_names' => $guest_list,
    'holder_name' => $holder_name,
    'guests_data' => $guests_json !== false ? $guests_json : '[]',
]);

fn_set_notification('N', __('notice'), __('sphinx_holidays.booking_updated', ['[default]' => 'Booking details updated.']));

// Back where the customer started: checkout when the cart is skipped.
return [CONTROLLER_STATUS_REDIRECT, fn_travel_core_after_add_to_cart_url('sphinx_holidays')];
