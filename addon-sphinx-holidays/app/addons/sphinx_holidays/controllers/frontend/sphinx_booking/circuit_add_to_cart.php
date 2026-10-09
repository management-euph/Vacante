<?php
declare(strict_types=1);
/**
 * Sphinx Booking Controller — Circuit Add to Cart Mode
 *
 * Optionally customizes the circuit (adds optional services),
 * then creates booking records and adds to CS-Cart cart.
 *
 * Flow: quote → customize (optional) → book → add to cart
 *
 * @package SphinxHolidays
 * @since   1.1.0
 */
if (!defined('BOOTSTRAP')) { exit('Access denied'); }

use Tygh\Addons\SphinxHolidays\Services\CartService;
use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;
use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Helpers\RequestCoerce;

    $cartService = new CartService();

    if (($rateLimited = $cartService->checkRateLimit()) !== null) {
        return $rateLimited;
    }

    $bookingData = TypeCoerce::toStringMap($_REQUEST);
    $offer_id = RequestCoerce::string($_REQUEST, 'offer_id');
    $circuit_id = RequestCoerce::int($_REQUEST, 'circuit_id');

    if (empty($offer_id) || empty($circuit_id)) {
        fn_set_notification('E', __('error'),
            __('sphinx_holidays.invalid_offer', ['[default]' => 'Invalid offer.']));
        return [CONTROLLER_STATUS_REDIRECT, 'sphinx_booking.circuit_search'];
    }

    if (($duplicate = $cartService->checkDuplicate($offer_id)) !== null) {
        return $duplicate;
    }

    // Optional: customize circuit with selected services
    $selected_services = RequestCoerce::list($_REQUEST, 'services');
    $customized = null;
    if (!empty($selected_services)) {
        try {
            $customized = Container::getApi()->customizeCircuit([
                'offer_id' => $offer_id,
                'service_codes' => $selected_services,
            ]);
        } catch (\Throwable $e) {
            fn_log_event('general', 'runtime', ['message' => 'Sphinx circuit customize failed: ' . $e->getMessage()]);
        }
    }

    // Pricing: the provider's price only — the quote the booking form stored
    // on the server, or its customized price with the chosen services. Never
    // the form's total_price (a guest could change it, and it already had
    // commission added, which was then added a second time here).
    $storedQuote = (new \Tygh\Addons\SphinxHolidays\Services\CircuitQuoteStore())->get($offer_id, $circuit_id, time());
    $customizedMap = TypeCoerce::toStringMap($customized);
    $customizedPricing = TypeCoerce::toStringMap($customizedMap['pricing'] ?? null);
    if ($storedQuote === null || ($selected_services !== [] && $customizedPricing === [])) {
        fn_set_notification('W', __('warning'),
            __('sphinx_holidays.offer_unavailable', ['[default]' => 'This offer is no longer available.']));
        return [CONTROLLER_STATUS_REDIRECT, 'sphinx_booking.circuit_booking_form?' . http_build_query([
            'circuit_id' => $circuit_id,
            'departure_date' => RequestCoerce::string($_REQUEST, 'departure_date'),
        ])];
    }
    $total_price   = TypeCoerce::toFloat($customizedPricing['selling_price'] ?? $storedQuote['selling_price']);
    $basePrice     = TypeCoerce::toFloat($customizedPricing['supplier_price'] ?? $total_price);
    $priceCurrency = TypeCoerce::toString($customizedPricing['currency'] ?? ($storedQuote['currency'] !== '' ? $storedQuote['currency'] : ConfigProvider::getDefaultCurrency()));
    // The raw price for the checkout re-check (PreOrderPriceVerifier).
    $cartService->rememberVerifiedPrice($offer_id, $total_price);
    $total_price   = $cartService->applyCommission($total_price);

    if ($total_price <= 0) {
        fn_set_notification('E', __('error'),
            __('sphinx_holidays.price_unavailable', ['[default]' => 'Price not available.']));
        return [CONTROLLER_STATUS_REDIRECT, 'sphinx_booking.circuit_search'];
    }

    // The circuit's own buyable Sphinx product (sphinx_circuits.product_id,
    // populated by the add_circuit_products cron); the request's product_id
    // only counts when it is that same product.
    $product_id = $cartService->resolveCircuitProductId($circuit_id, RequestCoerce::int($_REQUEST, 'product_id'));
    if (empty($product_id)) {
        fn_set_notification('E', __('error'),
            __('sphinx_holidays.product_not_found', ['[default]' => 'Circuit product not found.']));
        return [CONTROLLER_STATUS_REDIRECT, 'sphinx_booking.circuit_search'];
    }

    // Guest validation
    $departure_date = RequestCoerce::string($_REQUEST, 'departure_date');
    $parsed_guests = $cartService->parseGuests(RequestCoerce::stringMap($_REQUEST, 'guests'), $departure_date);
    if ($parsed_guests === false) {
        return [CONTROLLER_STATUS_REDIRECT, 'sphinx_booking.circuit_booking_form?' . http_build_query([
            'circuit_id' => $circuit_id, 'departure_date' => $departure_date,
        ])];
    }

    // Price guard: the circuit quote is priced for the child ages the search
    // ran with — a DOB implying a different age at departure books a
    // wrong-price seat. Same gate as hotel/package add-to-cart.
    $ageMismatch = \Tygh\Addons\TravelCore\Services\GuestDataService::findChildAgeMismatch(
        TypeCoerce::toStringMap($parsed_guests['guests_data'] ?? [])
    );
    if ($ageMismatch !== null) {
        fn_set_notification('E', __('error'), __('travel_core.child_age_mismatch', [
            '[guest]' => $ageMismatch['name'],
            '[declared]' => $ageMismatch['declared_age'],
            '[actual]' => $ageMismatch['age_at_checkin'],
            '[default]' => 'The child [guest] will be [actual] years old at check-in, but the offer was priced for age [declared]. The search was re-run with the correct ages — please choose an offer again.',
        ]));
        return [CONTROLLER_STATUS_REDIRECT, 'sphinx_booking.circuit_search'];
    }

    // Extract type-specific fields
    $contact         = RequestCoerce::stringMap($_REQUEST, 'contact');
    $title           = RequestCoerce::string($_REQUEST, 'title');
    $departure_name  = RequestCoerce::string($_REQUEST, 'departure_name');
    $transport_type  = RequestCoerce::string($_REQUEST, 'transport_type');
    $duration_days   = RequestCoerce::int($_REQUEST, 'duration_days');
    $duration_nights = RequestCoerce::int($_REQUEST, 'duration_nights');
    $adults          = RequestCoerce::int($_REQUEST, 'adults', 2);
    $children        = RequestCoerce::int($_REQUEST, 'children');
    $children_ages   = RequestCoerce::string($_REQUEST, 'children_ages');

    $customizedRooms = TypeCoerce::toRowList($customizedMap['rooms'] ?? null);
    $roomsJsonRaw = json_decode(RequestCoerce::string($_REQUEST, 'rooms_json', '[]'), true);
    $roomsFallback = is_array($roomsJsonRaw) ? $roomsJsonRaw : [];
    $rooms = !empty($customizedRooms) ? $customizedRooms : TypeCoerce::toRowList($roomsFallback);
    $rooms_data = [];
    foreach ($rooms as $room) {
        $childrenAgesArr = TypeCoerce::toList($room['children_ages'] ?? []);
        $rooms_data[] = [
            'room_id'      => TypeCoerce::toString($room['code'] ?? ''),
            'room_name'    => TypeCoerce::toString($room['name'] ?? ''),
            'adults'       => TypeCoerce::toInt($room['adults'] ?? $adults),
            'children'     => count($childrenAgesArr),
            'childrenAges' => $childrenAgesArr,
        ];
    }
    if (empty($rooms_data)) {
        $rooms_data = [['room_id' => '', 'room_name' => 'Standard', 'adults' => $adults, 'children' => $children]];
    }

    // The trip ends on its last day, departure + days - 1 (departure + days
    // was a day after the return). Days and nights are the supplier's own,
    // apart (9 days / 6 nights when legs run overnight); without days, after
    // the nights. A same-day trip returns on its departure date.
    $tripLength = $duration_days > 0 ? $duration_days - 1 : $duration_nights;
    $check_out = !empty($departure_date)
        ? date('Y-m-d', (int) strtotime($departure_date . " + {$tripLength} days"))
        : '';

    // Build + persist booking record
    $booking_record = $cartService->buildBaseBookingRecord(
        $product_id, (string) $circuit_id, $offer_id, $title,
        $parsed_guests, $contact, $basePrice, $total_price, $priceCurrency,
        is_array($customized) ? TypeCoerce::toStringMap($customized) : $bookingData
    );
    $booking_record += [
        'room_id'       => $rooms_data[0]['room_id'],
        'room_type'     => 'circuit',
        'board_id'      => $transport_type,
        'check_in'      => $departure_date,
        'check_out'     => $check_out,
        'nights'        => $duration_nights,
        'adults'        => $adults,
        'children'      => $children,
        'children_ages' => $children_ages,
        'num_rooms'     => count($rooms_data),
        'rooms_data'    => json_encode($rooms_data, JSON_UNESCAPED_UNICODE),
    ];

    // Durable pricing breakdown for the admin booking view (same capture as
    // the hotel flow; api_response gets overwritten at confirmation).
    if ($customizedPricing !== []) {
        $booking_record['pricing_json'] = json_encode($customizedPricing, JSON_UNESCAPED_UNICODE);
    }

    $booking_id = $cartService->upsertBooking(
        $booking_record, (string) $circuit_id, $departure_date, $check_out, TypeCoerce::toString($parsed_guests['holder_name'])
    );

    $product_extra = [
        'travel_booking' => true, 'sphinx_booking' => true,
        'travel_booking_id' => $booking_id, 'travel_provider' => 'sphinx',
        'booking_type' => 'circuit',
        // What the checkout re-quote needs (PreOrderPriceVerifier): circuits have no verify endpoint.
        'departure_id' => RequestCoerce::int($_REQUEST, 'departure_id'),
        'service_codes' => $selected_services,
        'hotel_id' => (string) $circuit_id, 'hotel_name' => $title, 'offer_id' => $offer_id,
        'room_id' => $rooms_data[0]['room_id'], 'room_name' => $rooms_data[0]['room_name'],
        'board_id' => $transport_type, 'board_name' => ucfirst($transport_type),
        'transport_type' => $transport_type,
        // Shown on the order: the quote's meal plan and the departure city,
        // from the server-side quote (the form only for display text).
        'meal_name' => $storedQuote['meal_type'],
        'departure_name' => $storedQuote['departure_name'] !== '' ? $storedQuote['departure_name'] : $departure_name,
        'check_in' => $departure_date, 'check_out' => $check_out, 'nights' => $duration_nights,
        'duration_days' => $duration_days,
        'adults' => $adults, 'children' => $children, 'children_ages' => $children_ages,
        'num_rooms' => count($rooms_data), 'rooms_data' => $rooms_data,
        'guest_names' => $parsed_guests['guest_list'], 'holder_name' => $parsed_guests['holder_name'],
        'guests_data' => json_encode($parsed_guests['guests_data'], JSON_UNESCAPED_UNICODE),
        'contact_email' => TypeCoerce::toString($contact['email'] ?? ''), 'contact_phone' => TypeCoerce::toString($contact['phone'] ?? ''),
        'total_price' => $total_price, 'currency' => $priceCurrency,
    ];

    return $cartService->addToCartAndRedirect(
        $product_id, $total_price, $priceCurrency, $product_extra,
        TypeCoerce::toString(__('sphinx_holidays.circuit_added_to_cart', ['[default]' => 'Circuit booking added to cart.']))
    );
