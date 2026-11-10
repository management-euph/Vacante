<?php

declare(strict_types=1);
/**
 * eurosite_booking.package_add_to_cart — keeps the package booking and puts
 * it in the cart, as the stay's add_to_cart does.
 *
 * Every price, id and traveller count comes from the server-side snapshot
 * the booking page wrote (package_key, PackageSnapshotStore), never from the
 * POST; the travellers are read the same way both Eurosite forms read them
 * (BookingGuests).
 *
 * Only an extra the booking page itself offered can be charged: what the
 * guest ticked is matched against the snapshot's list, at the price it was
 * shown at.
 *
 * The line goes on the hotel's own product, with the package's facts beside
 * the stay's, so the cart, the checkout and the order show a package and not
 * a room: which way it travels, the day it leaves and where from. The
 * booking row keeps its PackageId, which AddBookingRequest sends after the
 * order is placed (BookingSubmissionService).
 *
 * A refusal sends the guest back to the hotel's page on the same departure,
 * where the bar asks again — never to an empty page.
 */

use Tygh\Addons\Eurosite\Services\BookingCartProduct;
use Tygh\Addons\Eurosite\Services\BookingGuests;
use Tygh\Addons\Eurosite\Services\BookingReturnUrl;
use Tygh\Addons\Eurosite\Services\ConfigProvider;
use Tygh\Addons\Eurosite\Services\Container;
use Tygh\Addons\Eurosite\Services\DesignARates;
use Tygh\Addons\Eurosite\Services\PackageDepartures;
use Tygh\Addons\Eurosite\Services\PackageExtras;
use Tygh\Addons\Eurosite\Services\PackageSnapshotStore;
use Tygh\Addons\Eurosite\Services\RoomOccupancy;
use Tygh\Addons\TravelCore\Helpers\RequestCoerce;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\TravelConstants;
use Tygh\Addons\TravelCore\ViewModels\BookingSidebarFactory;
use Tygh\Tygh;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    return [CONTROLLER_STATUS_REDIRECT, BookingReturnUrl::forProduct(BookingReturnUrl::requestedProductId($_REQUEST))];
}

$packageKey = (string) preg_replace('/[^a-f0-9]/', '', strtolower(RequestCoerce::string($_REQUEST, 'package_key')));
$snapshot = $packageKey !== '' ? PackageSnapshotStore::get($packageKey) : null;
if ($snapshot === null) {
    fn_set_notification('W', __('warning'), __('eurosite.offer_expired', [
        '[default]' => 'The selected offer has expired — please search again.',
    ]));

    return [CONTROLLER_STATUS_REDIRECT, BookingReturnUrl::forProduct(BookingReturnUrl::requestedProductId($_REQUEST))];
}

$productCode = TypeCoerce::toString($snapshot['product_code']);
$hotelRow = Container::hotels()->findByProductCode($productCode);
$cartProductId = BookingCartProduct::forSnapshot($snapshot, $hotelRow)['product_id'];
if ($cartProductId <= 0) {
    fn_set_notification('E', __('error'), __('eurosite.hotel_not_bookable', [
        '[default]' => 'This hotel cannot be booked online yet — please contact us to book it.',
    ]));

    return [CONTROLLER_STATUS_REDIRECT, BookingReturnUrl::HOME];
}

// The way back: the hotel's page on this departure.
$transport = TypeCoerce::toString($snapshot['transport']) === 'bus' ? 'bus' : 'flight';
$occupancy = RoomOccupancy::fromSnapshot($snapshot);
['adults' => $adults, 'children_ages' => $childrenAges] = RoomOccupancy::totals($occupancy);
$hotelPage = BookingSidebarFactory::changeSelectionUrl($cartProductId, [
    'transport' => $transport,
    'departure_id' => (string) PackageDepartures::departureId(TypeCoerce::toString($snapshot['dep_city_code'])),
    'departure_date' => TypeCoerce::toString($snapshot['departure_date']),
    'nights' => (string) TypeCoerce::toInt($snapshot['nights'] ?? 0),
    'adults' => (string) $adults,
    'children' => (string) count($childrenAges),
    'children_ages' => implode(',', $childrenAges),
    'rooms' => (string) count($occupancy),
]);
$backUrl = $hotelPage !== '' ? $hotelPage : BookingReturnUrl::HOME;

// Stop sale is never bookable (spec): the quote gives such an offer no
// token, so this only fires on a snapshot written before that rule.
if (TypeCoerce::toString($snapshot['availability_code'] ?? '') === 'ST') {
    fn_set_notification('W', __('warning'), __('eurosite.offer_stop_sale', [
        '[default]' => 'This offer is on stop sale and cannot be booked — please choose another.',
    ]));

    return [CONTROLLER_STATUS_REDIRECT, $backUrl];
}

// ── The travellers ──
$parsed = BookingGuests::parse($_POST['guests'] ?? null);
$guests = $parsed['guests'];
if ($parsed['invalid'] || !BookingGuests::roomsMatch($guests, $occupancy)) {
    fn_set_notification('E', __('error'), __('eurosite.guests_invalid', [
        '[default]' => 'Please fill in every guest (children need a date of birth).',
    ]));

    return [CONTROLLER_STATUS_REDIRECT, 'eurosite_booking.package_booking_form?package_key=' . $packageKey];
}
// Every adult's date of birth: the passenger list needs it.
foreach ($guests as $guest) {
    if (TypeCoerce::toString($guest['dob'] ?? '') === '') {
        fn_set_notification('E', __('error'), __('eurosite.package_dob_required'));

        return [CONTROLLER_STATUS_REDIRECT, 'eurosite_booking.package_booking_form?package_key=' . $packageKey];
    }
}

// The package is priced for the children's ages searched: a date of birth
// that gives another age at check-in sends the guest back to the bar.
$ages = BookingGuests::ageCheck($guests, TypeCoerce::toString($snapshot['check_in']), $occupancy);
if ($ages['mismatch'] !== null) {
    fn_set_notification('E', __('error'), __('travel_core.child_age_mismatch', [
        '[guest]' => $ages['mismatch']['name'],
        '[declared]' => $ages['mismatch']['declared'],
        '[actual]' => $ages['mismatch']['actual'],
    ]));

    return [CONTROLLER_STATUS_REDIRECT, $backUrl];
}

// ── The extras ── only ones this page offered, at the price it showed.
$extras = PackageExtras::chosen(TypeCoerce::toRowList($snapshot['extras'] ?? null), $_POST['services'] ?? null);
$extrasTotal = PackageExtras::total($extras);

// ── The booking row ──
$checkIn = TypeCoerce::toString($snapshot['check_in']);
$checkOut = TypeCoerce::toString($snapshot['check_out']);
$nights = max(0, (int) round(((int) strtotime($checkOut) - (int) strtotime($checkIn)) / 86400));
$rooms = TypeCoerce::toRowList($snapshot['rooms'] ?? null);
$roomsData = RoomOccupancy::displayRooms($snapshot);
$meals = TypeCoerce::toRowList($snapshot['meals'] ?? null);
$sessionId = function_exists('session_id') ? (string) session_id() : '';
$session = Tygh::$app['session'];
$auth = (is_array($session) || $session instanceof \ArrayAccess) ? TypeCoerce::toStringMap($session['auth'] ?? []) : [];
$userId = TypeCoerce::toInt($auth['user_id'] ?? 0);
$clientRef = 'ES' . strtoupper(substr(md5(uniqid((string) mt_rand(), true)), 0, 10));

$bookingId = Container::bookings()->create([
    'order_id' => 0,
    'user_id' => $userId,
    'session_id' => $sessionId,
    'client_ref' => $clientRef,
    'product_code' => $productCode,
    'hotel_name' => TypeCoerce::toString($snapshot['product_name']),
    'country_code' => TypeCoerce::toString($snapshot['country_code']),
    'city_code' => TypeCoerce::toString($snapshot['city_code']),
    'variant_id' => TypeCoerce::toString($snapshot['variant_id']),
    'series_id' => '',
    // What makes it a package, for AddBookingRequest and the order card.
    'package_id' => TypeCoerce::toString($snapshot['package_id']),
    'transport' => $transport,
    'dep_city_code' => TypeCoerce::toString($snapshot['dep_city_code']),
    'departure_date' => TypeCoerce::toString($snapshot['departure_date']),
    'check_in' => $checkIn,
    'check_out' => $checkOut,
    'nights' => $nights,
    'adults' => $adults,
    'children' => count($childrenAges),
    'children_ages' => implode(',', $childrenAges),
    'num_rooms' => count($roomsData),
    'rooms_data' => (string) json_encode($roomsData, JSON_UNESCAPED_UNICODE),
    'room_type' => $rooms !== [] ? TypeCoerce::toString($rooms[0]['name'] ?? '') : '',
    'board_id' => $meals !== [] ? TypeCoerce::toString($meals[0]['code'] ?? '') : '',
    'meal_name' => $meals !== [] ? DesignARates::mealLabel($meals[0]) : '',
    'guest_name' => $parsed['holder'],
    'guest_email' => '',
    'guest_phone' => '',
    'currency' => TypeCoerce::toString($snapshot['currency']),
    'total_price' => round(TypeCoerce::toFloat($snapshot['price']) + $extrasTotal, 2),
    'guests_json' => (string) json_encode($guests, JSON_UNESCAPED_UNICODE),
    // What the guest ticked: sent to Eurosite as SuppServices with the booking.
    'extras_json' => (string) json_encode($extras, JSON_UNESCAPED_UNICODE),
    'status' => TravelConstants::STATUS_PENDING,
]);

// ── The cart line (the store's primary currency; stored_price honours it) ──
$coefficient = TypeCoerce::toFloat(db_get_field(
    'SELECT coefficient FROM ?:currencies WHERE currency_code = ?s',
    TypeCoerce::toString($snapshot['currency']),
));
$price = round(TypeCoerce::toFloat($snapshot['price']) + $extrasTotal, 2);
$cartPrice = $coefficient > 0 ? round($price * $coefficient, 2) : $price;

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
    'product_id' => $cartProductId,
    'amount' => 1,
    'price' => $cartPrice,
    'stored_price' => 'Y',
    'extra' => [
        'travel_booking' => true,
        'eurosite_booking_id' => $bookingId,
        'booking_id' => $bookingId,
        // A package, not a stay: the shared cards say so (OrderBookingCardFactory).
        'booking_type' => 'package',
        'transport' => $transport,
        'departure_date' => TypeCoerce::toString($snapshot['departure_date']),
        'hotel_name' => TypeCoerce::toString($snapshot['product_name']),
        'check_in' => $checkIn,
        'check_out' => $checkOut,
        'nights' => $nights,
        'rooms_data' => (string) json_encode($roomsData, JSON_UNESCAPED_UNICODE),
        'num_rooms' => count($roomsData),
        'room_name' => $rooms !== [] ? TypeCoerce::toString($rooms[0]['name'] ?? '') : '',
        'board_name' => $meals !== [] ? DesignARates::mealLabel($meals[0]) : '',
        'adults' => $adults,
        'children_ages' => implode(',', $childrenAges),
        'guests_data' => (string) json_encode($guests, JSON_UNESCAPED_UNICODE),
        'holder_name' => $parsed['holder'],
        // The extras on the cart, the checkout and the order.
        'services' => array_map(
            static fn (array $extra): array => ['name' => $extra['title'], 'price' => $extra['price'], 'currency' => $extra['currency']],
            $extras,
        ),
        // The payment terms the page showed, kept with the line.
        'payment_terms' => BookingSidebarFactory::termLines(ConfigProvider::getPaymentTermsText()),
    ],
];

fn_calculate_cart_content($cart, $auth);

// CS-Cart drops a line it will not sell: the booking must not wait as if it
// were in the cart.
if (!isset($cart['products'][$cartId])) {
    Container::bookings()->update($bookingId, [
        'status' => TravelConstants::STATUS_FAILED,
        'api_response' => 'CART_DROPPED: product ' . $cartProductId,
    ]);
    fn_log_event('general', 'runtime', ['message' => sprintf(
        'Eurosite package_add_to_cart: the cart dropped booking %d (hotel %s, product %d)',
        $bookingId,
        $productCode,
        $cartProductId,
    )]);
    fn_save_cart_content($cart, $userId);
    fn_set_notification('E', __('error'), __('eurosite.cart_line_dropped', [
        '[default]' => 'This stay could not be added to the cart — please try again or contact us.',
    ]));

    return [CONTROLLER_STATUS_REDIRECT, $backUrl];
}
fn_save_cart_content($cart, $userId);

fn_set_notification('N', __('notice'), __('eurosite.added_to_cart', [
    '[default]' => 'Your stay was added to the cart — complete checkout to confirm the reservation.',
]));

return [CONTROLLER_STATUS_REDIRECT, fn_travel_core_after_add_to_cart_url('eurosite')];
