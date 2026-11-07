<?php
declare(strict_types=1);
/**
 * Sphinx Booking Controller — Package Add to Cart Mode
 *
 * Verifies the package, customizes it with the optional services chosen
 * (when any), then creates the booking record and adds it to the cart.
 * The record keeps what packages/book needs (PackageBookRequest): the
 * pre-commission selling price and its currency, the chosen services, the
 * offer's rooms, and each guest's birth date and gender.
 *
 * Flow: verify → customize (optional) → add to cart → book at order placement
 *
 * @package SphinxHolidays
 * @since   1.2.0
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

    $offer_id = RequestCoerce::string($_REQUEST, 'offer_id');
    $hotel_id = RequestCoerce::string($_REQUEST, 'hotel_id');

    if (empty($offer_id)) {
        fn_set_notification('E', __('error'),
            __('sphinx_holidays.invalid_offer', ['[default]' => 'Invalid offer.']));
        return [CONTROLLER_STATUS_REDIRECT, 'sphinx_booking.package_search'];
    }

    if (($duplicate = $cartService->checkDuplicate($offer_id)) !== null) {
        return $duplicate;
    }

    // The package as the provider prices it NOW (the booking form's same
    // verify): price, currency, hotel and dates never come from the form.
    try {
        $verified = TypeCoerce::toStringMap(Container::getApi()->verifyPackageOffer($offer_id));
    } catch (\Throwable $e) {
        fn_log_event('general', 'runtime', ['message' => 'Sphinx package add_to_cart verify failed: ' . $e->getMessage()]);
        $verified = [];
    }

    // Then the optional services chosen, customized onto the verified offer
    // (the doc's order). Only the offer's own optional services count: a
    // mandatory one is in the price already, an unknown code is the form's.
    $optionalCodes = [];
    foreach (TypeCoerce::toRowList($verified['additional_services'] ?? []) as $service) {
        if (TypeCoerce::toString($service['type'] ?? '') !== 'mandatory') {
            $optionalCodes[] = TypeCoerce::toString($service['code'] ?? '');
        }
    }
    $selected_services = array_values(array_intersect(
        array_map(static fn ($code): string => TypeCoerce::toString($code), RequestCoerce::list($_REQUEST, 'services')),
        $optionalCodes,
    ));
    $customized = null;
    if ($selected_services !== [] && $verified !== []) {
        try {
            $customized = Container::getApi()->customizePackage([
                'offer_id' => $offer_id,
                'service_codes' => $selected_services,
            ]);
        } catch (\Throwable $e) {
            fn_log_event('general', 'runtime', ['message' => 'Sphinx package customize failed: ' . $e->getMessage()]);
        }
    }

    $verifiedPricing = TypeCoerce::toStringMap($verified['pricing'] ?? null);
    $verifiedHotel = TypeCoerce::toStringMap($verified['hotel'] ?? null);
    $customizedMap = TypeCoerce::toStringMap($customized);
    $customizedPricing = TypeCoerce::toStringMap($customizedMap['pricing'] ?? null);
    if ($verifiedPricing === [] || ($selected_services !== [] && $customizedPricing === [])) {
        fn_set_notification('W', __('warning'),
            __('sphinx_holidays.offer_unavailable', ['[default]' => 'This offer is no longer available.']));
        return [CONTROLLER_STATUS_REDIRECT, 'sphinx_booking.package_search'];
    }
    // The package's own hotel: the request cannot pair it with another hotel's product.
    $verifiedHotelId = TypeCoerce::toString($verifiedHotel['id'] ?? '');
    if ($verifiedHotelId === '' || ($hotel_id !== '' && $hotel_id !== $verifiedHotelId)) {
        fn_set_notification('E', __('error'),
            __('sphinx_holidays.invalid_offer', ['[default]' => 'Invalid offer.']));
        return [CONTROLLER_STATUS_REDIRECT, 'sphinx_booking.package_search'];
    }
    $hotel_id = $verifiedHotelId;

    // Pricing: the provider's figure (customized with the chosen services,
    // else verified), commission applied exactly once. The form's
    // total_price already carried commission, which used to be added again.
    // base_price is that pre-commission selling price, as for hotels: the
    // checkout re-check reads and corrects it, and packages/book must send
    // it (it was supplier_price, which Sphinx does not take).
    $bookPricing   = $customizedPricing !== [] ? $customizedPricing : $verifiedPricing;
    $total_price   = TypeCoerce::toFloat($bookPricing['selling_price'] ?? 0);
    $basePrice     = $total_price;
    $priceCurrency = TypeCoerce::toString($bookPricing['currency'] ?? ConfigProvider::getDefaultCurrency());
    // The raw price for the checkout re-check (PreOrderPriceVerifier).
    $cartService->rememberVerifiedPrice($offer_id, $total_price);
    $total_price   = $cartService->applyCommission($total_price);

    if ($total_price <= 0) {
        fn_set_notification('E', __('error'),
            __('sphinx_holidays.price_unavailable', ['[default]' => 'Price not available.']));
        return [CONTROLLER_STATUS_REDIRECT, 'sphinx_booking.package_search'];
    }

    // The package's hotel's own buyable Sphinx product; the request's
    // product_id only counts when it is that same product.
    $product_id = $cartService->resolveProductId($hotel_id, RequestCoerce::int($_REQUEST, 'product_id'));
    if (empty($product_id)) {
        fn_set_notification('E', __('error'),
            __('sphinx_holidays.product_not_found', ['[default]' => 'Package product not found.']));
        return [CONTROLLER_STATUS_REDIRECT, 'sphinx_booking.package_search'];
    }

    // Guest validation
    // The dates the provider priced (the form's only as a fallback).
    $check_in  = TypeCoerce::toString($verifiedHotel['check_in'] ?? '') ?: RequestCoerce::string($_REQUEST, 'check_in');
    $check_out = TypeCoerce::toString($verifiedHotel['check_out'] ?? '') ?: RequestCoerce::string($_REQUEST, 'check_out');
    $parsed_guests = $cartService->parseGuests(RequestCoerce::stringMap($_REQUEST, 'guests'), $check_in);
    if ($parsed_guests === false) {
        return [CONTROLLER_STATUS_REDIRECT, 'sphinx_booking.package_booking_form?' . http_build_query([
            'offer_id' => $offer_id,
        ])];
    }
    // packages/book wants each traveller's gender (m/f) and each child's
    // real birth date (Sphinx checks it against the priced age); the form
    // asks both, and a request without them is refused here, not at Sphinx
    // after payment.
    foreach (TypeCoerce::toRowList(array_values(TypeCoerce::toStringMap($parsed_guests['guests_data'] ?? []))) as $guest) {
        $missingGender = !in_array(TypeCoerce::toString($guest['gender'] ?? ''), ['m', 'f'], true);
        $missingChildBirth = TypeCoerce::toString($guest['type'] ?? '') === 'child' && TypeCoerce::toString($guest['birthday'] ?? '') === '';
        if ($missingGender || $missingChildBirth) {
            fn_set_notification('E', __('error'), __('sphinx_holidays.traveller_details_required', [
                '[default]' => "Please choose each traveller's gender, and fill in each child's date of birth.",
            ]));

            return [CONTROLLER_STATUS_REDIRECT, 'sphinx_booking.package_booking_form?' . http_build_query([
                'offer_id' => $offer_id,
            ])];
        }
    }

    // Price guard: the package offer is priced for the ages the search ran
    // with — a child DOB implying a different age at check-in books a
    // wrong-price package. Block and send the customer back to package
    // search to re-run with the correct ages.
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
        return [CONTROLLER_STATUS_REDIRECT, 'sphinx_booking.package_search'];
    }

    // Extract type-specific
    $contact       = RequestCoerce::stringMap($_REQUEST, 'contact');
    $hotelName     = TypeCoerce::toString($verifiedHotel['name'] ?? '') ?: RequestCoerce::string($_REQUEST, 'hotel_name');
    $roomName      = RequestCoerce::string($_REQUEST, 'room_name');
    $boardName     = RequestCoerce::string($_REQUEST, 'board_name');
    $transport_type = RequestCoerce::string($_REQUEST, 'transport_type');
    $nights        = RequestCoerce::int($_REQUEST, 'nights');
    $adults        = RequestCoerce::int($_REQUEST, 'adults', 2);
    $children      = RequestCoerce::int($_REQUEST, 'children');
    $children_ages = RequestCoerce::string($_REQUEST, 'children_ages');
    $num_rooms     = max(1, RequestCoerce::int($_REQUEST, 'num_rooms', 1));

    if (empty($nights) && !empty($check_in) && !empty($check_out)) {
        $ci_ts = strtotime($check_in);
        $co_ts = strtotime($check_out);
        if ($ci_ts !== false && $co_ts !== false) {
            $nights = (int) round(($co_ts - $ci_ts) / 86400);
        }
    }

    // The offer's own rooms, as Sphinx verified them: their codes are what
    // packages/book names (room_code), never the form's copy.
    $rooms_data = [];
    foreach (TypeCoerce::toRowList($verifiedHotel['rooms'] ?? []) as $room) {
        $code = TypeCoerce::toString($room['code'] ?? '');
        $childAges = TypeCoerce::toIntList($room['children_ages'] ?? []);
        $rooms_data[] = $room + [
            'room_id' => $code,
            'room_code' => $code,
            'room_name' => TypeCoerce::toString($room['name'] ?? ''),
            'children' => count($childAges),
        ];
    }
    if ($rooms_data === []) {
        $rooms_data = [['room_id' => '', 'room_name' => $roomName, 'adults' => $adults, 'children' => $children]];
    }

    $service_codes = $selected_services;

    // Build + persist booking record. Its offer snapshot is Sphinx's answer
    // (customized, else verified) — it was the form's request when nothing
    // was customized.
    $booking_record = $cartService->buildBaseBookingRecord(
        $product_id, $hotel_id, $offer_id, $hotelName,
        $parsed_guests, $contact, $basePrice, $total_price, $priceCurrency,
        $customizedMap !== [] ? $customizedMap : $verified
    );
    $firstRoom = TypeCoerce::toStringMap($rooms_data[0]);
    $booking_record += [
        'room_id'       => TypeCoerce::toString($firstRoom['room_id'] ?? ''),
        'room_type'     => 'package',
        'board_id'      => $boardName,
        'check_in'      => $check_in,
        'check_out'     => $check_out,
        'nights'        => $nights,
        'adults'        => $adults,
        'children'      => $children,
        'children_ages' => $children_ages,
        'num_rooms'     => $num_rooms,
        'rooms_data'    => json_encode($rooms_data, JSON_UNESCAPED_UNICODE),
    ];

    // Durable pricing breakdown for the admin booking view (same capture as
    // the hotel flow; api_response gets overwritten at confirmation), with
    // the services chosen: what packages/book sends, on a retry too.
    $booking_record['pricing_json'] = json_encode($bookPricing + ['chosen_services' => $service_codes], JSON_UNESCAPED_UNICODE);

    $booking_id = $cartService->upsertBooking(
        $booking_record, $hotel_id, $check_in, $check_out, TypeCoerce::toString($parsed_guests['holder_name'])
    );

    $product_extra = [
        'travel_booking' => true, 'sphinx_booking' => true,
        'travel_booking_id' => $booking_id, 'travel_provider' => 'sphinx',
        'booking_type' => 'package',
        'hotel_id' => $hotel_id, 'hotel_name' => $hotelName, 'offer_id' => $offer_id,
        'room_id' => TypeCoerce::toString($firstRoom['room_id'] ?? ''), 'room_name' => $roomName,
        'board_id' => $boardName, 'board_name' => $boardName,
        'check_in' => $check_in, 'check_out' => $check_out, 'nights' => $nights,
        'adults' => $adults, 'children' => $children, 'children_ages' => $children_ages,
        'num_rooms' => $num_rooms, 'rooms_data' => $rooms_data,
        'transport_type' => $transport_type,
        'guest_names' => $parsed_guests['guest_list'], 'holder_name' => $parsed_guests['holder_name'],
        'guests_data' => json_encode($parsed_guests['guests_data'], JSON_UNESCAPED_UNICODE),
        'contact_email' => TypeCoerce::toString($contact['email'] ?? ''), 'contact_phone' => TypeCoerce::toString($contact['phone'] ?? ''),
        'total_price' => $total_price, 'currency' => $priceCurrency,
        'additional_services' => $service_codes,
    ];

    return $cartService->addToCartAndRedirect(
        $product_id, $total_price, $priceCurrency, $product_extra,
        TypeCoerce::toString(__('sphinx_holidays.package_added_to_cart', ['[default]' => 'Package booking added to cart.']))
    );
