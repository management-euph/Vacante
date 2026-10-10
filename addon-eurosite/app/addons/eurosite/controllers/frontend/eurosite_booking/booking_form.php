<?php

declare(strict_types=1);
/**
 * eurosite_booking.booking_form — the guest booking form: the SAME shared
 * travel_core page sphinx and novoton render (summary sidebar + guest cards +
 * conditions modal), fed with eurosite data. Everything about the offer
 * comes from the server-side snapshot written at search time
 * (OfferContextStore) — the URL carries only the offer_key, so prices and
 * occupancy cannot be tampered with.
 *
 * Eurosite pax specifics (AddBookingRequest): per guest name, PaxType
 * adult/child, TGender B/F (C for children), DOB, ChildAge — passed to the
 * shared booking_guest_room_body.tpl as its opt-in gb_gender_options /
 * gb_child_gender params.
 */

use Tygh\Addons\Eurosite\Services\BookingSidebarBuilder;
use Tygh\Addons\Eurosite\Services\ConfigProvider;
use Tygh\Addons\Eurosite\Services\BookingCartProduct;
use Tygh\Addons\Eurosite\Services\BookingReturnUrl;
use Tygh\Addons\Eurosite\Services\Container;
use Tygh\Addons\Eurosite\Services\OfferContextStore;
use Tygh\Addons\Eurosite\Services\RoomOccupancy;
use Tygh\Addons\TravelCore\Helpers\RequestCoerce;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\MoneyFormatter;
use Tygh\Addons\TravelCore\Services\TravelCoreConfig;
use Tygh\Addons\TravelCore\ViewModels\BookingSidebarFactory;
use Tygh\Registry;
use Tygh\Tygh;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

/** @var \Smarty $view */
$view = Tygh::$app['view'];

$offerKey = (string) preg_replace('/[^a-f0-9]/', '', strtolower(RequestCoerce::string($_REQUEST, 'offer_key')));
$snapshot = $offerKey !== '' ? OfferContextStore::get($offerKey) : null;

if ($snapshot === null) {
    fn_set_notification('W', __('warning'), __('eurosite.offer_expired', [
        '[default]' => 'The selected offer has expired — please search again.',
    ]));

    // Back to the product page, which restores the guest's last search.
    return [CONTROLLER_STATUS_REDIRECT, BookingReturnUrl::forProduct(BookingReturnUrl::requestedProductId($_REQUEST))];
}

$occupancy = RoomOccupancy::fromSnapshot($snapshot);
$hotelRow = Container::hotels()->findByProductCode(TypeCoerce::toString($snapshot['product_code']));

// A booking goes on the hotel's own product (add_to_cart): say so now, not
// after the guest has filled in the form.
$cartProductId = BookingCartProduct::forSnapshot($snapshot, $hotelRow)['product_id'];
if ($cartProductId <= 0) {
    fn_set_notification('E', __('error'), __('eurosite.hotel_not_bookable', [
        '[default]' => 'This hotel cannot be booked online yet — please contact us to book it.',
    ]));

    // No product page to return to: it is disabled or gone.
    return [CONTROLLER_STATUS_REDIRECT, BookingReturnUrl::HOME];
}

// Cancellation fees for the sidebar card + conditions modal (best effort —
// the card stays hidden when the API has no schedule for us).
$fees = [];
try {
    $tourop = $hotelRow !== null ? TypeCoerce::toString($hotelRow['tourop_code'] ?? '') : '';
    $fees = Container::getApi()->getItemFees([
        'currency'     => TypeCoerce::toString($snapshot['currency']),
        'country_code' => TypeCoerce::toString($snapshot['country_code']),
        'city_code'    => TypeCoerce::toString($snapshot['city_code']),
        'product_code' => TypeCoerce::toString($snapshot['product_code']),
        'variant_id'   => TypeCoerce::toString($snapshot['variant_id']),
        'check_in'     => TypeCoerce::toString($snapshot['check_in']),
        'check_out'    => TypeCoerce::toString($snapshot['check_out']),
        'tourop_code'  => $tourop,
        'rooms'        => RoomOccupancy::itemRooms($snapshot),
    ]);
} catch (\Throwable $e) {
    fn_log_event('general', 'runtime', ['message' => 'Eurosite booking_form fees unavailable: ' . $e->getMessage()]);
}

$paymentLines = BookingSidebarFactory::termLines(ConfigProvider::getPaymentTermsText());

// "Bucharest, Romania" — CS-Cart's country name when it knows the code.
$countryCode = TypeCoerce::toString($snapshot['country_code']);
$countryName = function_exists('fn_get_country_name') ? TypeCoerce::toString(fn_get_country_name($countryCode)) : '';
$locationLine = implode(', ', array_filter([
    TypeCoerce::toString($snapshot['city_name']),
    $countryName !== '' ? $countryName : $countryCode,
], static fn (string $part): bool => $part !== ''));

// Offer currency → store primary: the SAME coefficient add_to_cart puts on
// the cart line, so the booking page and the cart agree on the amount.
$coefficient = TypeCoerce::toFloat(db_get_field(
    'SELECT coefficient FROM ?:currencies WHERE currency_code = ?s',
    TypeCoerce::toString($snapshot['currency']),
));

// Facility chips: Eurosite's own product info (cached getProductInfo),
// parsed from the operator's "Facilitati: …" line. Rows cached before the
// parser existed carry none until the product-info cron refreshes them.
$features = [];
if ($hotelRow !== null) {
    $infoRow = Container::productInfoCache()->get(
        TypeCoerce::toString($hotelRow['tourop_code'] ?? ''),
        TypeCoerce::toString($snapshot['product_code']),
    );
    $infoPayload = json_decode(TypeCoerce::toString($infoRow['payload_json'] ?? ''), true);
    $features = TypeCoerce::toStringList(is_array($infoPayload) ? ($infoPayload['facilities'] ?? []) : []);
}

$productId = $hotelRow !== null ? TypeCoerce::toInt($hotelRow['product_id'] ?? 0) : 0;
$sidebar = BookingSidebarBuilder::build(
    $snapshot,
    $hotelRow,
    $fees,
    $paymentLines,
    $locationLine,
    function_exists('fn_travel_core_product_main_pair') ? fn_travel_core_product_main_pair($productId) : [],
    TypeCoerce::toString(__('eurosite.cancel_fee_line')),
    date('Y-m-d'),
    TravelCoreConfig::getDateFormat(),
    MoneyFormatter::forStore(),
    $coefficient,
    $features,
    TravelCoreConfig::getBookingSidebarMaxFeatures(),
);
$view->assign('travel_booking_sidebar', $sidebar->toViewArray());

// One guest card per room, in the shape the shared cards iterate; seq_offset
// numbers the guests across rooms (Guest 1…N), as on the other providers.
$guestRooms = [];
$seq = 0;
foreach ($occupancy as $i => $room) {
    $guestRooms[] = [
        'num'          => $i + 1,
        'idx'          => $i,
        'seq_offset'   => $seq,
        'adults'       => $room['adults'],
        'children'     => count($room['children_ages']),
        'childrenAges' => $room['children_ages'],
    ];
    $seq += $room['adults'] + count($room['children_ages']);
}
$view->assign('eurosite_rooms', $guestRooms);
// AddBookingRequest TGender: B (male) / F (female); explicit words, no
// preselection (the shared radios are required).
$view->assign('eurosite_gender_options', [
    'B' => TypeCoerce::toString(__('travel_core.gender_male')),
    'F' => TypeCoerce::toString(__('travel_core.gender_female')),
]);
// Display-only: the shared child-age guard reads the form's check_in.
// add_to_cart re-reads every fact from the snapshot.
$view->assign('eurosite_check_in', TypeCoerce::toString($snapshot['check_in']));
$view->assign('eurosite_offer_key', $offerKey);
// Back = the product page with this stay: its engine re-runs the search.
$view->assign('eurosite_back_url', BookingReturnUrl::forSnapshot($snapshot, $cartProductId, $occupancy));
$view->assign('eurosite_return_product_id', $cartProductId);

$pageTitle = TypeCoerce::toString(__('eurosite.complete_booking'));
$view->assign('page_title', $pageTitle);
Registry::set('navigation.dynamic.page_title', $pageTitle);
// No breadcrumb: the page title and the progress bar share one row
// (booking_steps.tpl), so the form starts higher. <title> still uses page_title.
