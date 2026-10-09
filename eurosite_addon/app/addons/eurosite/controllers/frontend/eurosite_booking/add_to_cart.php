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
 * The cart line goes on the hotel's own product, chosen by product_id
 * (BookingCartProduct): the product page the guest booked from, else the
 * hotel's linked product. A hotel that is not a store product cannot be
 * booked. stored_price=Y keeps the booking's price; the shared cart/order
 * hooks key on the travel_booking extra, not the product.
 *
 * A refusal sends the guest back to the hotel's product page with their stay
 * (BookingReturnUrl), where the search runs again — never to an empty page.
 */

use Tygh\Addons\Eurosite\Services\BookingCartProduct;
use Tygh\Addons\Eurosite\Services\BookingReturnUrl;
use Tygh\Addons\Eurosite\Services\ConfigProvider;
use Tygh\Addons\Eurosite\Services\Container;
use Tygh\Addons\Eurosite\Services\OfferContextStore;
use Tygh\Addons\Eurosite\Services\RoomOccupancy;
use Tygh\Addons\TravelCore\Helpers\RequestCoerce;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\DateHelper;
use Tygh\Addons\TravelCore\TravelConstants;
use Tygh\Tygh;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    return [CONTROLLER_STATUS_REDIRECT, BookingReturnUrl::forProduct(BookingReturnUrl::requestedProductId($_REQUEST))];
}

$offerKey = (string) preg_replace('/[^a-f0-9]/', '', strtolower(RequestCoerce::string($_REQUEST, 'offer_key')));
$snapshot = $offerKey !== '' ? OfferContextStore::get($offerKey) : null;
if ($snapshot === null) {
    fn_set_notification('W', __('warning'), __('eurosite.offer_expired', [
        '[default]' => 'The selected offer has expired — please search again.',
    ]));

    // The product page restores the guest's last search by itself.
    return [CONTROLLER_STATUS_REDIRECT, BookingReturnUrl::forProduct(BookingReturnUrl::requestedProductId($_REQUEST))];
}

// ── The product the cart line goes on: the hotel's own, by product_id ──
$cartProductId = BookingCartProduct::forSnapshot(
    $snapshot,
    Container::hotels()->findByProductCode(TypeCoerce::toString($snapshot['product_code'])),
)['product_id'];
if ($cartProductId <= 0) {
    fn_set_notification('E', __('error'), __('eurosite.hotel_not_bookable', [
        '[default]' => 'This hotel cannot be booked online yet — please contact us to book it.',
    ]));
    fn_log_event('general', 'runtime', ['message' => sprintf(
        'Eurosite add_to_cart: hotel %s has no store product that can be bought',
        TypeCoerce::toString($snapshot['product_code']),
    )]);

    // No product page to return to: it is disabled or gone.
    return [CONTROLLER_STATUS_REDIRECT, BookingReturnUrl::HOME];
}
// Stop sale is never bookable (spec). OfferContextStore gives such offers no
// key, so this only fires on a snapshot stored before that rule existed.
if (TypeCoerce::toString($snapshot['availability_code'] ?? '') === 'ST') {
    fn_set_notification('W', __('warning'), __('eurosite.offer_stop_sale', [
        '[default]' => 'This offer is on stop sale and cannot be booked — please choose another.',
    ]));

    return [CONTROLLER_STATUS_REDIRECT, BookingReturnUrl::forSnapshot($snapshot, $cartProductId)];
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
    // The shared guest cards mask DOB as DD/MM/YYYY; the API wants Y-m-d.
    $rawDob = trim(TypeCoerce::toString($guest['dob'] ?? ''));
    $dob = $rawDob !== '' ? (DateHelper::parseDate($rawDob) ?? '') : '';
    if ($first === '' || $last === '' || ($rawDob !== '' && $dob === '') || ($type === 'child' && $dob === '')) {
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
        'room'       => max(1, TypeCoerce::toInt($guest['room'] ?? 1)),
    ];
    if ($type === 'child') {
        $entry['age'] = TypeCoerce::toInt($guest['age'] ?? 0);
    }
    if (!empty($guest['is_holder']) || $holderName === '') {
        $holderName = $entry['name'];
    }
    $guests[] = $entry;
}

// Every room must hold exactly the adults and children it was priced for.
$occupancy = RoomOccupancy::fromSnapshot($snapshot);
$perRoom = [];
foreach ($guests as $g) {
    $perRoom[$g['room']][$g['type']] = ($perRoom[$g['room']][$g['type']] ?? 0) + 1;
}
$roomsMatch = count($perRoom) === count($occupancy);
foreach ($occupancy as $i => $room) {
    $got = $perRoom[$i + 1] ?? [];
    if (($got['adult'] ?? 0) !== $room['adults'] || ($got['child'] ?? 0) !== count($room['children_ages'])) {
        $roomsMatch = false;
    }
}
if ($invalid || !$roomsMatch) {
    fn_set_notification('E', __('error'), __('eurosite.guests_invalid', [
        '[default]' => 'Please fill in every guest (children need a date of birth).',
    ]));

    return [CONTROLLER_STATUS_REDIRECT, 'eurosite_booking.booking_form?offer_key=' . $offerKey];
}

// Price guard (as sphinx): the offer is priced for the searched child ages,
// so a DOB implying another age at check-in re-runs the search with the
// corrected ages instead of booking a wrong-price stay.
// Calendar dates compared in one timezone, so a birthday on the check-in
// day counts (no DST hour between two local midnights).
$checkInDate = DateHelper::parseDate(TypeCoerce::toString($snapshot['check_in']));
$ageMismatch = null;
$correctedRooms = array_map(
    static fn (array $room): array => ['adults' => $room['adults'], 'children_ages' => []],
    $occupancy,
);
foreach ($guests as $g) {
    if ($g['type'] !== 'child') {
        continue;
    }
    $atCheckIn = $checkInDate !== null
        ? (new \DateTimeImmutable($g['dob']))->diff(new \DateTimeImmutable($checkInDate))->y
        : TypeCoerce::toInt($g['age'] ?? 0);
    if (isset($correctedRooms[$g['room'] - 1])) {
        $correctedRooms[$g['room'] - 1]['children_ages'][] = $atCheckIn;
    }
    if ($ageMismatch === null && $atCheckIn !== TypeCoerce::toInt($g['age'] ?? 0)) {
        $ageMismatch = ['name' => $g['name'], 'declared' => TypeCoerce::toInt($g['age'] ?? 0), 'actual' => $atCheckIn];
    }
}
if ($ageMismatch !== null) {
    fn_set_notification('E', __('error'), __('travel_core.child_age_mismatch', [
        '[guest]' => $ageMismatch['name'],
        '[declared]' => $ageMismatch['declared'],
        '[actual]' => $ageMismatch['actual'],
        '[default]' => 'The child [guest] will be [actual] years old at check-in, but the offer was priced for age [declared]. The search was re-run with the correct ages — please choose an offer again.',
    ]));

    // The product page re-runs the search with the corrected ages.
    return [CONTROLLER_STATUS_REDIRECT, BookingReturnUrl::forSnapshot($snapshot, $cartProductId, $correctedRooms)];
}

// Contact comes from CS-Cart checkout (as for sphinx/novoton): the booking
// form no longer asks for it; place_order_post backfills it from the order.
$guestEmail = trim(RequestCoerce::string($_REQUEST, 'guest_email'));
$guestPhone = trim(RequestCoerce::string($_REQUEST, 'guest_phone'));
if (filter_var($guestEmail, FILTER_VALIDATE_EMAIL) === false) {
    $guestEmail = '';
}

// ── Persist the booking (mirror dual-write inside the repository) ──
$checkIn = TypeCoerce::toString($snapshot['check_in']);
$checkOut = TypeCoerce::toString($snapshot['check_out']);
$nights = max(0, (int) round((strtotime($checkOut) - strtotime($checkIn)) / 86400));
$rooms = TypeCoerce::toRowList($snapshot['rooms'] ?? null);
// The rooms with their guests (room_name, code, adults, children,
// childrenAges): the shared cart card reads them, and the booking submission
// sends each room's code and travellers back to Eurosite.
$roomsData = RoomOccupancy::displayRooms($snapshot);
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
    'num_rooms'     => count($roomsData),
    'rooms_data'    => (string) json_encode($roomsData, JSON_UNESCAPED_UNICODE),
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
    'product_id'   => $cartProductId,
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
        'rooms_data'          => (string) json_encode($roomsData, JSON_UNESCAPED_UNICODE),
        'num_rooms'           => count($roomsData),
        'room_name'           => $rooms !== [] ? TypeCoerce::toString($rooms[0]['name'] ?? '') : '',
        'adults'              => TypeCoerce::toInt($snapshot['adults'] ?? 2),
        'children_ages'       => implode(',', $childrenAges),
        'guests_data'         => (string) json_encode($guests, JSON_UNESCAPED_UNICODE),
        'holder_name'         => $holderName,
        // The payment terms shown on the booking page, kept with the line:
        // the order shows what the customer agreed to, not today's setting.
        'payment_terms'       => array_values(array_filter(
            array_map('trim', explode("\n", ConfigProvider::getPaymentTermsText())),
            static fn (string $line): bool => $line !== '',
        )),
    ],
];

fn_calculate_cart_content($cart, $auth);

// CS-Cart drops a line it won't sell (the product's category, user group,
// storefront or vendor): the booking must not wait as if it were in the cart.
if (!isset($cart['products'][$cartId])) {
    Container::bookings()->update($bookingId, [
        'status'       => TravelConstants::STATUS_FAILED,
        'api_response' => 'CART_DROPPED: product ' . $cartProductId,
    ]);
    fn_log_event('general', 'runtime', ['message' => sprintf(
        'Eurosite add_to_cart: the cart dropped booking %d (hotel %s, product %d)',
        $bookingId,
        TypeCoerce::toString($snapshot['product_code']),
        $cartProductId,
    )]);
    fn_save_cart_content($cart, $userId);
    fn_set_notification('E', __('error'), __('eurosite.cart_line_dropped', [
        '[default]' => 'This stay could not be added to the cart — please try again or contact us.',
    ]));

    return [CONTROLLER_STATUS_REDIRECT, BookingReturnUrl::forSnapshot($snapshot, $cartProductId)];
}
fn_save_cart_content($cart, $userId);

fn_set_notification('N', __('notice'), __('eurosite.added_to_cart', [
    '[default]' => 'Your stay was added to the cart — complete checkout to confirm the reservation.',
]));

// Straight to checkout when Settings -> Travel Core -> "Skip the cart page
// for" ticks Eurosite; else the cart, as before.
return [CONTROLLER_STATUS_REDIRECT, fn_travel_core_after_add_to_cart_url('eurosite')];
