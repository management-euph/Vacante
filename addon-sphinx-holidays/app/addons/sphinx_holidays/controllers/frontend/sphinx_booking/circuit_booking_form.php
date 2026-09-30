<?php
declare(strict_types=1);
/**
 * Sphinx Booking Controller — Circuit Booking Form Mode
 *
 * Gets a quote for a specific circuit + departure date + occupancy,
 * then displays the guest entry form with optional services.
 *
 * Flow: user selects circuit from rates → this controller gets quote → shows form
 *
 * @package SphinxHolidays
 * @since   1.1.0
 */
if (!defined('BOOTSTRAP')) { exit('Access denied'); }

use Tygh\Tygh;
use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;
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

if (empty($circuit_id) || empty($departure_date)) {
    fn_set_notification('E', __('error'), __('sphinx_holidays.invalid_circuit', ['[default]' => 'Invalid circuit selection.']));
    return [CONTROLLER_STATUS_REDIRECT, 'sphinx_booking.circuit_search'];
}

$children_ages = [];
if (!empty($children_ages_str)) {
    $children_ages = array_map('intval', array_filter(explode(',', $children_ages_str), function($v) { return $v !== ''; }));
}

try {
    $api = Container::getApi();

    $quoteParams = [
        'circuit_id' => $circuit_id,
        'departure_date' => $departure_date,
        'occupancy' => [['adults' => $adults, 'children_ages' => $children_ages]],
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

    // Take the first quote (or let user select if multiple)
    $quote = $quotes[0];
    $offer_id = TypeCoerce::toString($quote['offer_id'] ?? '');

    // Apply commission
    $quotePricing = TypeCoerce::toStringMap($quote['pricing'] ?? null);
    $sellingPrice = TypeCoerce::toFloat($quotePricing['selling_price'] ?? 0);
    $basePrice = $sellingPrice;
    $sellingPrice = Container::getCartService()->applyCommission($sellingPrice);
    // The quote stays on the server: add-to-cart prices from it, never from the form.
    (new \Tygh\Addons\SphinxHolidays\Services\CircuitQuoteStore())->remember(
        $offer_id,
        $circuit_id,
        $basePrice,
        TypeCoerce::toString($quotePricing['currency'] ?? ConfigProvider::getDefaultCurrency()),
        $departure_date,
        time(),
    );

    // Parse rooms from quote
    $rooms = TypeCoerce::toRowList($quote['rooms'] ?? []);

    // Parse additional services (optional/mandatory)
    $additionalServices = TypeCoerce::toList($quote['additional_services'] ?? []);

    $quoteDeparture = TypeCoerce::toStringMap($quote['departure'] ?? null);
    $quoteDuration = TypeCoerce::toStringMap($quote['duration'] ?? null);

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
        'rooms_data' => \Tygh\Addons\SphinxHolidays\Services\CartService::normalizeRoomsForDisplay(
            $rooms, $adults, $children_ages_str
        ),
        'meal_type' => TypeCoerce::toString($quote['meal_type_name'] ?? ''),
        'adults' => $adults,
        'children' => $children,
        'children_ages' => $children_ages_str,
        'total_price' => $sellingPrice,
        'base_price' => $basePrice,
        'currency' => TypeCoerce::toString($quotePricing['currency'] ?? ConfigProvider::getDefaultCurrency()),
        'additional_services' => $additionalServices,
        'payment_terms' => TypeCoerce::toList($quote['payment_terms'] ?? []),
        'cancellation_fees' => TypeCoerce::toList($quote['cancellation_fees'] ?? []),
        'flight' => $quote['flight'] ?? null,
        'verified' => true,
    ]);

    $view->assign('sphinx_provider', 'sphinx');

} catch (\Throwable $e) {
    fn_log_event('general', 'runtime', [
        'message' => 'Sphinx Circuit Booking Form Error: ' . $e->getMessage(),
        'file'    => $e->getFile() . ':' . $e->getLine(),
    ]);
    fn_set_notification('E', __('error'),
        __('sphinx_holidays.booking_error', ['[default]' => 'An error occurred. Please try again.']));
    return [CONTROLLER_STATUS_REDIRECT, 'sphinx_booking.circuit_search'];
}
