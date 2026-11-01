<?php
declare(strict_types=1);
/**
 * Novoton Booking Controller — Add to Cart Mode
 * Extracted from novoton_booking.php for maintainability.
 * Included by the main controller when $mode == "add_to_cart".
 */
if (!defined('BOOTSTRAP')) { exit('Access denied'); }

use Tygh\Tygh;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Addons\NovotonHolidays\Services\Container;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\GuestDataNormalizer;

    // --- Security: Rate limiting ---
    $security = _nvt_get_security_service();
    // CS-Cart's session is an ArrayAccess container object; a plain (non-reference)
    // local binds the same object handle, so offset reads below operate on the
    // live session exactly as direct `Tygh::$app['session'][...]` access would.
    $session = Tygh::$app['session'];
    if (!is_array($session) && !$session instanceof \ArrayAccess) {
        $session = [];
    }
    $auth = TypeCoerce::toStringMap($session['auth'] ?? null);
    $session_id = (is_object($session) && method_exists($session, 'getID'))
        ? TypeCoerce::toString($session->getID())
        : '';
    $rate_limit_id = !empty($auth['user_id']) ? TypeCoerce::toString($auth['user_id']) : $session_id;
    if (!$security->checkBookingRateLimit($rate_limit_id)) {
        $security->logSecurityEvent('rate_limit_exceeded', ['mode' => 'add_to_cart', 'identifier' => $rate_limit_id]);
        fn_set_notification('E', __('error'), 'Too many booking requests. Please try again later.');
        return [CONTROLLER_STATUS_REDIRECT, 'index.index'];
    }

    $bookingData = TypeCoerce::toStringMap($_REQUEST);

    // Normalize room_id: restore + signs lost by URL decoding
    if (!empty($bookingData['room_id'])) {
        $bookingData['room_id'] = fn_novoton_holidays_normalize_room_code(TypeCoerce::toString($bookingData['room_id']));
    }

    // --- Security: Validate booking data via SecurityService ---
    $validation = $security->validateBookingData($bookingData);
    if (!$validation['valid']) {
        $security->logSecurityEvent('booking_validation_failed', [
            'mode' => 'add_to_cart',
            'errors' => $validation['errors'],
            'hotel_id' => $bdHotelId ?? ''
        ]);
        fn_set_notification('E', __('error'), __('novoton_holidays.invalid_booking_data'));
        return [CONTROLLER_STATUS_REDIRECT, 'index.index'];
    }

    // Validate room_id is also present (not checked by SecurityService)
    if (empty($bookingData['room_id'])) {
        fn_set_notification('E', __('error'), __('novoton_holidays.invalid_booking_data'));
        return [CONTROLLER_STATUS_REDIRECT, 'index.index'];
    }

    // The hotel's own active Novoton product; the form's product_id only
    // counts when it is that same product (never a fallback).
    $bdHotelId = TypeCoerce::toString($bookingData['hotel_id'] ?? '');
    $product_id = \Tygh\Addons\NovotonHolidays\Services\HotelCartProduct::resolve($bdHotelId, TypeCoerce::toInt($bookingData['product_id'] ?? 0));
    if ($product_id <= 0) {
        fn_set_notification('E', __('error'), __('novoton_holidays.product_not_found'));
        return [CONTROLLER_STATUS_REDIRECT, 'index.index'];
    }

    // Get hotel info using repository
    $hotel_info = _nvt_hotel_repo()->findById($bdHotelId);

    if (empty($hotel_info)) {
        // Hotel not in local DB — auto-create from API (same pattern as OffersUpdateCommand)
        fn_log_event('general', 'runtime', [
            'message' => 'Novoton add_to_cart: hotel_id not in local DB, auto-creating',
            'hotel_id' => $bdHotelId,
            'product_id' => $product_id,
        ]);

        $hotel_data = [
            'hotel_id' => $bdHotelId,
            'product_id' => (int) $product_id,
            'hotel_name' => '',
            'city' => '',
            'region' => '',
            'country' => '',
            'hotel_type' => '',
            'has_room_price' => 'N',
            'hotel_list_synced_at' => date('Y-m-d H:i:s'),
        ];

        try {
            $api_for_hotel = fn_novoton_holidays_get_api();
            if ($api_for_hotel !== null) {
                $api_hotel_info = $api_for_hotel->hotels()->getHotelInfo($bdHotelId);
                // getHotelInfo() always returns a SimpleXMLElement; read fields directly.
                $hotel_data['hotel_name'] = (string) ($api_hotel_info->Hotel ?? '');
                $hotel_data['city']       = (string) ($api_hotel_info->City ?? '');
                $hotel_data['region']     = (string) ($api_hotel_info->Region ?? '');
                $hotel_data['country']    = (string) ($api_hotel_info->Country ?? '');
                $hotel_data['hotel_type'] = (string) ($api_hotel_info->HotelType ?? $api_hotel_info->Stars ?? '');
            }
        } catch (\Throwable $e) {
            fn_log_event('general', 'runtime', [
                'message' => 'Novoton add_to_cart: getHotelInfo failed, using stub',
                'hotel_id' => $bdHotelId,
                'error' => $e->getMessage(),
            ]);
        }

        _nvt_hotel_repo()->upsert($hotel_data);
        $hotel_info = _nvt_hotel_repo()->findById($bdHotelId);
    }

    // Process guest information — sanitize via SecurityService
    $rawGuests = TypeCoerce::toStringMap($bookingData['guests'] ?? null);
    $guests = $rawGuests !== [] ? $security->sanitizeGuestData($rawGuests) : [];
    $contact = TypeCoerce::toStringMap($bookingData['contact'] ?? []);
    // Parse guests (no full DOB validation needed at add_to_cart, that happens in update_booking)
    $parsed_guests = \Tygh\Addons\TravelCore\Services\GuestDataService::parseAndValidateGuests($guests, '', 'novoton');
    $guests_data = TypeCoerce::toStringMap($parsed_guests['guests_data'] ?? []);
    $guest_names = $parsed_guests['guest_names'] ?? [];
    $guest_list = TypeCoerce::toString($parsed_guests['guest_list'] ?? '');
    $holder_name = TypeCoerce::toString($parsed_guests['holder_name'] ?? '');

    // The rooms the reservation will book (rooms_data, each room's own room,
    // board, package, adults and child ages), checked against the posted
    // guests. Every one of them is priced below and the customer pays their
    // sum: the posted total_price and per-room prices are never charged
    // (audit C2/H4). null = inconsistent rooms_data/guests, nothing is priced.
    $booked_rooms = \Tygh\Addons\NovotonHolidays\Services\BookedRooms::fromRequest($bookingData, $guests_data);

    // Children ages: every room's, in room order (for the redirect and log
    // below the guests' own ages when rooms_data could not be read)
    $all_child_ages = [];
    if ($booked_rooms !== null) {
        foreach ($booked_rooms as $booked_room) {
            array_push($all_child_ages, ...$booked_room['childrenAges']);
        }
    } else {
        foreach ($guests_data as $guest) {
            if (is_array($guest) && ($guest['type'] ?? '') === 'child' && isset($guest['age'])) {
                $all_child_ages[] = TypeCoerce::toInt($guest['age']);
            }
        }
    }
    $children_ages = !empty($all_child_ages) || $booked_rooms !== null
        ? implode(',', $all_child_ages)
        : TypeCoerce::toString($bookingData['children_ages'] ?? '');

    // Package of the clicked card; the price below is pinned to it (BookedOffer)
    $package_name = $booked_package = TypeCoerce::toString($bookingData['package_name'] ?? '');
    if ($package_name === '' && $bdHotelId !== '') {
        // V3: Get first package from novoton_hotel_packages table
        $packageRepo = Container::getInstance()->hotelPackageRepository();
        $first_pkg = $packageRepo->getFirstPackageName($bdHotelId);
        if (!empty($first_pkg)) {
            $package_name = $first_pkg;
        }
    }

    // The price the customer saw on the form: only compared with the quote
    // below ("No Surprises" notice, admin alert), never charged
    $customer_visible_price = TypeCoerce::toFloat($bookingData['total_price'] ?? 0);

    $check_in = TypeCoerce::toString($bookingData['check_in'] ?? '');
    $check_out = TypeCoerce::toString($bookingData['check_out'] ?? '');

    // Always call the API to get terms and the price (Option A: fetch terms at
    // checkout): room_price once per room, with that room's adults, its own
    // children ages and its package; the booked offer of each is the same
    // room + board AND package — never the first <Price> of the answer.
    $api = fn_novoton_holidays_get_api();
    $quote = $api !== null && $booked_rooms !== null
        ? (new \Tygh\Addons\NovotonHolidays\Services\RoomsQuote($api->pricing(), $api->availability()))->price(
            $bdHotelId,
            $check_in,
            $check_out,
            $booked_rooms,
            ['source' => 'add_to_cart', 'hotel_id' => $bdHotelId],
        )
        : null;

    // A80: Server-side price validation - safety net
    // If rooms_data does not match the guests, or any room has no price (or
    // no offer of its room/board), abort the booking: it is never charged for
    // fewer rooms than it books (e.g. a room that does not accept certain
    // child ages).
    if ($quote === null) {
        fn_log_event('general', 'runtime', [
            'message' => $booked_rooms === null
                ? 'Novoton add_to_cart: PRICE VERIFICATION FAILED - rooms_data is inconsistent with the request or the guests'
                : 'Novoton add_to_cart: PRICE VERIFICATION FAILED - API returned no price for a room/board of the booking',
            'hotel_id' => $bdHotelId,
            'room_id' => TypeCoerce::toString($bookingData['room_id']),
            'package_name' => $booked_package,
            'children_ages' => $all_child_ages,
            'adults' => TypeCoerce::toInt($bookingData['adults'] ?? 2),
            'num_rooms' => $booked_rooms !== null ? count($booked_rooms) : TypeCoerce::toInt($bookingData['num_rooms'] ?? 1),
        ]);

        fn_set_notification('E', __('error'), __('novoton_holidays.price_verification_failed', [
            '[default]' => 'Price verification failed. The booking cannot proceed. Please go back and try refreshing the price, or contact support.'
        ]));

        // Back to the booking form of the same offer, room and all
        $return_url = \Tygh\Addons\NovotonHolidays\Services\BookingFormLink::back($bookingData, $product_id, $children_ages, $booked_rooms, $customer_visible_price);

        return [CONTROLLER_STATUS_REDIRECT, $return_url];
    }

    $priced_rooms = $quote['rooms'];
    $first_room = $priced_rooms[0];
    // Room 1's offer: its terms (payment/deposit, cancellation, notes) are the booking's
    $offerMatch = $quote['offer'];

    // Initialize terms
    $terms_of_payment = '';
    $terms_of_cancellation = '';
    $remark = '';
    $important = '';
    if ($first_room['package_name'] !== '') {
        // Book the package the price comes from: the clicked one, or, when
        // the answer no longer offers it, the one BookedOffer priced instead.
        $package_name = $first_room['package_name'];
    }
    $rawPrice = $quote['base'];
    $base_price = $rawPrice; // API price before commission, every room
    $api_price = $quote['total']; // with commission, every room

    // The charge: the server's quote of every booked room
    $total_price = $api_price;

    // Price floor alert: the form showed less than the real-time room_price
    // API (stale priceinfo data, calculation bugs or cache issues). The
    // customer is charged the API price; the admin is told. Children's ages
    // always re-priced the form, so those bookings never raised it.
    if ($customer_visible_price > 0 && $customer_visible_price < $api_price && empty($all_child_ages)) {
        $price_diff = round($api_price - $customer_visible_price, 2);
        fn_log_event('general', 'runtime', [
            'message' => 'Novoton PRICE FLOOR: form price below real-time API room_price — using API price',
            'hotel_id' => $bdHotelId,
            'room_id' => $first_room['room_id'],
            'num_rooms' => count($priced_rooms),
            'form_price' => $customer_visible_price,
            'api_price' => $api_price,
            'api_price_raw' => $rawPrice,
            'difference' => $price_diff,
        ]);

        // Send email alert to admin with price discrepancy details
        fn_novoton_holidays_send_price_alert_email([
            'hotel_id'      => $bdHotelId,
            'hotel_name'    => TypeCoerce::toString($hotel_info['hotel_name'] ?? ''),
            'room_id'       => $first_room['room_id'],
            'board_id'      => $first_room['board_id'],
            'check_in'      => $check_in,
            'check_out'     => $check_out,
            'adults'        => $first_room['adults'],
            'children'      => $first_room['children'],
            'children_ages' => $children_ages,
            'form_price'    => $customer_visible_price,
            'api_price'     => $api_price,
            'api_price_raw' => $rawPrice,
            'difference'    => $price_diff,
        ]);
    }

    // "No Surprises" policy: detect and communicate price changes to the user.
    // Uses Price Tolerance: changes < threshold (default 1%) are silent.
    if ($customer_visible_price > 0) {
        $detector = Container::getInstance()->priceChangeDetector();
        $changeInfo = $detector->analyse(
            $customer_visible_price,
            $total_price,
            ConfigProvider::getApiCurrency(),
            'add_to_cart',
            [
                'hotel_name' => TypeCoerce::toString($hotel_info['hotel_name'] ?? ''),
                'hotel_id'   => $bdHotelId,
                'room_id'    => $first_room['room_id'],
            ]
        );

        if ($changeInfo['significant']) {
            // Store alert in session — template will render it on the cart page
            $detector->storeAlert($changeInfo);

            // User-facing toast; amounts (API currency) written exactly as the cart line shows them
            if ($changeInfo['direction'] === 'increase') {
                fn_set_notification('W', __('novoton_holidays.price_change'),
                    __('novoton_holidays.price_updated_from_to', [
                        '[old_price]' => fn_novoton_holidays_format_api_amount_for_shopper($customer_visible_price),
                        '[new_price]' => fn_novoton_holidays_format_api_amount_for_shopper($total_price),
                    ])
                );
            } else {
                // Price decrease — a "win" for the customer
                fn_set_notification('N', __('novoton_holidays.price_dropped'),
                    __('novoton_holidays.price_dropped_to', [
                        '[new_price]' => fn_novoton_holidays_format_api_amount_for_shopper($total_price),
                    ])
                );
            }
        }
    }

    // Terms, deposit and notes of the booked offer: every offer carries its
    // own, so the first <TermsOfPayment> of the answer may be another package's.
    [
        'terms_of_payment' => $terms_of_payment,
        'terms_of_cancellation' => $terms_of_cancellation,
        'remark' => $remark,
        'important' => $important,
    ] = \Tygh\Addons\NovotonHolidays\Services\RoomOfferRows::terms($quote['answer'], $offerMatch);

    if ($total_price <= 0) {
        fn_set_notification('E', __('error'), __('novoton_holidays.price_unavailable'));
        return [CONTROLLER_STATUS_REDIRECT, 'products.view?product_id=' . $product_id];
    }

    // Calculate nights using DateTime::diff (DST-safe)
    $nights = TypeCoerce::toInt($bookingData['nights'] ?? 0);
    if ($nights <= 0) {
        try {
            $d1 = new \DateTime($check_in);
            $d2 = new \DateTime($check_out);
            $nights = (int) $d1->diff($d2)->days;
        } catch (\Exception $e) {
            $nights = 7;
        }
    }

    // Room 1's board (no board asked for: the cheapest board it was priced on)
    $board_id = $first_room['board_id'];

    // rooms_data as priced: the reservation (BookingRoomAssembler) and the
    // booking records (BookingRecordBuilder) are built from it, so each room
    // carries the server's price, never the posted one (novoton-money-9).
    // Display names come from the ids, on request from the hotel's quota, not from the form.
    $rooms_data = [];
    $room_ids_for_db = [];
    $room_types_for_db = [];
    $total_adults = 0;
    $total_children = 0;
    foreach ($priced_rooms as $priced_room) {
        $room_display = fn_novoton_holidays_format_room_type($priced_room['room_id']);
        $valid_ages = TypeCoerce::toStringList($priced_room['childrenAges']);
        if ($priced_room['package_name'] === '') {
            // Priced on any package: book the booking's, as the reservation always did
            $priced_room['package_name'] = $package_name;
        }
        $rooms_data[] = $priced_room + [
            'room_name' => $room_display,
            'room_type_display' => $room_display,
            'board_name' => fn_novoton_holidays_format_board_name($priced_room['board_id'], $priced_room['ext_board_id']),
            'children_ages_str' => $valid_ages !== []
                ? implode(', ', $valid_ages) . ' ' . TypeCoerce::toString(__('novoton_holidays.years_old'))
                : '',
        ];
        $room_ids_for_db[] = $priced_room['room_id'];
        $room_types_for_db[] = $room_display;
        $total_adults += $priced_room['adults'];
        $total_children += $priced_room['children'];
    }
    $num_rooms = count($rooms_data);
    $room_id_str = $first_room['room_id'];

    // Check if similar booking already exists (same hotel, dates, holder, no order yet)
    // This prevents duplicates from form resubmissions
    $bookingRepo = _nvt_booking_repo();
    $existing = $bookingRepo->findExisting(
        $bdHotelId,
        $check_in,
        $check_out,
        $holder_name,
        1 // within last 1 hour
    );
    $existing_booking_id = $existing !== null ? TypeCoerce::toInt($existing['booking_id'] ?? 0) : 0;

    // room_id / room_type columns list every room (multi-room bookings too)
    $room_id_column = implode(', ', $room_ids_for_db);
    $room_type_column = implode(', ', $room_types_for_db);

    if ($existing_booking_id > 0) {
        // Update existing booking instead of creating new one
        $booking_record = [
            'room_id' => $room_id_column,
            'room_type' => $room_type_column,
            'adults' => $total_adults,
            'children' => $total_children,
            'rooms_data' => json_encode($rooms_data, JSON_UNESCAPED_UNICODE),
            'guest_name' => $guest_list,
            'guests_data' => (new GuestDataNormalizer())->toJson($guests_data),
            'base_price' => $base_price,
            'total_price' => $total_price,
            'guest_email' => TypeCoerce::toString($contact['email'] ?? ''),
            'api_request' => json_encode([
                'guests' => $guests_data,
                'contact' => $contact,
                'rooms_data' => $rooms_data
            ])
        ];
        // Update user_id if now logged in
        $authNow = TypeCoerce::toStringMap($session['auth'] ?? []);
        if (!empty($authNow['user_id'])) {
            $booking_record['user_id'] = TypeCoerce::toInt($authNow['user_id']);
        }
        // A79: Use BookingRepository for update
        _nvt_booking_repo()->update($existing_booking_id, $booking_record);
        $booking_id = $existing_booking_id;
    } else {
        // Get current user and session info
        $authNow = TypeCoerce::toStringMap($session['auth'] ?? []);
        $user_id = TypeCoerce::toInt($authNow['user_id'] ?? 0);
        $session_id = session_id();

        // Create new booking record in database
        $booking_record = [
            'order_id' => 0, // Will be updated when order is placed
            'user_id' => $user_id,
            'session_id' => $session_id,
            'product_id' => $product_id,
            'hotel_id' => $bdHotelId,
            'hotel_name' => TypeCoerce::toString($hotel_info['hotel_name'] ?? ''),
            'package_name' => $package_name,
            'room_id' => $room_id_column,
            'room_type' => $room_type_column,
            'board_id' => $board_id,
            'check_in' => $check_in,
            'check_out' => $check_out,
            'nights' => $nights,
            'adults' => $total_adults,
            'children' => $total_children,
            'children_ages' => $children_ages,
            'num_rooms' => $num_rooms,
            'rooms_data' => json_encode($rooms_data, JSON_UNESCAPED_UNICODE),
            'guest_name' => $guest_list,
            'holder_name' => $holder_name,
            'guest_email' => '',  // Will be set from order at checkout
            'guest_phone' => TypeCoerce::toString($contact['phone'] ?? ''),
            'guests_data' => (new GuestDataNormalizer())->toJson($guests_data),
            'base_price' => $base_price,
            'total_price' => $total_price,
            'currency' => ConfigProvider::getApiCurrency(),
            'status' => 'pending',
            'api_request' => json_encode([
                'guests' => $guests_data,
                'contact' => $contact,
                'rooms_data' => $rooms_data
            ])
        ];

        // A79: Use BookingRepository for create
        $booking_id = _nvt_booking_repo()->create($booking_record);
    }

    // travel_bookings sync is handled by BookingRepository::create()/update() above

    // Add to cart with booking details
    $product = [
        'product_id' => $product_id,
        'amount' => 1,
        'extra' => [
            'travel_booking' => true,
            'novoton_booking' => true,
            'novoton_booking_id' => $booking_id,
            'hotel_id' => $bdHotelId,
            'hotel_name' => TypeCoerce::toString($hotel_info['hotel_name'] ?? ''),
            'hotel_city' => TypeCoerce::toString($hotel_info['city'] ?? ''),
            'hotel_region' => TypeCoerce::toString($hotel_info['region'] ?? ''),
            'hotel_country' => TypeCoerce::toString($hotel_info['country'] ?? ''),
            'package_name' => $package_name,
            'room_id' => $room_id_str,
            'room_name' => str_replace(['%2b', '%2B'], '+', $room_id_str),
            'room_type_display' => fn_novoton_holidays_format_room_type($room_id_str),
            'board_id' => $board_id,
            'ext_board_id' => $first_room['ext_board_id'],
            'board_name' => fn_novoton_holidays_format_board_name($board_id, $first_room['ext_board_id']),
            'check_in' => $check_in,
            'check_out' => $check_out,
            'nights' => $nights,
            // Every room's occupancy (one room: that room's, so the line's
            // top-level tuple is the priced room — PreOrderPriceVerifier)
            'adults' => $total_adults,
            'children' => $total_children,
            'children_ages' => $children_ages,
            'num_rooms' => $num_rooms,
            'rooms_data' => $rooms_data,
            'guest_names' => $guest_list,
            'holder_name' => $holder_name,
            'guests_data' => (new GuestDataNormalizer())->toJson($guests_data),
            'contact_email' => TypeCoerce::toString($contact['email'] ?? ''),
            'contact_phone' => TypeCoerce::toString($contact['phone'] ?? ''),
            'terms_of_payment' => fn_novoton_holidays_format_payment_terms($terms_of_payment),
            'terms_of_cancellation' => fn_novoton_holidays_format_cancellation_terms($terms_of_cancellation, $check_in),
            'terms_of_payment_raw' => $terms_of_payment,
            'terms_of_cancellation_raw' => $terms_of_cancellation,
            'remark' => $remark,
            'important' => $important,
            'total_price' => $total_price,
            'currency' => ConfigProvider::getApiCurrency(),
        ]
    ];

    // Set the price directly (override product price)
    $product['price'] = $total_price;

    // Add to cart — by-ref binds into $_SESSION, the authoritative session
    // store (see travel_core SessionAccessor). Never assign to
    // Tygh::$app['session']: it is a frozen Pimple service and offsetSet
    // throws FrozenServiceException. Narrowing happens THROUGH the
    // references, so the initialised arrays land in the live session.
    $cart = &$_SESSION['cart'];
    $auth = &$_SESSION['auth'];
    if (!is_array($cart)) {
        $cart = [];
    }
    if (!is_array($auth)) {
        $auth = [];
    }

    // Initialize cart if needed
    if (empty($cart)) {
        fn_clear_cart($cart);
    }
    // Ensure the products bag is an array before writing the booking item below
    // (fn_clear_cart() initialises it; this also narrows the reference type).
    $cart['products'] = is_array($cart['products'] ?? null) ? $cart['products'] : [];

    // Generate unique cart_id for this booking
    $cart_id = TypeCoerce::toString(fn_generate_cart_id($product_id, $product['extra']));

    // Convert price from API currency (EUR) to CS-Cart primary currency for cart storage.
    // CS-Cart internally stores all cart prices in the primary currency and applies
    // display-currency coefficients when rendering. Storing EUR directly would cause
    // the coefficient to be applied on top, resulting in a wrong price on the cart page.
    $primaryCurrency = defined('CART_PRIMARY_CURRENCY') ? TypeCoerce::toString(CART_PRIMARY_CURRENCY) : 'EUR';
    $cart_price = _nvt_currency_service()->convertFromApiCurrency($total_price, $primaryCurrency);

    // Add product to cart (with a chosen deposit, the line charges only that)
    $cart['products'][$cart_id] = \Tygh\Addons\NovotonHolidays\Services\DepositLine::fromRequest([
        'product_id' => $product_id,
        'amount' => 1,
        'price' => $cart_price,
        'base_price' => $cart_price,
        'original_price' => $cart_price,
        'extra' => $product['extra'],
        'stored_price' => 'Y'  // Important: use our calculated price
    ], $terms_of_payment, $bookingData);

    // Recalculate cart
    fn_calculate_cart_content($cart, $auth, 'S', true, 'F', true);
    fn_save_cart_content($cart, $auth['user_id'] ?? 0);

    // Cache the verified API price of every room in session for
    // pre_place_order "Silent Sync". If a cached price is fresh enough
    // (< TTL), the pre-order check can skip the API call and make checkout
    // feel instant. The verifier's own rooms and key recipe (package included).
    if ($api_price > 0) {
        // Ensure the cache bag is an array before writing the keyed entries
        // ($_SESSION is the authoritative session store — see SessionAccessor).
        $price_cache = &$_SESSION[\Tygh\Addons\NovotonHolidays\Services\PreOrderPriceVerifier::PRICE_CACHE_SESSION_KEY];
        if (!is_array($price_cache)) {
            $price_cache = [];
        }
        // Keyed on the PRICED rooms (their own ages), never on the posted
        // guests: a guest age that is not the priced one must miss the cache.
        $priced_extra = $product['extra'];
        unset($priced_extra['guests_data']);
        $room_lines = \Tygh\Addons\NovotonHolidays\Services\PreOrderPriceVerifier::roomLines($priced_extra);
        foreach ($room_lines as $room_index => $room_line) {
            // One line: the whole booking is that room
            $cached_room = count($room_lines) > 1 ? ($priced_rooms[$room_index] ?? null) : null;
            $price_cache[\Tygh\Addons\NovotonHolidays\Services\PreOrderPriceVerifier::priceCacheKey($room_line)] = [
                'api_price'     => $cached_room !== null ? $cached_room['price'] : $api_price,
                'api_price_raw' => $cached_room !== null ? $cached_room['price_raw'] : $base_price,
                'form_price'    => $cached_room !== null ? $cached_room['price'] : $total_price,
                'timestamp'     => time(),
            ];
        }
        unset($price_cache);
    }

    fn_set_notification('N', __('notice'), __('novoton_holidays.added_to_cart'));

    return [CONTROLLER_STATUS_REDIRECT, fn_travel_core_after_add_to_cart_url('novoton_holidays')];
