<?php
declare(strict_types=1);
/**
 * Sphinx Booking Controller — Add to Cart Mode (Hotels)
 *
 * Verifies the offer, creates records in sphinx_bookings and travel_bookings,
 * then adds the product to the CS-Cart cart.
 *
 * @package SphinxHolidays
 * @since   1.0.0
 */
if (!defined('BOOTSTRAP')) { exit('Access denied'); }

use Tygh\Addons\SphinxHolidays\Services\CartService;
use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;
use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Helpers\RequestCoerce;

    $cartService = new CartService();

    // Rate limit + input
    if (($rateLimited = $cartService->checkRateLimit()) !== null) {
        return $rateLimited;
    }

    $bookingData = TypeCoerce::toStringMap($_REQUEST);
    $offer_id = RequestCoerce::string($_REQUEST, 'offer_id');
    $hotel_id = RequestCoerce::string($_REQUEST, 'hotel_id');
    $product_id = RequestCoerce::int($_REQUEST, 'product_id');

    if (empty($offer_id)) {
        fn_set_notification('E', __('error'),
            __('sphinx_holidays.invalid_offer', ['[default]' => 'Invalid offer.']));
        return [CONTROLLER_STATUS_REDIRECT, 'index.index'];
    }

    // Verify offer price with Sphinx API
    try {
        $verifyResult = Container::getApi()->verifyHotelOffer($offer_id);
    } catch (\Throwable $e) {
        fn_log_event('general', 'runtime', ['message' => 'Sphinx add_to_cart verify failed: ' . $e->getMessage()]);
        $verifyResult = null;
    }

    if (!\Tygh\Addons\SphinxHolidays\Helpers\OfferAvailability::isVerifiedAvailable(
        $verifyResult,
        ConfigProvider::shouldRequireImmediateAvailability(),
    )) {
        fn_log_event('general', 'runtime', [
            'message' => 'Sphinx add_to_cart: offer rejected by verify',
            'offer_id' => $offer_id,
            'verify_response' => substr((string) json_encode($verifyResult, JSON_UNESCAPED_UNICODE), 0, 1000),
        ]);
        fn_set_notification('E', __('error'),
            __('sphinx_holidays.offer_no_longer_available', ['[default]' => 'This offer is no longer available.']));
        return [CONTROLLER_STATUS_REDIRECT, 'index.index'];
    }

    // The gate above rejects null/empty responses — narrow for the reads below,
    // unwrapping the API envelope so the offer-field reads (hotel/room/board,
    // dates, occupancy) see the offer payload itself.
    $verifyResult = TypeCoerce::toStringMap(
        \Tygh\Addons\SphinxHolidays\Helpers\OfferAvailability::unwrapOffer($verifyResult)
    );

    // Price with commission
    $basePrice = \Tygh\Addons\SphinxHolidays\Helpers\OfferAvailability::extractPrice($verifyResult);
    $total_price = $cartService->applyCommission($basePrice);

    if ($total_price <= 0) {
        fn_set_notification('E', __('error'),
            __('sphinx_holidays.price_unavailable', ['[default]' => 'Price not available.']));
        return [CONTROLLER_STATUS_REDIRECT, 'index.index'];
    }

    // Cache the verified raw price in the session for the pre_place_order
    // "Silent Sync": while the entry is younger than
    // ConfigProvider::getPreorderCacheTtl(), the checkout verifier trusts
    // this verify and skips its own API round-trip (novoton's pattern —
    // availability was confirmed by the gate above).
    $price_cache = &$_SESSION['sphinx_price_cache'];
    if (!is_array($price_cache)) {
        $price_cache = [];
    }
    $price_cache[md5($offer_id)] = [
        'api_price_raw' => $basePrice,
        'timestamp'     => time(),
    ];
    unset($price_cache);

    // Resolve product
    $product_id = $cartService->resolveProductId($hotel_id, $product_id);
    if (empty($product_id)) {
        fn_set_notification('E', __('error'),
            __('sphinx_holidays.product_not_found', ['[default]' => 'Hotel product not found.']));
        return [CONTROLLER_STATUS_REDIRECT, 'index.index'];
    }

    // Guest validation
    $check_in = TypeCoerce::toString($verifyResult['check_in'] ?? $bookingData['check_in'] ?? '');
    $parsed_guests = $cartService->parseGuests(RequestCoerce::stringMap($_REQUEST, 'guests'), $check_in);
    if ($parsed_guests === false) {
        return [CONTROLLER_STATUS_REDIRECT, 'sphinx_booking.booking_form?' . http_build_query([
            'offer_id' => $offer_id, 'hotel_id' => $hotel_id, 'product_id' => $product_id,
        ])];
    }

    // Price guard: the offer price is fixed for the ages the search ran with.
    // A child DOB implying a DIFFERENT age at check-in would book a
    // wrong-price stay (or be rejected by the Sphinx API), so block and
    // re-run the search with the DOB-corrected ages instead.
    $guestsMap = TypeCoerce::toStringMap($parsed_guests['guests_data'] ?? []);
    $ageMismatch = \Tygh\Addons\TravelCore\Services\GuestDataService::findChildAgeMismatch($guestsMap);
    if ($ageMismatch !== null) {
        fn_set_notification('E', __('error'), __('travel_core.child_age_mismatch', [
            '[guest]' => $ageMismatch['name'],
            '[declared]' => $ageMismatch['declared_age'],
            '[actual]' => $ageMismatch['age_at_checkin'],
            '[default]' => 'The child [guest] will be [actual] years old at check-in, but the offer was priced for age [declared]. The search was re-run with the correct ages — please choose an offer again.',
        ]));

        $correctedAges = [];
        foreach ($guestsMap as $mismatchGuest) {
            if (is_array($mismatchGuest) && TypeCoerce::toString($mismatchGuest['type'] ?? '') === 'child') {
                $correctedAges[] = TypeCoerce::toInt(
                    $mismatchGuest['age_at_checkin'] ?? $mismatchGuest['declared_age'] ?? $mismatchGuest['age'] ?? 0
                );
            }
        }

        return [CONTROLLER_STATUS_REDIRECT, 'sphinx_booking.search?' . http_build_query([
            'hotel_id' => $hotel_id,
            'product_id' => $product_id,
            'check_in' => $check_in,
            'check_out' => TypeCoerce::toString($verifyResult['check_out'] ?? $bookingData['check_out'] ?? ''),
            'adults' => TypeCoerce::toInt($verifyResult['adults'] ?? $bookingData['adults'] ?? 2),
            'children' => count($correctedAges),
            'children_ages' => implode(',', $correctedAges),
            'refresh' => 1,
        ])];
    }

    // Extract offer details
    $contact     = RequestCoerce::stringMap($_REQUEST, 'contact');
    $hotelName   = TypeCoerce::toString($verifyResult['hotel_name'] ?? '');
    // Room/board names: verify response first; when it omits them (shape
    // drift) fall back to the booking form's hidden fields — the names the
    // customer actually saw. Display-only: price/ids stay verify-sourced.
    // An empty stored room_name blanks the Travel Bookings grid Room column
    // AND the order-details room line, so the fallback matters.
    $roomName    = TypeCoerce::toString($verifyResult['room_name'] ?? $verifyResult['room_type'] ?? '');
    if ($roomName === '') {
        $roomName = RequestCoerce::string($_REQUEST, 'room_name');
    }
    $boardName   = TypeCoerce::toString($verifyResult['board_name'] ?? $verifyResult['board_type'] ?? '');
    if ($boardName === '') {
        $boardName = RequestCoerce::string($_REQUEST, 'board_name');
    }
    $boardId     = TypeCoerce::toString($verifyResult['board_code'] ?? $boardName);
    $roomId      = TypeCoerce::toString($verifyResult['room_code'] ?? $roomName);
    $check_out   = TypeCoerce::toString($verifyResult['check_out'] ?? $bookingData['check_out'] ?? '');
    $adults      = TypeCoerce::toInt($verifyResult['adults'] ?? $bookingData['adults'] ?? 2);
    $children    = TypeCoerce::toInt($verifyResult['children'] ?? $bookingData['children'] ?? 0);
    $currency    = ConfigProvider::getDefaultCurrency();

    $nights = 0;
    if (!empty($check_in) && !empty($check_out)) {
        $ci_ts = strtotime($check_in);
        $co_ts = strtotime($check_out);
        if ($ci_ts !== false && $co_ts !== false) {
            $nights = (int) round(($co_ts - $ci_ts) / 86400);
        }
    }

    $all_child_ages = [];
    foreach (TypeCoerce::toRowList($parsed_guests['guests_data']) as $guest) {
        if (TypeCoerce::toString($guest['type'] ?? '') === 'child' && isset($guest['age'])) {
            $all_child_ages[] = TypeCoerce::toInt($guest['age']);
        }
    }
    $children_ages = !empty($all_child_ages) ? implode(',', $all_child_ages) : TypeCoerce::toString($bookingData['children_ages'] ?? '');

    $rooms_data = [[
        'room_id' => $roomId, 'room_name' => $roomName, 'room_type_display' => $roomName,
        'board_id' => $boardId, 'board_name' => $boardName,
        'adults' => $adults, 'children' => $children, 'childrenAges' => $all_child_ages,
        'price' => $total_price,
    ]];

    // Build + persist booking record
    $booking_record = $cartService->buildBaseBookingRecord(
        $product_id, $hotel_id, $offer_id, $hotelName,
        $parsed_guests, $contact, $basePrice, $total_price, $currency, $verifyResult
    );
    $booking_record += [
        'room_id'       => $roomId,
        'room_type'     => $roomName,
        'board_id'      => $boardId,
        'check_in'      => $check_in,
        'check_out'     => $check_out,
        'nights'        => $nights,
        'adults'        => $adults,
        'children'      => $children,
        'children_ages' => $children_ages,
        'num_rooms'     => 1,
        'rooms_data'    => json_encode($rooms_data, JSON_UNESCAPED_UNICODE),
    ];

    // Payment & cancellation terms from the verify response: raw JSON on the
    // booking row (the orders sync later overwrites with authoritative data)
    // and formatted display lines in the cart extras for order pages.
    // Kept as the API sent them: the live shape is an OBJECT
    // {is_loaded, is_free, text, rules}, which TypeCoerce::toList() flattened
    // to [true, false, null, [...]] — every cart/email terms line came out
    // empty and the stored JSON lost its keys.
    $payment_terms_raw = is_array($verifyResult['payment_terms'] ?? null) ? $verifyResult['payment_terms'] : [];
    $cancellation_fees_raw = is_array($verifyResult['cancellation_fees'] ?? null) ? $verifyResult['cancellation_fees'] : [];
    if ($payment_terms_raw !== []) {
        $booking_record['payment_terms_json'] = json_encode($payment_terms_raw, JSON_UNESCAPED_UNICODE);
    }
    if ($cancellation_fees_raw !== []) {
        $booking_record['cancellation_fees_json'] = json_encode($cancellation_fees_raw, JSON_UNESCAPED_UNICODE);
    }

    // Full pricing breakdown (marketing/discount/selling/commission/supplier/
    // taxes/fees) — durable copy for the admin booking view. api_response is
    // NOT durable: it is overwritten with the book response at confirmation.
    $pricing_raw = TypeCoerce::toStringMap($verifyResult['pricing'] ?? null);
    if ($pricing_raw !== []) {
        $booking_record['pricing_json'] = json_encode($pricing_raw, JSON_UNESCAPED_UNICODE);
    }

    $booking_id = $cartService->upsertBooking(
        $booking_record, $hotel_id, $check_in, $check_out, TypeCoerce::toString($parsed_guests['holder_name'])
    );

    // Assemble cart extras
    $product_extra = [
        'travel_booking' => true, 'sphinx_booking' => true,
        'travel_booking_id' => $booking_id, 'travel_provider' => 'sphinx',
        'hotel_id' => $hotel_id, 'hotel_name' => $hotelName, 'offer_id' => $offer_id,
        'room_id' => $roomId, 'room_name' => $roomName, 'room_type_display' => $roomName,
        'board_id' => $boardId, 'board_name' => $boardName,
        'check_in' => $check_in, 'check_out' => $check_out, 'nights' => $nights,
        'adults' => $adults, 'children' => $children, 'children_ages' => $children_ages,
        'num_rooms' => 1, 'rooms_data' => $rooms_data,
        'guest_names' => $parsed_guests['guest_list'], 'holder_name' => $parsed_guests['holder_name'],
        'guests_data' => json_encode($parsed_guests['guests_data'], JSON_UNESCAPED_UNICODE),
        'contact_email' => TypeCoerce::toString($contact['email'] ?? ''), 'contact_phone' => TypeCoerce::toString($contact['phone'] ?? ''),
        'total_price' => $total_price, 'currency' => $currency,
        'payment_terms' => \Tygh\Addons\SphinxHolidays\Services\TermsFormatter::lines($payment_terms_raw),
        'cancellation_fees' => \Tygh\Addons\SphinxHolidays\Services\TermsFormatter::lines($cancellation_fees_raw),
        // Raw terms + confirmation, so edit mode rebuilds the same timeline
        // and badge without re-verifying the offer.
        'terms_raw' => (string) json_encode(['payment' => $payment_terms_raw, 'cancellation' => $cancellation_fees_raw], JSON_UNESCAPED_UNICODE),
        'confirmation' => TypeCoerce::toString($verifyResult['confirmation'] ?? ''),
    ];

    return $cartService->addToCartAndRedirect(
        $product_id, $total_price, $currency, $product_extra,
        TypeCoerce::toString(__('sphinx_holidays.added_to_cart', ['[default]' => 'Hotel booking added to cart.']))
    );
