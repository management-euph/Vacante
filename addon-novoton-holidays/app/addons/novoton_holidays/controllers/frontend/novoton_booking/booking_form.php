<?php
declare(strict_types=1);
/**
 * Novoton Booking Controller — Booking Form Mode
 * Extracted from novoton_booking.php for maintainability.
 * Included by the main controller when $mode == "booking_form".
 */
if (!defined('BOOTSTRAP')) { exit('Access denied'); }

use Tygh\Registry;
use Tygh\Tygh;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Addons\NovotonHolidays\Services\Container;
use Tygh\Addons\NovotonHolidays\Services\PriceInfoFormatter;
use Tygh\Addons\NovotonHolidays\Services\RoomLimitsResolver;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\CurrencyService;
use Tygh\Addons\NovotonHolidays\ViewModels\NovotonBookingSidebarBuilder;

    $bookingData = TypeCoerce::toStringMap($_REQUEST);

    // Normalize room_id: restore + signs lost by URL decoding
    if (!empty($bookingData['room_id'])) {
        $bookingData['room_id'] = fn_novoton_holidays_normalize_room_code(PriceInfoFormatter::toScalar($bookingData['room_id']));
    }

    // Check if this is a multi-room booking
    $is_multi_room = !empty($bookingData['multi_room']) || !empty($bookingData['rooms_data']);

    // Parse rooms_data if it's a string (from form submission)
    $rooms_data = [];
    if (!empty($bookingData['rooms_data'])) {
        $rawRoomsData = $bookingData['rooms_data'];
        $rooms_data = is_string($rawRoomsData)
            ? json_decode(rawurldecode($rawRoomsData), true)
            : $rawRoomsData;
        if (!is_array($rooms_data)) {
            $rooms_data = [];
        }
        // Normalize room_id and room_name in each room (restore + lost by URL decoding)
        foreach ($rooms_data as &$room) {
            if (!is_array($room)) {
                continue;
            }
            if (!empty($room['room_id'])) {
                $room['room_id'] = fn_novoton_holidays_normalize_room_code(PriceInfoFormatter::toScalar($room['room_id']));
            }
            if (!empty($room['room_name'])) {
                $room['room_name'] = fn_novoton_holidays_normalize_room_code(PriceInfoFormatter::toScalar($room['room_name']));
            }
        }
        unset($room);
    }

    // For multi-room, derive room_id from rooms_data if not directly provided
    if ($is_multi_room && empty($bookingData['room_id']) && is_array($rooms_data[0] ?? null) && !empty($rooms_data[0]['room_id'])) {
        $bookingData['room_id'] = PriceInfoFormatter::toScalar($rooms_data[0]['room_id']);
        $bookingData['board_id'] = PriceInfoFormatter::toScalar($rooms_data[0]['board_id'] ?? 'AI');
    }

    // Validate required data
    if (empty($bookingData['hotel_id']) || empty($bookingData['check_in']) || empty($bookingData['check_out'])) {
        fn_set_notification('E', __('error'), TypeCoerce::toString(__('novoton_holidays.invalid_booking_data')) . ' (missing hotel_id, check_in, or check_out)');
        return [CONTROLLER_STATUS_REDIRECT, 'index.index'];
    }

    // For non-multi-room, room_id is required
    if (!$is_multi_room && empty($bookingData['room_id'])) {
        fn_set_notification('E', __('error'), TypeCoerce::toString(__('novoton_holidays.invalid_booking_data')) . ' (missing room_id)');
        return [CONTROLLER_STATUS_REDIRECT, 'index.index'];
    }

    // Get product and hotel info
    $prefix = ConfigProvider::getFirstProductCodePrefix();
    $bdHotelId = PriceInfoFormatter::toScalar($bookingData['hotel_id']);
    $product_code = $prefix . $bdHotelId;

    $product_id = db_get_field(
        "SELECT product_id FROM ?:products WHERE product_code = ?s",
        $product_code
    );

    // Get hotel info from novoton_hotels table
    /** @var array<string, mixed>|null $hotel_info */
    $hotel_info = _nvt_hotel_repo()->findById($bdHotelId);

    // If hotel not found in novoton_hotels, try to get name from product
    if ((empty($hotel_info) || empty($hotel_info['hotel_name'])) && !empty($product_id)) {
        $product_name = PriceInfoFormatter::toScalar(db_get_field(
            "SELECT product FROM ?:product_descriptions WHERE product_id = ?i AND lang_code = ?s",
            PriceInfoFormatter::toInt($product_id),
            CART_LANGUAGE
        ));
        if (!empty($product_name)) {
            if (empty($hotel_info)) {
                $hotel_info = [];
            }
            $hotel_info['hotel_name'] = $product_name;
        }
    }

    // Fallback: if still no hotel name, use hotel_id
    if (!is_array($hotel_info)) {
        $hotel_info = [];
    }
    if (empty($hotel_info['hotel_name'])) {
        $hotel_info['hotel_name'] = 'Hotel #' . $bdHotelId;
    }

    // Prepare booking data for template
    $booking = [
        'hotel_id' => $bdHotelId,
        'room_id' => PriceInfoFormatter::toScalar($bookingData['room_id']),
        'board_id' => PriceInfoFormatter::toScalar($bookingData['board_id'] ?? 'BB'),
        'check_in' => PriceInfoFormatter::toScalar($bookingData['check_in']),
        'check_out' => PriceInfoFormatter::toScalar($bookingData['check_out']),
        'nights' => PriceInfoFormatter::toInt($bookingData['nights'] ?? 7),
        'adults' => PriceInfoFormatter::toInt($bookingData['adults'] ?? 2),
        'children' => PriceInfoFormatter::toInt($bookingData['children'] ?? 0),
        'total_price' => PriceInfoFormatter::toFloat($bookingData['total_price'] ?? $bookingData['price'] ?? 0),
        'children_ages' => PriceInfoFormatter::toScalar($bookingData['children_ages'] ?? ''),
        'package_name' => PriceInfoFormatter::toScalar($bookingData['package_name'] ?? ''),
        'num_rooms' => PriceInfoFormatter::toInt($bookingData['num_rooms'] ?? 1),
        'rooms_data' => []
    ];

    // Reuse rooms_data already parsed and room_id-fixed at top of controller
    if (!empty($rooms_data)) {
        $booking['rooms_data'] = $rooms_data;
        $booking['num_rooms'] = count($rooms_data);
    }

    // If no rooms_data, create default based on adults/children
    if (empty($booking['rooms_data'])) {
        $children_ages_arr = [];
        if (!empty($booking['children_ages'])) {
            $children_ages_arr = array_map('intval', array_filter(explode(',', $booking['children_ages']), function($v) { return $v !== ''; }));
        }

        // Z3: Format board name consistently
        $formatted_board_name = fn_novoton_holidays_format_board_name($booking['board_id']);
        if ($formatted_board_name === $booking['board_id'] && !empty($bookingData['board_name'])) {
            // If format function didn't change it, try the passed board_name
            $formatted_board_name = fn_novoton_holidays_format_board_name(PriceInfoFormatter::toScalar($bookingData['board_name']));
        }

        $booking['rooms_data'] = [
            [
                'room_id' => $booking['room_id'],
                'room_name' => PriceInfoFormatter::toScalar($bookingData['room_name'] ?? $booking['room_id']),
                'board_id' => $booking['board_id'],
                'board_name' => $formatted_board_name,
                'adults' => $booking['adults'],
                'children' => $booking['children'],
                'childrenAges' => $children_ages_arr,
                'price' => $booking['total_price']
            ]
        ];
    }

    // Parse children ages if string (for legacy support)
    $children_ages_array = [];
    if (!empty($booking['children_ages'])) {
        $children_ages_array = array_map('intval', array_filter(explode(',', $booking['children_ages']), function($v) { return $v !== ''; }));
    }
    $booking['children_ages_array'] = $children_ages_array;

    // Get package name - only from passed parameters (tied to specific room/price)
    $package_name = '';

    // First check rooms_data (from multi-room booking)
    $firstRoom = is_array($booking['rooms_data'][0] ?? null) ? $booking['rooms_data'][0] : [];
    if (!empty($firstRoom['package_name'])) {
        $package_name = PriceInfoFormatter::toScalar($firstRoom['package_name']);
    }
    // Then check URL parameter (from single room booking)
    elseif (!empty($bookingData['package_name'])) {
        $package_name = PriceInfoFormatter::toScalar($bookingData['package_name']);
    }
    // Decode URL encoding (e.g., %2b -> +)
    $package_name = rawurldecode($package_name);
    // Do NOT fall back to database - package_name must come from room_price result

    // Get hotel stars
    $hotel_stars = '';
    $hotelName = PriceInfoFormatter::toScalar($hotel_info['hotel_name'] ?? '');
    if (!empty($hotel_info['star_rating'])) {
        $hotel_stars = str_repeat('★', PriceInfoFormatter::toInt($hotel_info['star_rating']));
    } elseif (!empty($hotelName)) {
        // Try to extract stars from hotel name (e.g. "Hotel Name ****")
        if (preg_match('/(\*+)/', $hotelName, $matches) === 1) {
            $hotel_stars = $matches[1];
        }
    }

    // V3: Get all packages from novoton_hotel_packages table
    $all_packages = [];
    $packageRepo = \Tygh\Addons\NovotonHolidays\Services\Container::getInstance()->hotelPackageRepository();
    $db_packages = $packageRepo->getPackageIdNamePairs($booking['hotel_id']);
    if (!empty($db_packages)) {
        foreach ($db_packages as $pkg) {
            $all_packages[] = [
                'IdCont' => PriceInfoFormatter::toScalar($pkg['package_id'] ?? ''),
                'PackageName' => PriceInfoFormatter::toScalar($pkg['package_name'] ?? '')
            ];
        }
    }

    // Age categories + occupancy limits for the form's guest pickers. Shared
    // with edit_booking, which renders the same template and reads the same
    // two variables.
    $limits = RoomLimitsResolver::resolve(
        TypeCoerce::toStringMap($hotel_info),
        TypeCoerce::toString($booking['hotel_id']),
        TypeCoerce::toString($booking['room_id']),
    );
    $booking['age_categories'] = $limits['age_categories'];
    $booking['current_room_limits'] = $limits['current_room_limits'];

    // Canonical rooms_data JSON for the template's inline JS. Prepared here
    // because {json_decode(...)} in a template is a Smarty 5 CompilerException
    // — rooms_data is already normalized to an array above.
    $roomsDataJson = json_encode($booking['rooms_data'], JSON_UNESCAPED_UNICODE);
    $booking['rooms_data_json'] = is_string($roomsDataJson) ? $roomsDataJson : '[]';

    // Assign to view
    /** @var \Smarty $view */
    $view = Tygh::$app['view'];
    $view->assign('booking_data', $booking);
    $novoton_display_currency = CurrencyService::getDisplayCurrency();
    $currenciesMap = TypeCoerce::toStringMap(\Tygh\Registry::get('currencies'));
    $currencyEntry = TypeCoerce::toStringMap($currenciesMap[$novoton_display_currency] ?? []);
    $novoton_display_coefficient = TypeCoerce::toFloat($currencyEntry['coefficient'] ?? 1.0);
    $novoton_display_symbol = TypeCoerce::toString($currencyEntry['symbol'] ?? $novoton_display_currency);

    $view->assign('novoton_display_currency', $novoton_display_currency);
    $view->assign('novoton_display_coefficient', $novoton_display_coefficient);
    $view->assign('novoton_display_symbol', $novoton_display_symbol);
    $view->assign('product_id', $product_id);
    $view->assign('hotel_name', $hotel_info['hotel_name'] ?? 'Hotel');
    $view->assign('hotel_city', $hotel_info['city'] ?? '');
    $view->assign('hotel_region', $hotel_info['region'] ?? '');
    $view->assign('hotel_country', $hotel_info['country'] ?? 'BULGARIA');
    $view->assign('hotel_stars', $hotel_stars);

    // Sanitized location line + map link, same as the search card and PDP
    // ($hotel_info is loaded via findById = SELECT *, so the coordinate/address
    // columns are already present). The map URL comes from the shared builder
    // so the "Locație - arată pe hartă" link is present for every hotel —
    // coordinate pin when geocoded, place-search on "name, location" otherwise.
    // Shared header derivation (travel_core factory via the novoton builder):
    // sanitized line + always-present map URL + the hotel_header component's
    // view model. Built by the SAME builder edit_booking uses, so the two modes
    // of this page cannot drift apart again.
    $headerVm = NovotonBookingSidebarBuilder::header(
        TypeCoerce::toString($booking['hotel_id']),
        TypeCoerce::toStringMap($hotel_info),
        $hotel_stars,
        PriceInfoFormatter::toInt($product_id),
    );
    $view->assign('hotel_location_line', $headerVm->locationLine);
    $view->assign('hotel_map_url', $headerVm->mapUrl);
    $view->assign('travel_hotel_header', $headerVm->toViewArray());
    $view->assign('package_name', $package_name);
    $view->assign('hotel_all_packages', $all_packages);
    $session = Tygh::$app['session'];
    $sessionAuth = (is_array($session) || $session instanceof \ArrayAccess) ? ($session['auth'] ?? []) : [];
    $view->assign('auth', TypeCoerce::toStringMap($sessionAuth));

    // Calendar prices: per-date approximate total for the cheapest room
    // Uses guest count to calculate realistic per-night totals
    $calendar_prices_json = '{}';
    $calendar_prices_currency = $novoton_display_currency;
    if (ConfigProvider::isShowCalendarPrices()) {
        $calendar_adults = max(1, (int) $booking['adults']);
        $priceInfoService = Container::getInstance()->priceInfoService();
        $calendarData = $priceInfoService->getCalendarPrices(TypeCoerce::toString($bookingData['hotel_id']), $novoton_display_currency, $calendar_adults);
        if (!empty($calendarData['prices'])) {
            $calendar_prices_json = json_encode($calendarData['prices'], JSON_UNESCAPED_UNICODE);
            $calendar_prices_currency = $calendarData['currency'];
        }
    }
    $view->assign('calendar_prices_json', $calendar_prices_json);
    $view->assign('calendar_prices_currency', $calendar_prices_currency);
    $view->assign('show_calendar_prices', ConfigProvider::isShowCalendarPrices() ? 'Y' : 'N');

    // ── Shared 2-column summary sidebar (travel_core component) ───────────
    // Same builder as edit_booking: one page, one summary, whichever mode
    // rendered it.
    // Availability as the search result stated it (the Book link carries
    // the API quota): on request, or bookable with "only N left".
    [$nvtStatus, $nvtRoomsLeft] = NovotonBookingSidebarBuilder::availability(
        !empty($bookingData['is_on_request']),
        TypeCoerce::toInt($bookingData['rooms_available'] ?? 0),
    );
    // The cart-line amount (API EUR → store primary), shown in the shopper's
    // selected currency — the same number add_to_cart stores.
    $nvtPrimaryTotal = _nvt_currency_service()->convertFromApiCurrency(
        PriceInfoFormatter::toFloat($booking['total_price'] ?? 0),
        defined('CART_PRIMARY_CURRENCY') ? TypeCoerce::toString(CART_PRIMARY_CURRENCY) : 'EUR',
    );
    $sidebarVm = NovotonBookingSidebarBuilder::sidebar(
        $bookingData,
        $headerVm,
        PriceInfoFormatter::toInt($product_id),
        TypeCoerce::toString($package_name),
        $novoton_display_coefficient,
        $novoton_display_symbol,
        $nvtStatus !== \Tygh\Addons\TravelCore\ViewModels\BookingSidebarViewModel::STATUS_ON_REQUEST,
        defined('CART_LANGUAGE') ? TypeCoerce::toString(CART_LANGUAGE) : 'en',
        \Tygh\Addons\TravelCore\Services\MoneyFormatter::forStore(),
        $nvtPrimaryTotal,
        $nvtStatus,
        $nvtRoomsLeft > 0 ? TypeCoerce::toString(__('novoton_holidays.we_have_left', ['[count]' => $nvtRoomsLeft])) : '',
        \Tygh\Addons\TravelCore\Services\TravelCoreConfig::getDateFormat(),
        \Tygh\Addons\TravelCore\Services\TravelCoreConfig::getBookingSidebarMaxFeatures(),
    );
    $view->assign('travel_booking_sidebar', $sidebarVm->toViewArray());

    // Page setup
    $page_title = __('novoton_holidays.complete_booking');
    $view->assign('page_title', $page_title);
    Registry::set('navigation.dynamic.page_title', $page_title);
    fn_add_breadcrumb($page_title);
