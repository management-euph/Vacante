<?php
declare(strict_types=1);
/**
 * Sphinx Booking Controller — Circuit Booking Form Mode
 *
 * Gets a quote for a specific circuit + departure date + occupancy,
 * then displays the guest entry form with optional services.
 *
 * Flow: user selects circuit from rates → this controller gets quote → shows form.
 * From a circuit's page (board 16.3), "Rezervă" on a room brings its offer
 * (offer_id): the form shows that offer as it was quoted there, without
 * quoting again; when it is no longer kept, the same room (offer_key) in a
 * new quote.
 *
 * @package SphinxHolidays
 * @since   1.1.0
 */
if (!defined('BOOTSTRAP')) { exit('Access denied'); }

use Tygh\Tygh;
use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;
use Tygh\Addons\SphinxHolidays\Services\CircuitQuoteStore;
use Tygh\Addons\SphinxHolidays\Services\CircuitRoomOffers;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Helpers\RequestCoerce;

/** @var \Smarty $view */
$view = Tygh::$app['view'];

$circuit_id = RequestCoerce::int($_REQUEST, 'circuit_id');
$departure_date = RequestCoerce::string($_REQUEST, 'departure_date');
$departure_id = RequestCoerce::int($_REQUEST, 'departure_id');
$adults = max(1, RequestCoerce::int($_REQUEST, 'adults', 2));
$children = max(0, RequestCoerce::int($_REQUEST, 'children'));
$children_ages_str = RequestCoerce::string($_REQUEST, 'children_ages');
// The circuit bar (board 16.3) sends each room as the hotel picker does
// (rooms_data); flat totals are one room, as before.
$rooms_data_json = RequestCoerce::string($_REQUEST, 'rooms_data');

if (empty($circuit_id) || empty($departure_date)) {
    fn_set_notification('E', __('error'), __('sphinx_holidays.invalid_circuit', ['[default]' => 'Invalid circuit selection.']));
    return [CONTROLLER_STATUS_REDIRECT, 'sphinx_booking.circuit_search'];
}

// One occupancy entry per room (the shared resolver the hotel and package
// searches use); the totals the form carries follow from it.
$occupancy = \Tygh\Addons\SphinxHolidays\Helpers\SearchOccupancyResolver::resolve(
    $rooms_data_json,
    $adults,
    max(1, RequestCoerce::int($_REQUEST, 'rooms', 1)),
    $children_ages_str,
);

// The room chosen on the circuit's page (board 16.3, "Rezervă"): its offer
// as quoted there, and what it is (rooms and meal) should that offer be
// gone (CircuitRoomOffers::key()).
$chosen_offer = RequestCoerce::string($_REQUEST, 'offer_id');
$chosen_key = RequestCoerce::string($_REQUEST, 'offer_key');

try {
    $quoteStore = new CircuitQuoteStore();
    $quote = $chosen_offer !== '' ? $quoteStore->quote($chosen_offer, $circuit_id, time()) : null;
    if ($quote !== null && TypeCoerce::toString($quote['departure_date'] ?? '') !== $departure_date) {
        $quote = null;
    }
    $fresh = $quote === null;

    if ($quote !== null) {
        // The offer is priced for its own rooms: those, whatever the link says.
        $quotedOccupancy = CircuitRoomOffers::occupancyOf($quote);
        if ($quotedOccupancy !== []) {
            $occupancy = $quotedOccupancy;
        }
    } else {
        $api = Container::getApi();

        $quoteParams = [
            'circuit_id' => $circuit_id,
            'departure_date' => $departure_date,
            'occupancy' => $occupancy,
        ];
        if ($departure_id > 0) {
            $quoteParams['departure_id'] = $departure_id;
        }

        $quoteResponse = $api->getCircuitQuote($quoteParams);
        // SphinxApi returns the decoded list of quotes directly.
        $quotes = TypeCoerce::toRowList($quoteResponse);

        if (empty($quotes)) {
            fn_set_notification('W', __('warning'),
                __('sphinx_holidays.no_circuit_quotes', ['[default]' => 'No quotes available for this circuit. Please try different dates.']));
            return [CONTROLLER_STATUS_REDIRECT, 'sphinx_booking.circuit_search'];
        }

        // The room chosen on the page, in this new quote; without a choice, the first.
        $quote = $chosen_key !== '' ? CircuitRoomOffers::withKey($quotes, $chosen_key) : $quotes[0];
        if ($quote === null) {
            // That room is no longer offered: back to the circuit's rooms.
            fn_set_notification('W', __('warning'),
                __('sphinx_holidays.offer_unavailable', ['[default]' => 'This offer is no longer available.']));
            $circuit_product_id = Container::getCartService()->resolveCircuitProductId($circuit_id, 0);
            return [CONTROLLER_STATUS_REDIRECT, $circuit_product_id > 0 ? 'products.view?product_id=' . $circuit_product_id : 'sphinx_booking.circuit_search'];
        }
    }

    $all_ages = array_merge(...array_column($occupancy, 'children_ages'));
    $adults = array_sum(array_column($occupancy, 'adults'));
    $children = count($all_ages);
    $children_ages_str = implode(',', $all_ages);

    $offer_id = TypeCoerce::toString($quote['offer_id'] ?? '');
    $quoteDeparture = TypeCoerce::toStringMap($quote['departure'] ?? null);

    // Apply commission
    $quotePricing = TypeCoerce::toStringMap($quote['pricing'] ?? null);
    // The quote's rooms: their codes are what circuits/book names (room_code).
    $rooms = TypeCoerce::toRowList($quote['rooms'] ?? []);
    $sellingPrice = TypeCoerce::toFloat($quotePricing['selling_price'] ?? 0);
    $basePrice = $sellingPrice;
    $sellingPrice = Container::getCartService()->applyCommission($sellingPrice);
    // The quote stays on the server: add-to-cart prices from it, never from
    // the form — its price, rooms, meal plan, departure city and terms
    // (CircuitQuoteStore::rememberQuotes()). An offer chosen on the page is
    // kept already.
    if ($fresh) {
        $quoteStore->rememberQuotes([$quote], $circuit_id, $departure_date, time());
    }
    $quoteCurrency = TypeCoerce::toString($quotePricing['currency'] ?? '');
    $quoteCurrency = $quoteCurrency !== '' ? $quoteCurrency : ConfigProvider::getDefaultCurrency();


    // Parse additional services (optional/mandatory)
    // The services in the shopper's money, as the total is shown: the booking
    // page adds a ticked one to the total (booking-conditions.js).
    $cirMoney = \Tygh\Addons\TravelCore\Services\MoneyFormatter::forStore();
    $cirToPrimary = static fn (float $amount): float => (new \Tygh\Addons\TravelCore\Services\CurrencyService($quoteCurrency))
        ->convertFromApiCurrency($amount, defined('CART_PRIMARY_CURRENCY') ? TypeCoerce::toString(CART_PRIMARY_CURRENCY) : $quoteCurrency);
    $additionalServices = \Tygh\Addons\SphinxHolidays\Helpers\ServiceRows::withDisplay(
        \Tygh\Addons\SphinxHolidays\Helpers\ServiceRows::forShopper($quote['additional_services'] ?? null, Container::getCartService()->applyCommission(...)),
        static fn (float $api): float => $cirMoney->toDisplay($cirToPrimary($api)),
        static fn (float $amount): string => $cirMoney->formatDisplay($amount),
    );
    $view->assign('sphinx_trip_money', [
        'base' => round($cirMoney->toDisplay($cirToPrimary($sellingPrice)), 2),
        'format' => $cirMoney->scriptFormat(),
    ]);

    $quoteDuration = TypeCoerce::toStringMap($quote['duration'] ?? null);

    $cirRoomsData = \Tygh\Addons\SphinxHolidays\Services\CartService::normalizeRoomsForDisplay(
        $rooms, $adults, $children_ages_str
    );

    $view->assign('sphinx_circuit_booking', [
        'offer_id' => $offer_id,
        'circuit_id' => $circuit_id,
        'title' => TypeCoerce::toString($quote['title'] ?? ''),
        'departure_date' => $departure_date,
        'departure_id' => $departure_id,
        'departure_name' => TypeCoerce::toString($quoteDeparture['name'] ?? ''),
        'transport_type' => TypeCoerce::toString($quote['transport_type'] ?? ''),
        'duration_days' => TypeCoerce::toInt($quoteDuration['days'] ?? 0),
        'duration_nights' => TypeCoerce::toInt($quoteDuration['nights'] ?? 0),
        'summary' => TypeCoerce::toString($quote['summary'] ?? ''),
        'image' => TypeCoerce::toString($quote['image'] ?? ''),
        'rooms' => $rooms,
        // Canonical shape for the shared guest-cards partial (synthesizes one
        // room from booking-level occupancy when the quote carries none).
        'rooms_data' => $cirRoomsData,
        'meal_type' => TypeCoerce::toString($quote['meal_type_name'] ?? ''),
        'adults' => $adults,
        'children' => $children,
        'children_ages' => $children_ages_str,
        // The rooms as the bar sent them, for a way back to this step.
        'occupancy_json' => (string) json_encode(array_map(
            static fn (array $room): array => ['adults' => $room['adults'], 'children' => count($room['children_ages']), 'childrenAges' => $room['children_ages']],
            $occupancy,
        )),
        'total_price' => $sellingPrice,
        'base_price' => $basePrice,
        'currency' => $quoteCurrency,
        'additional_services' => $additionalServices,
        'flight' => $quote['flight'] ?? null,
        'verified' => true,
    ]);

    // Cancellation and payment, as a hotel's booking page shows them
    // (travel_core booking_terms_timeline.tpl): the quote's own terms, the
    // commissioned price on the cart line's scale (API currency → the store's
    // primary, as CartService stores the line), in the shopper's currency.
    $view->assign('sphinx_trip_terms', \Tygh\Addons\SphinxHolidays\ViewModels\SphinxBookingSidebarBuilder::tripTerms(
        $quote['cancellation_fees'] ?? null,
        $quote['payment_terms'] ?? null,
        (new \Tygh\Addons\TravelCore\Services\CurrencyService($quoteCurrency))
            ->convertFromApiCurrency($sellingPrice, defined('CART_PRIMARY_CURRENCY') ? TypeCoerce::toString(CART_PRIMARY_CURRENCY) : $quoteCurrency),
        \Tygh\Addons\TravelCore\Services\MoneyFormatter::forStore(),
        date('Y-m-d'),
        \Tygh\Addons\TravelCore\Services\TravelCoreConfig::getDateFormat(),
    ));

    // The shared booking page's summary (travel_core booking_sidebar.tpl), as
    // the package page builds it: the circuit's title and picture (its
    // product's, else the quote's), the departure and return dates, the rooms
    // and meal plan, the flights, what the price includes, the price and the
    // terms. "Ofertă" and "Schimbați-vă opțiunea" lead back to the circuit's
    // page, where its bar picks the departure.
    $cirProductId = Container::getCartService()->resolveCircuitProductId($circuit_id, 0);
    $cirNights = TypeCoerce::toInt($quoteDuration['nights'] ?? 0);
    $cirDepartTs = (int) strtotime($departure_date);
    $cirReturn = $cirDepartTs > 0 ? date('Y-m-d', (int) strtotime('+' . $cirNights . ' days', $cirDepartTs)) : '';
    $cirFirstRoom = $rooms[0] ?? [];
    $cirFlight = $quote['flight'] ?? null;
    $cirSidebar = \Tygh\Addons\SphinxHolidays\ViewModels\SphinxBookingSidebarBuilder::build(
        [
            'product_id' => $cirProductId,
            'rooms_data' => $cirRoomsData,
            'room_name' => TypeCoerce::toString($cirFirstRoom['name'] ?? ''),
            'check_in' => $departure_date,
            'check_out' => $cirReturn,
            'nights' => $cirNights,
            'num_rooms' => max(1, count($occupancy)),
            'adults' => $adults,
            'children' => $children,
            'children_ages' => $children_ages_str,
            'board_name' => TypeCoerce::toString($quote['meal_type_name'] ?? ''),
        ],
        \Tygh\Addons\TravelCore\ViewModels\HotelHeaderFactory::bare(TypeCoerce::toString($quote['title'] ?? ''), 0, $cirProductId),
        null,
        number_format($sellingPrice, 2, ',', '.') . ' ' . $quoteCurrency,
        $quote['cancellation_fees'] ?? null,
        true,
        defined('CART_LANGUAGE') ? TypeCoerce::toString(CART_LANGUAGE) : 'en',
        \Tygh\Addons\TravelCore\Services\MoneyFormatter::forStore(),
        (new \Tygh\Addons\TravelCore\Services\CurrencyService($quoteCurrency))
            ->convertFromApiCurrency($sellingPrice, defined('CART_PRIMARY_CURRENCY') ? TypeCoerce::toString(CART_PRIMARY_CURRENCY) : $quoteCurrency),
        0.0,
        '',
        TypeCoerce::toString($quote['confirmation'] ?? ''),
        $quote['payment_terms'] ?? null,
        date('Y-m-d'),
        \Tygh\Addons\TravelCore\Services\TravelCoreConfig::getDateFormat(),
        \Tygh\Addons\TravelCore\Services\TravelCoreConfig::getBookingSidebarMaxFeatures(),
    )->withTrip(
        TypeCoerce::toString(__('sphinx_holidays.trip_kind_circuit')),
        \Tygh\Addons\SphinxHolidays\ViewModels\SphinxBookingSidebarBuilder::tripLegs(
            is_array($cirFlight) ? TypeCoerce::toStringMap($cirFlight) : null,
            [],
            [
                'out' => TypeCoerce::toString(__('sphinx_holidays.outbound')),
                'back' => TypeCoerce::toString(__('sphinx_holidays.return')),
                'bus' => TypeCoerce::toString(__('sphinx_holidays.bus_transport')),
            ],
            static fn (int $ts): string => \Tygh\Addons\TravelCore\Services\DateHelper::formatStoreDate($ts),
        ),
        \Tygh\Addons\SphinxHolidays\ViewModels\SphinxBookingSidebarBuilder::tripIncludes(
            TypeCoerce::toStringList($quote['included_services'] ?? null),
            [],
        ),
        TypeCoerce::toStringList($quote['not_included_services'] ?? null),
        \Tygh\Addons\TravelCore\ViewModels\BookingSidebarFactory::changeSelectionUrl($cirProductId, []),
        TypeCoerce::toString(__('sphinx_holidays.departure')),
        TypeCoerce::toString(__('sphinx_holidays.trip_return')),
        TypeCoerce::toString($quote['image'] ?? ''),
    );
    $view->assign('travel_booking_sidebar', $cirSidebar->toViewArray());

    $view->assign('sphinx_provider', 'sphinx');
    // circuits/book wants every traveller's gender (m or f), children too.
    $view->assign('sphinx_gender_options', [
        'm' => TypeCoerce::toString(__('travel_core.gender_male')),
        'f' => TypeCoerce::toString(__('travel_core.gender_female')),
    ]);

} catch (\Throwable $e) {
    fn_log_event('general', 'runtime', [
        'message' => 'Sphinx Circuit Booking Form Error: ' . $e->getMessage(),
        'file'    => $e->getFile() . ':' . $e->getLine(),
    ]);
    fn_set_notification('E', __('error'),
        __('sphinx_holidays.booking_error', ['[default]' => 'An error occurred. Please try again.']));
    return [CONTROLLER_STATUS_REDIRECT, 'sphinx_booking.circuit_search'];
}
