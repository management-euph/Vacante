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

    // Get product ID from hotel ID
    $prefix = ConfigProvider::getFirstProductCodePrefix();
    $bdHotelId = TypeCoerce::toString($bookingData['hotel_id'] ?? '');
    $product_code = $prefix . $bdHotelId;

    $product_id = TypeCoerce::toInt(db_get_field(
        "SELECT product_id FROM ?:products WHERE product_code = ?s",
        $product_code
    ));

    if ($product_id <= 0) {
        // Try the product_id from form
        $product_id = TypeCoerce::toInt($bookingData['product_id'] ?? 0);
    }

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

    // Get children ages from guests_data (more reliable than form hidden field)
    $all_child_ages = [];
    foreach ($guests_data as $guest) {
        if (!is_array($guest)) {
            continue;
        }
        if (($guest['type'] ?? '') === 'child' && isset($guest['age'])) {
            $all_child_ages[] = TypeCoerce::toInt($guest['age']);
        }
    }
    $children_ages = !empty($all_child_ages)
        ? implode(',', $all_child_ages)
        : TypeCoerce::toString($bookingData['children_ages'] ?? '');

    // Get package name
    $package_name = TypeCoerce::toString($bookingData['package_name'] ?? '');
    if ($package_name === '' && $bdHotelId !== '') {
        // V3: Get first package from novoton_hotel_packages table
        $packageRepo = Container::getInstance()->hotelPackageRepository();
        $first_pkg = $packageRepo->getFirstPackageName($bdHotelId);
        if (!empty($first_pkg)) {
            $package_name = $first_pkg;
        }
    }

    // Get total price (from form or recalculate)
    $total_price = TypeCoerce::toFloat($bookingData['total_price'] ?? 0);

    // Always call API to get terms and verify price (Option A: fetch terms at checkout)
    // IMPORTANT: Include children ages for correct price calculation
    $priceParams = [
        'hotel_id' => $bdHotelId,
        'room_id' => TypeCoerce::toString($bookingData['room_id']),
        'board_id' => TypeCoerce::toString($bookingData['board_id'] ?? ''),
        'star_rating' => '',
        'check_in' => TypeCoerce::toString($bookingData['check_in'] ?? ''),
        'check_out' => TypeCoerce::toString($bookingData['check_out'] ?? ''),
        'adults' => TypeCoerce::toInt($bookingData['adults'] ?? 2),
        'children' => $all_child_ages  // Include children ages from guest form
    ];

    $api = fn_novoton_holidays_get_api();
    $priceData = $api !== null ? $api->pricing()->getRoomPrice($priceParams) : null;

    // A80: Server-side price validation - safety net
    // If we have children and API returns no data, abort booking
    // This prevents bookings with incorrect prices when room doesn't accept certain child ages
    if (!($priceData instanceof \SimpleXMLElement) || !isset($priceData->Price)) {
        fn_log_event('general', 'runtime', [
            'message' => 'Novoton add_to_cart: PRICE VERIFICATION FAILED - API returned no price',
            'hotel_id' => $bdHotelId,
            'room_id' => TypeCoerce::toString($bookingData['room_id']),
            'children_ages' => $all_child_ages,
            'adults' => TypeCoerce::toInt($bookingData['adults'] ?? 2)
        ]);

        fn_set_notification('E', __('error'), __('novoton_holidays.price_verification_failed', [
            '[default]' => 'Price verification failed. The booking cannot proceed. Please go back and try refreshing the price, or contact support.'
        ]));

        // Build return URL to booking form with all parameters
        $return_params = [
            'hotel_id' => $bdHotelId,
            'product_id' => $product_id,
            'check_in' => TypeCoerce::toString($bookingData['check_in'] ?? ''),
            'check_out' => TypeCoerce::toString($bookingData['check_out'] ?? ''),
            'nights' => TypeCoerce::toString($bookingData['nights'] ?? ''),
            'adults' => TypeCoerce::toInt($bookingData['adults'] ?? 2),
            'children' => TypeCoerce::toInt($bookingData['children'] ?? 0),
            'children_ages' => $children_ages,
            'rooms' => TypeCoerce::toInt($bookingData['num_rooms'] ?? 1)
        ];
        $return_url = 'novoton_booking.booking_form?' . http_build_query($return_params);

        return [CONTROLLER_STATUS_REDIRECT, $return_url];
    }

    // Initialize terms
    $terms_of_payment = '';
    $terms_of_cancellation = '';
    $remark = '';
    $important = '';
    $base_price = 0; // API price before commission

    // Update price if we got one from API
    if (isset($priceData->Price)) {
        $room_id_for_match  = rawurldecode(TypeCoerce::toString($bookingData['room_id']));
        $board_id_for_match = TypeCoerce::toString($bookingData['board_id'] ?? '');
        $minPriceMatch = fn_novoton_min_price_from_xml($priceData, $room_id_for_match, $board_id_for_match);
        $rawPrice = $minPriceMatch !== null ? $minPriceMatch['price'] : (float)((string)$priceData->Price);
        $base_price = $rawPrice;
        $api_price = fn_novoton_holidays_get_api()->pricing()->applyCommission($rawPrice);

        // Remember the price the customer saw on the form before any correction
        $customer_visible_price = $total_price;

        // ALWAYS use API price when children are involved (ages affect pricing)
        if (!empty($all_child_ages)) {
            $total_price = $api_price;
        }

        // Price floor: final price must NEVER be lower than real-time room_price API
        // Protects against stale priceinfo data, calculation bugs, or cache issues
        if ($total_price <= 0 || $total_price < $api_price) {
            if ($total_price > 0 && $total_price < $api_price) {
                $price_diff = round($api_price - $total_price, 2);
                fn_log_event('general', 'runtime', [
                    'message' => 'Novoton PRICE FLOOR: form price below real-time API room_price — using API price',
                    'hotel_id' => $bdHotelId,
                    'room_id' => TypeCoerce::toString($bookingData['room_id']),
                    'form_price' => $total_price,
                    'api_price' => $api_price,
                    'api_price_raw' => $rawPrice,
                    'difference' => $price_diff,
                ]);

                // Send email alert to admin with price discrepancy details
                fn_novoton_holidays_send_price_alert_email([
                    'hotel_id'      => $bdHotelId,
                    'hotel_name'    => TypeCoerce::toString($hotel_info['hotel_name'] ?? ''),
                    'room_id'       => TypeCoerce::toString($bookingData['room_id']),
                    'board_id'      => TypeCoerce::toString($bookingData['board_id'] ?? ''),
                    'check_in'      => TypeCoerce::toString($bookingData['check_in'] ?? ''),
                    'check_out'     => TypeCoerce::toString($bookingData['check_out'] ?? ''),
                    'adults'        => TypeCoerce::toInt($bookingData['adults'] ?? 2),
                    'children'      => TypeCoerce::toInt($bookingData['children'] ?? 0),
                    'children_ages' => $children_ages,
                    'form_price'    => $total_price,
                    'api_price'     => $api_price,
                    'api_price_raw' => $rawPrice,
                    'difference'    => $price_diff,
                ]);
            }
            $total_price = $api_price;
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
                    'room_id'    => TypeCoerce::toString($bookingData['room_id']),
                ]
            );

            if ($changeInfo['significant']) {
                // Store alert in session — template will render it on the cart page
                $detector->storeAlert($changeInfo);

                // User-facing notification via CS-Cart toast
                if ($changeInfo['direction'] === 'increase') {
                    fn_set_notification('W', __('novoton_holidays.price_change'),
                        __('novoton_holidays.price_updated_from_to', [
                            '[old_price]' => fn_format_price($customer_visible_price),
                            '[new_price]' => fn_format_price($total_price),
                        ])
                    );
                } else {
                    // Price decrease — a "win" for the customer
                    fn_set_notification('N', __('novoton_holidays.price_dropped'),
                        __('novoton_holidays.price_dropped_to', [
                            '[new_price]' => fn_format_price($total_price),
                        ])
                    );
                }
            }
        }
    }

    // Extract terms from API response using xpath (more reliable than direct property access)
    $termsPayment = $priceData->xpath('//TermsOfPayment');
    $termsCancellation = $priceData->xpath('//TermsOfCancellation');

    if (!empty($termsPayment[0])) {
        $terms_of_payment = (string) $termsPayment[0]->asXML();
    }
    if (!empty($termsCancellation[0])) {
        $terms_of_cancellation = (string) $termsCancellation[0]->asXML();
    }

    // Extract remark and important info
    if (isset($priceData->remark)) {
        $remark = (string)$priceData->remark;
    }
    if (isset($priceData->Important)) {
        $important = (string)$priceData->Important;
    }

    if ($total_price <= 0) {
        fn_set_notification('E', __('error'), __('novoton_holidays.price_unavailable'));
        return [CONTROLLER_STATUS_REDIRECT, 'products.view?product_id=' . $product_id];
    }

    // Calculate nights using DateTime::diff (DST-safe)
    $nights = TypeCoerce::toInt($bookingData['nights'] ?? 0);
    $check_in = TypeCoerce::toString($bookingData['check_in'] ?? '');
    $check_out = TypeCoerce::toString($bookingData['check_out'] ?? '');
    if ($nights <= 0) {
        try {
            $d1 = new \DateTime($check_in);
            $d2 = new \DateTime($check_out);
            $nights = (int) $d1->diff($d2)->days;
        } catch (\Exception $e) {
            $nights = 7;
        }
    }

    // Format board name for display
    $board_id = TypeCoerce::toString($bookingData['board_id'] ?? 'BB');
    if ($board_id === '') {
        $board_id = 'BB';
    }
    $board_name = fn_novoton_holidays_format_board_name($board_id);

    // Parse rooms_data
    $num_rooms = TypeCoerce::toInt($bookingData['num_rooms'] ?? 1);
    $rooms_data = [];
    $rawRoomsData = $bookingData['rooms_data'] ?? null;
    if (!empty($rawRoomsData)) {
        if (is_string($rawRoomsData)) {
            $decoded = json_decode($rawRoomsData, true);
            $rooms_data = is_array($decoded) ? $decoded : [];
        } elseif (is_array($rawRoomsData)) {
            $rooms_data = $rawRoomsData;
        }
        // Normalize room_id and room_name in each room (restore + lost by URL decoding)
        foreach ($rooms_data as &$rm) {
            if (!is_array($rm)) {
                continue;
            }
            if (!empty($rm['room_id'])) {
                $rm['room_id'] = fn_novoton_holidays_normalize_room_code(TypeCoerce::toString($rm['room_id']));
            }
            if (!empty($rm['room_name'])) {
                $rm['room_name'] = fn_novoton_holidays_normalize_room_code(TypeCoerce::toString($rm['room_name']));
            }
        }
        unset($rm);
    }

    // If rooms_data is still empty, create default with complete info
    $room_id_str = TypeCoerce::toString($bookingData['room_id']);
    if (empty($rooms_data)) {
        $children_ages_arr = [];
        $rawChildrenAges = $bookingData['children_ages'] ?? null;
        if (is_string($rawChildrenAges) && $rawChildrenAges !== '') {
            $children_ages_arr = array_map(
                static fn (string $v): int => (int) $v,
                array_filter(explode(',', $rawChildrenAges), static fn ($v) => $v !== ''),
            );
        } elseif (is_array($rawChildrenAges)) {
            $children_ages_arr = array_map(
                static fn ($v): int => TypeCoerce::toInt($v),
                $rawChildrenAges,
            );
        }
        $rooms_data = [
            [
                'room_id' => $room_id_str,
                'room_name' => fn_novoton_holidays_format_room_type($room_id_str),
                'room_type_display' => fn_novoton_holidays_format_room_type($room_id_str),
                'board_id' => $board_id,
                'board_name' => $board_name,
                'adults' => TypeCoerce::toInt($bookingData['adults'] ?? 2),
                'children' => TypeCoerce::toInt($bookingData['children'] ?? 0),
                'childrenAges' => $children_ages_arr,
                'price' => TypeCoerce::toFloat($bookingData['total_price'] ?? 0)
            ]
        ];
        $num_rooms = 1;
    }

    // Add children_ages_str and room_type_display to each room for Smarty display
    // Also sync children ages from guest form back to rooms_data
    foreach ($rooms_data as $room_idx => &$room) {
        if (!is_array($room)) {
            continue;
        }
        $room_num = TypeCoerce::toInt($room_idx) + 1;

        // Collect children ages from guests_data for this room
        $child_ages_for_room = [];
        foreach ($guests_data as $guest) {
            if (!is_array($guest)) {
                continue;
            }
            if (TypeCoerce::toInt($guest['room'] ?? 0) === $room_num && ($guest['type'] ?? '') === 'child') {
                $child_ages_for_room[] = TypeCoerce::toInt($guest['age'] ?? 0);
            }
        }

        // If we have ages from guest form, update rooms_data
        if (!empty($child_ages_for_room)) {
            $room['childrenAges'] = $child_ages_for_room;
        }

        if (!empty($room['childrenAges']) && is_array($room['childrenAges'])) {
            // Filter out null values and format
            $valid_ages = TypeCoerce::toStringList(array_filter($room['childrenAges'], static fn ($age) => $age !== null && $age !== ''));
            $room['children_ages_str'] = !empty($valid_ages) ? implode(', ', $valid_ages) . ' ' . TypeCoerce::toString(__('novoton_holidays.years_old')) : '';
        } else {
            $room['children_ages_str'] = '';
        }
        // Ensure room_type_display is set (translated room name)
        if (empty($room['room_type_display']) && !empty($room['room_id'])) {
            $room['room_type_display'] = fn_novoton_holidays_format_room_type(TypeCoerce::toString($room['room_id']));
            $room['room_name'] = fn_novoton_holidays_format_room_type(TypeCoerce::toString($room['room_id']));
        }
    }
    unset($room);

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

    // Extract room_id and room_type from rooms_data for database columns
    // This ensures the columns are populated even for multi-room bookings
    $room_ids_for_db = [];
    $room_types_for_db = [];
    $total_adults = 0;
    $total_children = 0;

    foreach ($rooms_data as $room) {
        if (!is_array($room)) {
            continue;
        }
        if (!empty($room['room_id'])) {
            $room_ids_for_db[] = TypeCoerce::toString($room['room_id']);
        }
        if (!empty($room['room_name'])) {
            $room_types_for_db[] = TypeCoerce::toString($room['room_name']);
        } elseif (!empty($room['room_type_display'])) {
            $room_types_for_db[] = TypeCoerce::toString($room['room_type_display']);
        } elseif (!empty($room['room_id'])) {
            $room_types_for_db[] = fn_novoton_holidays_format_room_type(TypeCoerce::toString($room['room_id']));
        }
        $total_adults += TypeCoerce::toInt($room['adults'] ?? 0);
        $total_children += TypeCoerce::toInt($room['children'] ?? 0);
    }

    // Fallback to bookingData if rooms_data didn't have room_id
    if (empty($room_ids_for_db)) {
        $room_ids_for_db[] = $room_id_str;
        $room_types_for_db[] = fn_novoton_holidays_format_room_type($room_id_str);
    }

    // Use totals from rooms_data if available, otherwise from bookingData
    if ($total_adults === 0) {
        $total_adults = TypeCoerce::toInt($bookingData['adults'] ?? 2);
    }
    if ($total_children === 0) {
        $total_children = TypeCoerce::toInt($bookingData['children'] ?? 0);
    }

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
            'board_name' => $board_name,
            'check_in' => $check_in,
            'check_out' => $check_out,
            'nights' => $nights,
            'adults' => TypeCoerce::toInt($bookingData['adults'] ?? 2),
            'children' => TypeCoerce::toInt($bookingData['children'] ?? 0),
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

    // Add product to cart
    $cart['products'][$cart_id] = [
        'product_id' => $product_id,
        'amount' => 1,
        'price' => $cart_price,
        'base_price' => $cart_price,
        'original_price' => $cart_price,
        'extra' => $product['extra'],
        'stored_price' => 'Y'  // Important: use our calculated price
    ];

    // Recalculate cart
    fn_calculate_cart_content($cart, $auth, 'S', true, 'F', true);
    fn_save_cart_content($cart, $auth['user_id'] ?? 0);

    // Cache verified API price in session for pre_place_order "Silent Sync".
    // If the cached price is fresh enough (< TTL), the pre-order check can
    // skip the API call and make checkout feel instant.
    if (isset($api_price) && $api_price > 0) {
        $cache_key = md5(implode('|', [
            $bdHotelId,
            $room_id_str,
            TypeCoerce::toString($bookingData['board_id'] ?? ''),
            $check_in,
            $check_out,
            (string) TypeCoerce::toInt($bookingData['adults'] ?? 2),
            $children_ages,
        ]));
        // Ensure the cache bag is an array before writing the keyed entry
        // ($_SESSION is the authoritative session store — see SessionAccessor).
        $price_cache = &$_SESSION['novoton_price_cache'];
        if (!is_array($price_cache)) {
            $price_cache = [];
        }
        $price_cache[$cache_key] = [
            'api_price'     => $api_price,
            'api_price_raw' => $base_price,
            'form_price'    => $total_price,
            'timestamp'     => time(),
        ];
        unset($price_cache);
    }

    fn_set_notification('N', __('notice'), __('novoton_holidays.added_to_cart'));

    return [CONTROLLER_STATUS_REDIRECT, fn_travel_core_after_add_to_cart_url('novoton_holidays')];
