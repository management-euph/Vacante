<?php

declare(strict_types=1);
/**
 * eurosite_booking.add_to_cart — persist the booking + put it in the cart.
 *
 * Every price/occupancy fact comes from the server-side offer snapshot
 * (offer_key), never from the POST. Guests are validated locally (names,
 * child DOBs) because eurosite's pax contract (TGender B/F/C) extends the
 * shared travel_core field set; the POST layout stays byte-compatible with
 * the shared guest cards.
 *
 * The cart line rides on the hidden EUROSITE-BOOKING carrier product
 * (eurosite hotels have no per-hotel CS-Cart products — search is
 * destination-driven), with stored_price=Y and the travel_booking extra the
 * shared cart/order hooks key on.
 */

use Tygh\Addons\Eurosite\Services\Container;
use Tygh\Addons\Eurosite\Services\OfferContextStore;
use Tygh\Addons\TravelCore\Helpers\RequestCoerce;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\TravelConstants;
use Tygh\Tygh;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    return [CONTROLLER_STATUS_REDIRECT, 'eurosite_booking.search'];
}

$offerKey = (string) preg_replace('/[^a-f0-9]/', '', strtolower(RequestCoerce::string($_REQUEST, 'offer_key')));
$snapshot = $offerKey !== '' ? OfferContextStore::get($offerKey) : null;
if ($snapshot === null) {
    fn_set_notification('W', __('warning'), __('eurosite.offer_expired', [
        '[default]' => 'The selected offer has expired — please search again.',
    ]));

    return [CONTROLLER_STATUS_REDIRECT, 'eurosite_booking.search'];
}
// Stop sale is never bookable (spec). OfferContextStore gives such offers no
// key, so this only fires on a snapshot stored before that rule existed.
if (TypeCoerce::toString($snapshot['availability_code'] ?? '') === 'ST') {
    fn_set_notification('W', __('warning'), __('eurosite.offer_stop_sale', [
        '[default]' => 'This offer is on stop sale and cannot be booked — please choose another.',
    ]));

    return [CONTROLLER_STATUS_REDIRECT, 'eurosite_booking.search'];
}

// ── Guests ──
$rawGuests = is_array($_POST['guests'] ?? null) ? $_POST['guests'] : [];
$guests = [];
$holderName = '';
$invalid = false;
foreach ($rawGuests as $key => $guest) {
    if (!is_array($guest)) {
        continue;
    }
    $first = trim(TypeCoerce::toString($guest['first_name'] ?? ''));
    $last = trim(TypeCoerce::toString($guest['last_name'] ?? ''));
    $type = TypeCoerce::toString($guest['type'] ?? 'adult') === 'child' ? 'child' : 'adult';
    $dob = trim(TypeCoerce::toString($guest['dob'] ?? ''));
    if ($first === '' || $last === '' || ($type === 'child' && $dob === '')) {
        $invalid = true;
        break;
    }
    $gender = strtoupper(trim(TypeCoerce::toString($guest['gender'] ?? '')));
    $entry = [
        'type'       => $type,
        'first_name' => $first,
        'last_name'  => $last,
        'name'       => $last . ' / ' . $first,
        'gender'     => $type === 'child' ? 'C' : (in_array($gender, ['B', 'F'], true) ? $gender : 'B'),
        'dob'        => $dob,
        'room'       => 1,
    ];
    if ($type === 'child') {
        $entry['age'] = TypeCoerce::toInt($guest['age'] ?? 0);
    }
    if (!empty($guest['is_holder']) || $holderName === '') {
        $holderName = $entry['name'];
    }
    $guests[] = $entry;
}

$expectedGuests = TypeCoerce::toInt($snapshot['adults'] ?? 2) + count((array) ($snapshot['children_ages'] ?? []));
if ($invalid || count($guests) !== $expectedGuests) {
    fn_set_notification('E', __('error'), __('eurosite.guests_invalid', [
        '[default]' => 'Please fill in every guest (children need a date of birth).',
    ]));

    return [CONTROLLER_STATUS_REDIRECT, 'eurosite_booking.booking_form?offer_key=' . $offerKey];
}

$guestEmail = trim(RequestCoerce::string($_REQUEST, 'guest_email'));
$guestPhone = trim(RequestCoerce::string($_REQUEST, 'guest_phone'));
if (filter_var($guestEmail, FILTER_VALIDATE_EMAIL) === false || $guestPhone === '') {
    fn_set_notification('E', __('error'), __('eurosite.contact_invalid', [
        '[default]' => 'Please provide a valid e-mail address and phone number.',
    ]));

    return [CONTROLLER_STATUS_REDIRECT, 'eurosite_booking.booking_form?offer_key=' . $offerKey];
}

// ── Carrier product ──
$carrierId = TypeCoerce::toInt(db_get_field(
    "SELECT product_id FROM ?:products WHERE product_code = 'EUROSITE-BOOKING'",
));
if ($carrierId <= 0) {
    fn_set_notification('E', __('error'), __('eurosite.carrier_missing', [
        '[default]' => 'Booking checkout is not fully configured yet — please contact us to finish this reservation.',
    ]));
    fn_log_event('general', 'runtime', ['message' => 'Eurosite add_to_cart: EUROSITE-BOOKING carrier product missing']);

    return [CONTROLLER_STATUS_REDIRECT, 'eurosite_booking.booking_form?offer_key=' . $offerKey];
}

// ── Persist the booking (mirror dual-write inside the repository) ──
$checkIn = TypeCoerce::toString($snapshot['check_in']);
$checkOut = TypeCoerce::toString($snapshot['check_out']);
$nights = max(0, (int) round((strtotime($checkOut) - strtotime($checkIn)) / 86400));
$rooms = TypeCoerce::toRowList($snapshot['rooms'] ?? null);
$meals = TypeCoerce::toRowList($snapshot['meals'] ?? null);
$childrenAges = TypeCoerce::toIntList($snapshot['children_ages'] ?? []);
$sessionId = function_exists('session_id') ? (string) session_id() : '';
$session = Tygh::$app['session'];
$auth = (is_array($session) || $session instanceof \ArrayAccess) ? TypeCoerce::toStringMap($session['auth'] ?? []) : [];
$userId = TypeCoerce::toInt($auth['user_id'] ?? 0);
$clientRef = 'ES' . strtoupper(substr(md5(uniqid((string) mt_rand(), true)), 0, 10));

$bookingId = Container::bookings()->create([
    'order_id'      => 0,
    'user_id'       => $userId,
    'session_id'    => $sessionId,
    'client_ref'    => $clientRef,
    'product_code'  => TypeCoerce::toString($snapshot['product_code']),
    'hotel_name'    => TypeCoerce::toString($snapshot['product_name']),
    'country_code'  => TypeCoerce::toString($snapshot['country_code']),
    'city_code'     => TypeCoerce::toString($snapshot['city_code']),
    'variant_id'    => TypeCoerce::toString($snapshot['variant_id']),
    'series_id'     => TypeCoerce::toString($snapshot['series_id']),
    'check_in'      => $checkIn,
    'check_out'     => $checkOut,
    'nights'        => $nights,
    'adults'        => TypeCoerce::toInt($snapshot['adults'] ?? 2),
    'children'      => count($childrenAges),
    'children_ages' => implode(',', $childrenAges),
    'num_rooms'     => 1,
    'rooms_data'    => (string) json_encode($rooms, JSON_UNESCAPED_UNICODE),
    'room_type'     => $rooms !== [] ? TypeCoerce::toString($rooms[0]['name'] ?? '') : '',
    'board_id'      => $meals !== [] ? TypeCoerce::toString($meals[0]['code'] ?? '') : '',
    'meal_name'     => $meals !== [] ? TypeCoerce::toString($meals[0]['name'] ?? '') : '',
    'guest_name'    => $holderName,
    'guest_email'   => $guestEmail,
    'guest_phone'   => $guestPhone,
    'currency'      => TypeCoerce::toString($snapshot['currency']),
    'total_price'   => TypeCoerce::toFloat($snapshot['price']),
    'guests_json'   => (string) json_encode($guests, JSON_UNESCAPED_UNICODE),
    'status'        => TravelConstants::STATUS_PENDING,
]);

// ── Cart line (store primary currency; stored_price honours it) ──
$eurPrice = TypeCoerce::toFloat($snapshot['price']);
$coefficient = TypeCoerce::toFloat(db_get_field(
    'SELECT coefficient FROM ?:currencies WHERE currency_code = ?s',
    TypeCoerce::toString($snapshot['currency']),
));
$cartPrice = $coefficient > 0 ? round($eurPrice * $coefficient, 2) : $eurPrice;

/** @var array<string, mixed> $session_ref */
$session_ref = &Tygh::$app['session'];
$cart = &$session_ref['cart'];
if (!is_array($cart)) {
    $cart = [];
}
if (!isset($cart['products']) || !is_array($cart['products'])) {
    $cart['products'] = [];
}
$cartId = (int) hexdec(substr(md5('eurosite' . $bookingId), 0, 7));
$cart['products'][$cartId] = [
    'product_id'   => $carrierId,
    'amount'       => 1,
    'price'        => $cartPrice,
    'stored_price' => 'Y',
    'extra'        => [
        'travel_booking'      => true,
        'eurosite_booking_id' => $bookingId,
        'booking_id'          => $bookingId,
        'hotel_name'          => TypeCoerce::toString($snapshot['product_name']),
        'check_in'            => $checkIn,
        'check_out'           => $checkOut,
        'nights'              => $nights,
        'rooms_data'          => (string) json_encode($rooms, JSON_UNESCAPED_UNICODE),
        'guests_data'         => (string) json_encode($guests, JSON_UNESCAPED_UNICODE),
        'holder_name'         => $holderName,
    ],
];

fn_calculate_cart_content($cart, $auth);
fn_save_cart_content($cart, $userId);

fn_set_notification('N', __('notice'), __('eurosite.added_to_cart', [
    '[default]' => 'Your stay was added to the cart — complete checkout to confirm the reservation.',
]));

return [CONTROLLER_STATUS_REDIRECT, 'checkout.cart'];
