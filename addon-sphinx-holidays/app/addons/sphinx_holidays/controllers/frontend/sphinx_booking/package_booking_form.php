<?php
declare(strict_types=1);
/**
 * Sphinx Booking Controller — Package Booking Form Mode
 *
 * Verifies the selected package offer, retrieves payment terms and
 * cancellation fees, then displays the guest entry form with optional
 * services selection.
 *
 * Flow: search results → verify → display booking form
 *
 * @package SphinxHolidays
 * @since   1.2.0
 */
if (!defined('BOOTSTRAP')) { exit('Access denied'); }

use Tygh\Tygh;
use Tygh\Addons\SphinxHolidays\Services\CartService;
use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Helpers\RequestCoerce;
use Tygh\Addons\SphinxHolidays\ViewModels\SphinxBookingSidebarBuilder;

/** @var \Smarty $view */
$view = Tygh::$app['view'];

$offer_id = RequestCoerce::string($_REQUEST, 'offer_id');

if (empty($offer_id)) {
    fn_set_notification('E', __('error'), __('sphinx_holidays.invalid_offer', ['[default]' => 'Invalid offer.']));
    return [CONTROLLER_STATUS_REDIRECT, 'sphinx_booking.package_search'];
}

// Carry forward search params for back navigation
$adults = max(1, RequestCoerce::int($_REQUEST, 'adults', 2));
$children = max(0, RequestCoerce::int($_REQUEST, 'children'));
$children_ages_str = RequestCoerce::string($_REQUEST, 'children_ages');
$rooms = max(1, RequestCoerce::int($_REQUEST, 'rooms', 1));

try {
    $api = Container::getApi();

    // Verify the offer — SphinxApi returns the offer PAYLOAD (pricing,
    // payment terms, cancellation fees) with the response envelope already
    // unwrapped by ResponseDecoder. The old raw ['data'] read here was one
    // envelope-drift away from the "every Rezervă rejected" hotel incident.
    $verifyResult = $api->verifyPackageOffer($offer_id);

    if (empty($verifyResult) || empty($verifyResult['pricing'])) {
        fn_set_notification('W', __('warning'),
            __('sphinx_holidays.offer_unavailable', ['[default]' => 'This offer is no longer available.']));
        return [CONTROLLER_STATUS_REDIRECT, 'sphinx_booking.package_search'];
    }

    // A package the store cannot sell (its hotel has no buyable product) is
    // turned away now, not once the guests are filled in: add-to-cart would
    // refuse it (CartService::resolveProductId()). The results page leaves
    // such packages out; an older link can still lead here.
    $cartService = Container::getCartService();
    if ($cartService->resolveProductId(CartService::packageHotelId($verifyResult)) <= 0) {
        fn_set_notification('W', __('warning'),
            __('sphinx_holidays.offer_unavailable', ['[default]' => 'This offer is no longer available.']));
        return [CONTROLLER_STATUS_REDIRECT, 'sphinx_booking.package_search'];
    }

    /** @var array<string, mixed> $offer */
    $offer = $verifyResult;

    // Apply commission
    /** @var array<string, mixed> $pricing */
    $pricing = is_array($offer['pricing'] ?? null) ? $offer['pricing'] : [];
    $sellingPrice = TypeCoerce::toFloat($pricing['selling_price'] ?? 0);
    $basePrice = $sellingPrice;
    $sellingPrice = $cartService->applyCommission($sellingPrice);

    // Parse hotel info
    /** @var array<string, mixed> $hotel */
    $hotel = is_array($offer['hotel'] ?? null) ? $offer['hotel'] : [];
    $hotelRooms = is_array($hotel['rooms'] ?? null) ? $hotel['rooms'] : [];

    // Parse transport info
    /** @var array<string, mixed>|null $flight */
    $flight = is_array($offer['flight'] ?? null) ? $offer['flight'] : null;
    $bus = is_array($offer['bus'] ?? null) ? $offer['bus'] : [];
    $transfers = is_array($offer['transfers'] ?? null) ? $offer['transfers'] : [];

    // Determine transport type
    $transportType = 'hotel-only';
    if (is_array($flight) && !empty($flight['outbound'])) {
        $transportType = 'flight';
    } elseif (!empty($bus)) {
        $transportType = 'bus';
    }

    // The services in the shopper's money, as the total is shown: the booking
    // page adds a ticked one to the total (booking-conditions.js).
    $pkgCurrency = TypeCoerce::toString($pricing['currency'] ?? '');
    $pkgCurrency = $pkgCurrency !== '' ? $pkgCurrency : ConfigProvider::getDefaultCurrency();
    $pkgMoney = \Tygh\Addons\TravelCore\Services\MoneyFormatter::forStore();
    $pkgToPrimary = static fn (float $amount): float => (new \Tygh\Addons\TravelCore\Services\CurrencyService($pkgCurrency))
        ->convertFromApiCurrency($amount, defined('CART_PRIMARY_CURRENCY') ? TypeCoerce::toString(CART_PRIMARY_CURRENCY) : $pkgCurrency);
    $pkgServices = \Tygh\Addons\SphinxHolidays\Helpers\ServiceRows::withDisplay(
        \Tygh\Addons\SphinxHolidays\Helpers\ServiceRows::forShopper($offer['additional_services'] ?? null, $cartService->applyCommission(...)),
        static fn (float $api): float => $pkgMoney->toDisplay($pkgToPrimary($api)),
        static fn (float $amount): string => $pkgMoney->formatDisplay($amount),
    );
    $view->assign('sphinx_trip_money', [
        'base' => round($pkgMoney->toDisplay($pkgToPrimary($sellingPrice)), 2),
        'format' => $pkgMoney->scriptFormat(),
    ]);

    $pkgRoomsData = CartService::normalizeRoomsForDisplay(
        TypeCoerce::toRowList($hotelRooms), $adults, $children_ages_str
    );

    $view->assign('sphinx_package_booking', [
        'offer_id' => TypeCoerce::toString($offer['offer_id'] ?? $offer_id),
        'destination_name' => TypeCoerce::toString($offer['destination_name'] ?? ''),
        'hotel_id' => TypeCoerce::toString($hotel['id'] ?? ''),
        'hotel_name' => TypeCoerce::toString($hotel['name'] ?? ''),
        'check_in' => TypeCoerce::toString($hotel['check_in'] ?? ''),
        'check_out' => TypeCoerce::toString($hotel['check_out'] ?? ''),
        'rooms' => $hotelRooms,
        // Canonical shape for the shared guest-cards partial (synthesizes one
        // room from booking-level occupancy when the quote carries none).
        'rooms_data' => $pkgRoomsData,
        'meal_type' => TypeCoerce::toString($hotel['meal_type_name'] ?? ''),
        'transport_type' => $transportType,
        'flight' => $flight,
        'bus' => $bus,
        'transfers' => $transfers,
        'adults' => $adults,
        'children' => $children,
        'children_ages' => $children_ages_str,
        'num_rooms' => $rooms,
        'total_price' => $sellingPrice,
        'base_price' => $basePrice,
        'currency' => TypeCoerce::toString($pricing['currency'] ?? ConfigProvider::getDefaultCurrency()),
        'additional_services' => $pkgServices,
        'payment_terms' => $offer['payment_terms'] ?? [],
        'cancellation_fees' => $offer['cancellation_fees'] ?? [],
        'confirmation' => $offer['confirmation'] ?? '',
        'labels' => $offer['labels'] ?? [],
        'included_services' => $offer['included_services'] ?? [],
        'not_included_services' => $offer['not_included_services'] ?? [],
        'verified' => true,
    ]);

    // Cancellation and payment, as a hotel's booking page shows them
    // (travel_core booking_terms_timeline.tpl): the verified offer's own
    // terms, the commissioned price on the cart line's scale (API currency →
    // the store's primary, as CartService stores the line), in the shopper's
    // currency.
    $packageCurrency = TypeCoerce::toString($pricing['currency'] ?? '');
    $packageCurrency = $packageCurrency !== '' ? $packageCurrency : ConfigProvider::getDefaultCurrency();
    $view->assign('sphinx_trip_terms', \Tygh\Addons\SphinxHolidays\ViewModels\SphinxBookingSidebarBuilder::tripTerms(
        $offer['cancellation_fees'] ?? null,
        $offer['payment_terms'] ?? null,
        (new \Tygh\Addons\TravelCore\Services\CurrencyService($packageCurrency))
            ->convertFromApiCurrency($sellingPrice, defined('CART_PRIMARY_CURRENCY') ? TypeCoerce::toString(CART_PRIMARY_CURRENCY) : $packageCurrency),
        \Tygh\Addons\TravelCore\Services\MoneyFormatter::forStore(),
        date('Y-m-d'),
        \Tygh\Addons\TravelCore\Services\TravelCoreConfig::getDateFormat(),
    ));

    // The shared booking page's summary (travel_core booking_sidebar.tpl), as
    // the hotel page builds it, with the trip's own parts: its kind, its
    // journeys and what the price includes. "Ofertă" and "Schimbați-vă
    // opțiunea" lead back to the hotel's page in this package's mode, on the
    // same departure date and nights.
    $pkgHotelId = TypeCoerce::toString($hotel['id'] ?? '');
    $pkgHotelName = TypeCoerce::toString($hotel['name'] ?? '');
    $pkgProductId = $cartService->resolveProductId(CartService::packageHotelId($verifyResult));
    $pkgHotelRow = $pkgHotelId !== '' ? Container::getHotelRepository()->findById($pkgHotelId) : null;
    $pkgCheckIn = TypeCoerce::toString($hotel['check_in'] ?? '');
    $pkgCheckOut = TypeCoerce::toString($hotel['check_out'] ?? '');
    $pkgNights = max(0, (int) round(((int) strtotime($pkgCheckOut) - (int) strtotime($pkgCheckIn)) / 86400));
    $pkgFirstRoom = TypeCoerce::toRowList($hotelRooms)[0] ?? [];
    $pkgLabels = [];
    foreach (TypeCoerce::toRowList($offer['labels'] ?? null) as $pkgLabel) {
        $pkgLabelName = trim(TypeCoerce::toString($pkgLabel['name'] ?? ''));
        if ($pkgLabelName !== '') {
            $pkgLabels[] = $pkgLabelName;
        }
    }
    $pkgPrimaryTotal = (new \Tygh\Addons\TravelCore\Services\CurrencyService($packageCurrency))
        ->convertFromApiCurrency($sellingPrice, defined('CART_PRIMARY_CURRENCY') ? TypeCoerce::toString(CART_PRIMARY_CURRENCY) : $packageCurrency);
    $pkgSidebar = SphinxBookingSidebarBuilder::build(
        [
            'product_id' => $pkgProductId,
            'rooms_data' => $pkgRoomsData,
            'room_name' => TypeCoerce::toString($pkgFirstRoom['name'] ?? ''),
            'check_in' => $pkgCheckIn,
            'check_out' => $pkgCheckOut,
            'nights' => $pkgNights,
            'num_rooms' => $rooms,
            'adults' => $adults,
            'children' => $children,
            'children_ages' => $children_ages_str,
            'board_name' => TypeCoerce::toString($hotel['meal_type_name'] ?? ''),
        ],
        SphinxBookingSidebarBuilder::headerFromRow($pkgHotelId, $pkgHotelName, $pkgHotelRow, $pkgProductId),
        $pkgHotelRow,
        number_format($sellingPrice, 2, ',', '.') . ' ' . TypeCoerce::toString($pricing['currency'] ?? ''),
        $offer['cancellation_fees'] ?? null,
        true,
        defined('CART_LANGUAGE') ? TypeCoerce::toString(CART_LANGUAGE) : 'en',
        \Tygh\Addons\TravelCore\Services\MoneyFormatter::forStore(),
        $pkgPrimaryTotal,
        0.0,
        implode(' · ', $pkgLabels),
        TypeCoerce::toString($offer['confirmation'] ?? ''),
        $offer['payment_terms'] ?? null,
        date('Y-m-d'),
        \Tygh\Addons\TravelCore\Services\TravelCoreConfig::getDateFormat(),
        \Tygh\Addons\TravelCore\Services\TravelCoreConfig::getBookingSidebarMaxFeatures(),
    )->withTrip(
        match ($transportType) {
            'flight' => TypeCoerce::toString(__('sphinx_holidays.trip_kind_flight')),
            'bus' => TypeCoerce::toString(__('sphinx_holidays.trip_kind_bus')),
            default => TypeCoerce::toString(__('sphinx_holidays.trip_kind_hotel')),
        },
        SphinxBookingSidebarBuilder::tripLegs(
            $flight,
            TypeCoerce::toRowList($bus),
            [
                'out' => TypeCoerce::toString(__('sphinx_holidays.outbound')),
                'back' => TypeCoerce::toString(__('sphinx_holidays.return')),
                'bus' => TypeCoerce::toString(__('sphinx_holidays.bus_transport')),
            ],
            static fn (int $ts): string => \Tygh\Addons\TravelCore\Services\DateHelper::formatStoreDate($ts),
        ),
        SphinxBookingSidebarBuilder::tripIncludes(
            TypeCoerce::toStringList($offer['included_services'] ?? null),
            TypeCoerce::toRowList($transfers),
        ),
        TypeCoerce::toStringList($offer['not_included_services'] ?? null),
        \Tygh\Addons\TravelCore\ViewModels\BookingSidebarFactory::changeSelectionUrl($pkgProductId, [
            'transport' => $transportType === 'hotel-only' ? '' : $transportType,
            'departure_date' => $pkgCheckIn,
            'nights' => $pkgNights,
            'adults' => $adults,
            'children' => $children,
            'children_ages' => $children_ages_str,
            'rooms' => $rooms,
        ]),
    );
    $view->assign('travel_booking_sidebar', $pkgSidebar->toViewArray());

    $view->assign('sphinx_provider', 'sphinx');
    // packages/book wants every traveller's gender (m or f), children too.
    $view->assign('sphinx_gender_options', [
        'm' => TypeCoerce::toString(__('travel_core.gender_male')),
        'f' => TypeCoerce::toString(__('travel_core.gender_female')),
    ]);

} catch (\Throwable $e) {
    fn_log_event('general', 'runtime', [
        'message' => 'Sphinx Package Booking Form Error: ' . $e->getMessage(),
        'file'    => $e->getFile() . ':' . $e->getLine(),
    ]);
    fn_set_notification('E', __('error'),
        __('sphinx_holidays.booking_error', ['[default]' => 'An error occurred. Please try again.']));
    return [CONTROLLER_STATUS_REDIRECT, 'sphinx_booking.package_search'];
}
