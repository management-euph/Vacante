<?php

declare(strict_types=1);
/**
 * eurosite_booking.package_booking_form — the booking page of a package
 * chosen on a hotel's page ("Rezervă" in "Camere și prețuri"): the shared
 * Travel Core booking page, as the hotel's and Sphinx's packages have it.
 *
 * The page's link carries the offer's signed PackageOfferToken and the
 * guests. Eurosite is asked again for that departure (PackageQuote::find):
 * the page shows the offer as Eurosite has it now, or sends the guest back
 * to choose again when it is gone. What it shows is kept server-side
 * (PackageSnapshotStore); the form posts only its key.
 *
 * The guests as the hotel form asks them, with every adult's date of birth
 * required: a flight's or a coach's passenger list needs it.
 */

use Tygh\Addons\Eurosite\Services\BookingCartProduct;
use Tygh\Addons\Eurosite\Services\BookingReturnUrl;
use Tygh\Addons\Eurosite\Services\BookingSidebarBuilder;
use Tygh\Addons\Eurosite\Services\ConfigProvider;
use Tygh\Addons\Eurosite\Services\Container;
use Tygh\Addons\Eurosite\Services\EurositeProductFactory;
use Tygh\Addons\Eurosite\Services\PackageDepartures;
use Tygh\Addons\Eurosite\Services\PackageExtras;
use Tygh\Addons\Eurosite\Services\PackageQuote;
use Tygh\Addons\Eurosite\Services\PackageSnapshotStore;
use Tygh\Addons\Eurosite\Services\PackageTripSummary;
use Tygh\Addons\Eurosite\Services\RoomOccupancy;
use Tygh\Addons\TravelCore\Helpers\RequestCoerce;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\DateHelper;
use Tygh\Addons\TravelCore\Services\MoneyFormatter;
use Tygh\Addons\TravelCore\Services\ProviderRoomLimit;
use Tygh\Addons\TravelCore\Services\TravelCoreConfig;
use Tygh\Addons\TravelCore\ViewModels\BookingSidebarFactory;
use Tygh\Registry;
use Tygh\Tygh;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

/** @var \Smarty $view */
$view = Tygh::$app['view'];

$token = Container::packageOfferTokens()->decode(RequestCoerce::string($_REQUEST, 'offer_id'));
$hotelRow = $token !== null ? Container::hotels()->findByProductCode($token['product_code']) : null;
if ($token === null || $hotelRow === null) {
    fn_set_notification('W', __('warning'), __('eurosite.offer_expired', [
        '[default]' => 'The selected offer has expired — please search again.',
    ]));

    return [CONTROLLER_STATUS_REDIRECT, BookingReturnUrl::HOME];
}

$cartProductId = BookingCartProduct::forHotel(0, $hotelRow)['product_id'];
if ($cartProductId <= 0) {
    fn_set_notification('E', __('error'), __('eurosite.hotel_not_bookable', [
        '[default]' => 'This hotel cannot be booked online yet — please contact us to book it.',
    ]));

    return [CONTROLLER_STATUS_REDIRECT, BookingReturnUrl::HOME];
}

// The guests the offer was quoted for (the bar's link carries them).
$childrenAges = [];
foreach (explode(',', RequestCoerce::string($_REQUEST, 'children_ages')) as $age) {
    if ($age !== '' && is_numeric($age)) {
        $childrenAges[] = (int) $age;
    }
}
$occupancy = RoomOccupancy::fromRequest(
    RequestCoerce::string($_REQUEST, 'rooms_data'),
    max(1, RequestCoerce::int($_REQUEST, 'adults', 2)),
    $childrenAges,
);
['adults' => $adults, 'children_ages' => $childrenAges] = RoomOccupancy::totals($occupancy);

// The way back: the hotel's page on this departure, its bar asking again.
$pageTransport = $token['transport'] === 'bus' ? 'bus' : 'flight';
$hotelPage = BookingSidebarFactory::changeSelectionUrl($cartProductId, [
    'transport' => $pageTransport,
    'departure_id' => (string) PackageDepartures::departureId($token['dep_city_code']),
    'departure_date' => $token['departure_date'],
    'nights' => $token['nights'],
    'adults' => (string) $adults,
    'children' => (string) count($childrenAges),
    'children_ages' => implode(',', $childrenAges),
    'rooms' => (string) count($occupancy),
]);

$offer = null;
if (PackageQuote::validOccupancy($occupancy, ProviderRoomLimit::maxRooms('eurosite'))) {
    try {
        $offer = (new PackageQuote(Container::getApi(), Container::packageOfferTokens()))
            ->find($token, $occupancy, ConfigProvider::getDefaultLanguage());
    } catch (\Throwable $e) {
        fn_log_event('general', 'runtime', ['message' => 'Eurosite package booking form: ' . $e->getMessage()]);
    }
}
if ($offer === null) {
    fn_set_notification('W', __('warning'), __('eurosite.package_unavailable'));

    return [CONTROLLER_STATUS_REDIRECT, $hotelPage !== '' ? $hotelPage : BookingReturnUrl::HOME];
}

$snapshot = PackageSnapshotStore::snapshot($offer, $token, $occupancy, $cartProductId);

// "Servicii adiționale": what Eurosite offers for this variant, priced for
// these travellers. Kept with the snapshot, so add-to-cart only charges an
// extra this page showed.
$extras = (new PackageExtras(Container::getApi()))->forPackage(
    $snapshot,
    $occupancy,
    $offer->hotel->currency,
    ConfigProvider::getDefaultLanguage(),
);
$snapshot['extras'] = $extras;
$packageKey = PackageSnapshotStore::remember($offer, $token, $occupancy, $cartProductId, $extras);

// Offer currency → store primary: the same coefficient the cart line uses.
$coefficient = TypeCoerce::toFloat(db_get_field(
    'SELECT coefficient FROM ?:currencies WHERE currency_code = ?s',
    $offer->hotel->currency,
));
$money = MoneyFormatter::forStore();
if (abs($offer->hotel->price - TypeCoerce::toFloat($token['price'])) >= 0.01) {
    // Eurosite's price moved since the hotel's page showed it: say so first.
    fn_set_notification('W', __('warning'), __('eurosite.package_price_changed', [
        '[price]' => $money->format(EurositeProductFactory::toStorePrice($offer->hotel->price, $coefficient)),
    ]));
}

// The cancellation schedule, with the package's PackageId (best effort: the
// card stays hidden when Eurosite has none for us).
$fees = [];
try {
    $fees = Container::getApi()->getItemFees([
        'currency' => $offer->hotel->currency,
        'country_code' => TypeCoerce::toString($snapshot['country_code']),
        'city_code' => TypeCoerce::toString($snapshot['city_code']),
        'product_code' => $offer->hotel->productCode,
        'package_id' => $offer->packageId,
        'variant_id' => $offer->variantId(),
        'check_in' => $offer->hotel->checkIn,
        'check_out' => $offer->hotel->checkOut,
        'tourop_code' => TypeCoerce::toString($snapshot['tourop_code']),
        'rooms' => RoomOccupancy::itemRooms($snapshot),
    ]);
} catch (\Throwable $e) {
    fn_log_event('general', 'runtime', ['message' => 'Eurosite package booking form fees unavailable: ' . $e->getMessage()]);
}

$countryCode = TypeCoerce::toString($snapshot['country_code']);
$countryName = function_exists('fn_get_country_name') ? TypeCoerce::toString(fn_get_country_name($countryCode)) : '';
$locationLine = implode(', ', array_filter([
    TypeCoerce::toString($snapshot['city_name']),
    $countryName !== '' ? $countryName : $countryCode,
], static fn (string $part): bool => $part !== ''));

$infoRow = Container::productInfoCache()->get(TypeCoerce::toString($hotelRow['tourop_code'] ?? ''), $offer->hotel->productCode);
$infoPayload = json_decode(TypeCoerce::toString($infoRow['payload_json'] ?? ''), true);
$features = TypeCoerce::toStringList(is_array($infoPayload) ? ($infoPayload['facilities'] ?? []) : []);

$productId = TypeCoerce::toInt($hotelRow['product_id'] ?? 0);
$sidebar = BookingSidebarBuilder::build(
    $snapshot,
    $hotelRow,
    $fees,
    BookingSidebarFactory::termLines(ConfigProvider::getPaymentTermsText()),
    $locationLine,
    function_exists('fn_travel_core_product_main_pair') ? fn_travel_core_product_main_pair($productId) : [],
    TypeCoerce::toString(__('eurosite.cancel_fee_line')),
    date('Y-m-d'),
    TravelCoreConfig::getDateFormat(),
    $money,
    $coefficient,
    $features,
    TravelCoreConfig::getBookingSidebarMaxFeatures(),
)->withTrip(
    $pageTransport === 'bus'
        ? TypeCoerce::toString(__('eurosite.package_kind_bus'))
        : TypeCoerce::toString(__('eurosite.package_kind_flight')),
    PackageTripSummary::legs(
        $snapshot,
        [
            'out' => TypeCoerce::toString(__('eurosite.package_outbound')),
            'back' => TypeCoerce::toString(__('eurosite.package_return')),
            'bus' => TypeCoerce::toString(__('eurosite.package_coach')),
        ],
        static fn (int $ts): string => DateHelper::formatWith($ts, TravelCoreConfig::getDateFormat()),
    ),
    PackageTripSummary::includes($snapshot),
    [],
    $hotelPage,
);
$view->assign('travel_booking_sidebar', $sidebar->toViewArray());

// One guest card per room, numbered across rooms (Guest 1…N), as the hotel form.
$guestRooms = [];
$seq = 0;
foreach ($occupancy as $i => $room) {
    $guestRooms[] = [
        'num' => $i + 1,
        'idx' => $i,
        'seq_offset' => $seq,
        'adults' => $room['adults'],
        'children' => count($room['children_ages']),
        'childrenAges' => $room['children_ages'],
    ];
    $seq += $room['adults'] + count($room['children_ages']);
}
$view->assign('eurosite_rooms', $guestRooms);
// AddBookingRequest TGender: B (male) / F (female), as the hotel form.
$view->assign('eurosite_gender_options', [
    'B' => TypeCoerce::toString(__('travel_core.gender_male')),
    'F' => TypeCoerce::toString(__('travel_core.gender_female')),
]);
// Display-only, for the shared child-age guard; add-to-cart reads the snapshot.
$view->assign('eurosite_check_in', $offer->hotel->checkIn);
$view->assign('eurosite_package_key', $packageKey);
// The extras as the page shows them, in the shopper's money, and what the
// shared script needs to move the total when one is ticked
// (booking-conditions.js: data-travel-extras).
$extraRows = [];
foreach ($extras as $extra) {
    $amount = round($money->toDisplay(EurositeProductFactory::toStorePrice($extra['price'], $coefficient)), 2);
    // formatDisplay: the amount is already the shopper's, not the store's primary.
    $extraRows[] = $extra + ['display_amount' => $amount, 'display_price' => $amount > 0 ? '+' . $money->formatDisplay($amount) : ''];
}
$view->assign('eurosite_package_extras', $extraRows);
$view->assign('eurosite_trip_money', [
    'base' => round($money->toDisplay(EurositeProductFactory::toStorePrice($offer->hotel->price, $coefficient)), 2),
    'format' => $money->scriptFormat(),
]);
$view->assign('eurosite_package_flight', $pageTransport === 'flight');
$view->assign('eurosite_back_url', $hotelPage);
$view->assign('eurosite_return_product_id', $cartProductId);

$pageTitle = TypeCoerce::toString(__('eurosite.complete_booking'));
$view->assign('page_title', $pageTitle);
Registry::set('navigation.dynamic.page_title', $pageTitle);
