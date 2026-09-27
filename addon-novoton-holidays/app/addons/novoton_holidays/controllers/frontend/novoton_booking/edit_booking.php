<?php
declare(strict_types=1);
/**
 * Novoton Booking Controller — Edit Booking Mode
 * Extracted from novoton_booking.php for maintainability.
 * Included by the main controller when $mode == "edit_booking".
 */
if (!defined('BOOTSTRAP')) { exit('Access denied'); }

use Tygh\Registry;
use Tygh\Tygh;
use Tygh\Addons\NovotonHolidays\Services\PriceInfoFormatter;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\CurrencyService;
use Tygh\Addons\NovotonHolidays\Helpers\JsonDecoder;
use Tygh\Addons\NovotonHolidays\Services\RoomLimitsResolver;
use Tygh\Addons\NovotonHolidays\ViewModels\NovotonBookingSidebarBuilder;

    $booking_id = PriceInfoFormatter::toInt($_REQUEST['booking_id'] ?? 0);
    $cart_id = PriceInfoFormatter::toScalar($_REQUEST['cart_id'] ?? '');

    if (empty($booking_id)) {
        fn_set_notification('E', __('error'), __('novoton_holidays.invalid_booking_data'));
        return [CONTROLLER_STATUS_REDIRECT, 'checkout.cart'];
    }

    // Get booking record — verify ownership (user_id or session_id)
    // CS-Cart's session is an ArrayAccess container object; a plain (non-reference)
    // local binds the same object handle, so offset reads below operate on the
    // live session exactly as direct `Tygh::$app['session'][...]` access would.
    $session = Tygh::$app['session'];
    if (!is_array($session) && !$session instanceof \ArrayAccess) {
        $session = [];
    }
    $auth = TypeCoerce::toStringMap($session['auth'] ?? []);
    $current_user_id = PriceInfoFormatter::toInt($auth['user_id'] ?? 0);
    $current_session_id = (is_object($session) && method_exists($session, 'getID'))
        ? TypeCoerce::toString($session->getID())
        : '';

    $ownershipRepo = _nvt_booking_ownership_repo();
    /** @var array<string, mixed>|null $booking_record */
    $booking_record = $ownershipRepo->findByIdWithOwnership($booking_id, $current_user_id, $current_session_id);

    if (empty($booking_record)) {
        fn_set_notification('E', __('error'), __('novoton_holidays.invalid_booking_data'));
        return [CONTROLLER_STATUS_REDIRECT, 'checkout.cart'];
    }

    // Also try to get data from cart session (more up-to-date)
    $cart = $session['cart'] ?? null;
    // toArrayMap, NOT toStringMap: cart product ids are numeric (int keys)
    // and toStringMap drops int-keyed entries — the live-cart preference
    // below would silently never fire.
    $cart_products = is_array($cart) ? TypeCoerce::toArrayMap($cart['products'] ?? null) : [];
    /** @var array<string, mixed>|null $cart_item */
    $cart_item = null;
    if (!empty($cart_id) && !empty($cart_products[$cart_id]) && is_array($cart_products[$cart_id])) {
        $cart_item = $cart_products[$cart_id];
    }

    // Get hotel info (typed DTO + legacy array view for downstream template vars)
    $hotelRepo = _nvt_hotel_repo();
    $brHotelId = PriceInfoFormatter::toScalar($booking_record['hotel_id'] ?? '');
    $hotel = $hotelRepo->findByIdAsDto($brHotelId);
    /** @var array<string, mixed>|null $hotel_info */
    $hotel_info = $hotel?->toArray();

    // Get hotel name - try multiple sources
    $hotel_name = $hotel !== null ? $hotel->name ?? '' : '';
    if (empty($hotel_name)) {
        // Try from booking record
        $hotel_name = PriceInfoFormatter::toScalar($booking_record['hotel_name'] ?? '');
    }
    if (empty($hotel_name) && !empty($booking_record['product_id'])) {
        // Try from product name
        $hotel_name = PriceInfoFormatter::toScalar(db_get_field("SELECT product FROM ?:product_descriptions WHERE product_id = ?i AND lang_code = ?s",
            PriceInfoFormatter::toInt($booking_record['product_id']), CART_LANGUAGE));
    }
    if (empty($hotel_name)) {
        $hotel_name = 'Hotel #' . $brHotelId;
    }

    // Build booking data for form
    $rooms_data = [];
    $guests_data = [];

    // Rooms: try cart first (may have user selections), then database
    if ($cart_item !== null) {
        $cartExtra = TypeCoerce::toStringMap($cart_item['extra'] ?? []);
        if (!empty($cartExtra['rooms_data'])) {
            $rooms_data = JsonDecoder::decode(PriceInfoFormatter::toScalar($cartExtra['rooms_data']), 'edit_booking:cart_rooms_data');
        }
    }
    if (empty($rooms_data)) {
        $rooms_data = JsonDecoder::decode(PriceInfoFormatter::toScalar($booking_record['rooms_data'] ?? ''), 'edit_booking:db_rooms_data');
    }

    // Guests: ALWAYS read from DB — it's the single source of truth.
    // The cart copy is unreliable: fn_calculate_cart_content() can overwrite it,
    // session expiry loses it, and cart_id hash changes can make it stale.
    // update_booking.php writes to DB first, so DB always has the latest
    // guest data regardless of cart state. The shared builder normalizes the
    // stored shape and converts birthday -> DD/MM/YYYY dob for the form mask.
    $rawGuestsData = $booking_record['guests_data'] ?? '';
    $guestsInput = is_array($rawGuestsData) ? TypeCoerce::toStringMap($rawGuestsData) : TypeCoerce::toString($rawGuestsData);
    $guest_prefill = \Tygh\Addons\TravelCore\Services\GuestPrefillBuilder::build($guestsInput);

    $booking = [
        'hotel_id' => $brHotelId,
        'room_id' => PriceInfoFormatter::toScalar($booking_record['room_id'] ?? ''),
        'board_id' => PriceInfoFormatter::toScalar($booking_record['board_id'] ?? ''),
        'check_in' => PriceInfoFormatter::toScalar($booking_record['check_in'] ?? ''),
        'check_out' => PriceInfoFormatter::toScalar($booking_record['check_out'] ?? ''),
        'nights' => PriceInfoFormatter::toInt($booking_record['nights'] ?? 0),
        'adults' => PriceInfoFormatter::toInt($booking_record['adults'] ?? 2),
        'children' => PriceInfoFormatter::toInt($booking_record['children'] ?? 0),
        'children_ages' => PriceInfoFormatter::toScalar($booking_record['children_ages'] ?? ''),
        'total_price' => PriceInfoFormatter::toFloat($booking_record['total_price'] ?? 0),
        'package_name' => PriceInfoFormatter::toScalar($booking_record['package_name'] ?? ''),
        'num_rooms' => PriceInfoFormatter::toInt($booking_record['num_rooms'] ?? 0) ?: 1,
        'rooms_data' => $rooms_data,
    ];

    // Ensure rooms_data is not empty for single room bookings
    if (empty($booking['rooms_data'])) {
        // Parse children ages
        $children_ages_arr = [];
        if (!empty($booking['children_ages'])) {
            $children_ages_arr = array_map('intval', array_filter(explode(',', $booking['children_ages']), function($v) { return $v !== ''; }));
        }

        // Create default rooms_data
        $brRoomId = PriceInfoFormatter::toScalar($booking_record['room_id'] ?? '');
        $brBoardId = PriceInfoFormatter::toScalar($booking_record['board_id'] ?? '');
        $booking['rooms_data'] = [
            [
                'room_id' => $brRoomId,
                'room_name' => fn_novoton_holidays_format_room_type($brRoomId),
                'room_type_display' => fn_novoton_holidays_format_room_type($brRoomId),
                'board_id' => $brBoardId,
                'board_name' => fn_novoton_holidays_format_board_name($brBoardId),
                'adults' => PriceInfoFormatter::toInt($booking_record['adults'] ?? 2),
                'children' => PriceInfoFormatter::toInt($booking_record['children'] ?? 0),
                'childrenAges' => $children_ages_arr,
                'price' => PriceInfoFormatter::toFloat($booking_record['total_price'] ?? 0)
            ]
        ];
    }

    // Parse children ages
    $children_ages_array = [];
    if (!empty($booking['children_ages'])) {
        $children_ages_array = array_map('intval', array_filter(explode(',', $booking['children_ages']), function($v) { return $v !== ''; }));
    }
    $booking['children_ages_array'] = $children_ages_array;

    // Get package name
    $package_name = PriceInfoFormatter::toScalar($booking_record['package_name'] ?? '');
    if (empty($package_name) && !empty($brHotelId)) {
        // V3: Get first package from novoton_hotel_packages table
        $packageRepo = \Tygh\Addons\NovotonHolidays\Services\Container::getInstance()->hotelPackageRepository();
        $first_pkg = $packageRepo->getFirstPackageName($brHotelId);
        if (!empty($first_pkg)) {
            $package_name = $first_pkg;
        }
    }

    // Get hotel stars
    $hotel_stars = '';
    if (is_array($hotel_info) && !empty($hotel_info['star_rating'])) {
        $hotel_stars = str_repeat('★', PriceInfoFormatter::toInt($hotel_info['star_rating']));
    }

    // V3: Get all packages from novoton_hotel_packages table
    $all_packages = [];
    $packageRepo = \Tygh\Addons\NovotonHolidays\Services\Container::getInstance()->hotelPackageRepository();
    $db_packages = $packageRepo->getPackageIdNamePairs($brHotelId);
    if (!empty($db_packages)) {
        foreach ($db_packages as $pkg) {
            $all_packages[] = [
                'IdCont' => PriceInfoFormatter::toScalar($pkg['package_id'] ?? ''),
                'PackageName' => PriceInfoFormatter::toScalar($pkg['package_name'] ?? '')
            ];
        }
    }

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
    $view->assign('booking_id', $booking_id);
    $view->assign('cart_id', $cart_id);
    $view->assign('is_edit_mode', true);
    $view->assign('guest_prefill', $guest_prefill);
    $edit_product_id = PriceInfoFormatter::toInt($booking_record['product_id'] ?? 0);
    $view->assign('product_id', $booking_record['product_id']);
    $view->assign('hotel_name', $hotel_name);
    $view->assign('hotel_city', $hotel_info['city'] ?? $booking_record['hotel_city'] ?? '');
    $view->assign('hotel_region', $hotel_info['region'] ?? '');
    $view->assign('hotel_country', $hotel_info['country'] ?? $booking_record['hotel_country'] ?? 'BULGARIA');
    $view->assign('hotel_stars', $hotel_stars);
    $view->assign('package_name', $package_name);
    $view->assign('hotel_all_packages', $all_packages);
    $view->assign('auth', TypeCoerce::toStringMap($session['auth'] ?? []));

    // ── The same page, fully ──────────────────────────────────────────────
    // edit_booking.tpl is a one-line include of booking_form.tpl, so this mode
    // renders the identical 2-column layout — but only if it assigns the
    // identical view variables. It used to assign neither the hotel header nor
    // the sidebar, and since components/booking_sidebar.tpl is wrapped in
    // `{if $tbs}`, the entire left column rendered as NOTHING: an isolated bare
    // form with no hotel, no dates, no price, and an empty booking-conditions
    // modal (which reads the same variable). Both come from the shared builder
    // now, so create and edit cannot diverge again.
    //
    // Figures are the BOOKED ones — no re-quote. A guest fixing a misspelled
    // name must not have the price move under them.
    $editHeaderVm = NovotonBookingSidebarBuilder::header(
        TypeCoerce::toString($brHotelId),
        TypeCoerce::toStringMap($hotel_info ?? []),
        $hotel_stars,
        $edit_product_id,
        $hotel_name,
    );
    $view->assign('hotel_location_line', $editHeaderVm->locationLine);
    $view->assign('hotel_map_url', $editHeaderVm->mapUrl);
    $view->assign('travel_hotel_header', $editHeaderVm->toViewArray());

    $editSidebarVm = NovotonBookingSidebarBuilder::sidebar(
        $booking,
        $editHeaderVm,
        $edit_product_id,
        TypeCoerce::toString($package_name),
        $novoton_display_coefficient,
        $novoton_display_symbol,
        true,
        defined('CART_LANGUAGE') ? TypeCoerce::toString(CART_LANGUAGE) : 'en',
        \Tygh\Addons\TravelCore\Services\MoneyFormatter::forStore(),
        _nvt_currency_service()->convertFromApiCurrency(
            TypeCoerce::toFloat($booking['total_price']),
            defined('CART_PRIMARY_CURRENCY') ? TypeCoerce::toString(CART_PRIMARY_CURRENCY) : 'EUR',
        ),
        '',
        '',
        \Tygh\Addons\TravelCore\Services\TravelCoreConfig::getDateFormat(),
        \Tygh\Addons\TravelCore\Services\TravelCoreConfig::getBookingSidebarMaxFeatures(),
    );
    $view->assign('travel_booking_sidebar', $editSidebarVm->toViewArray());

    // Age categories, occupancy limits and the rooms JSON the form's inline
    // script reads. All three are `|default:`-guarded in the template, so
    // their absence degraded silently: every hotel quietly got 4 adults /
    // 2 children / capacity 2, and the room list came up empty.
    $editLimits = RoomLimitsResolver::resolve(
        TypeCoerce::toStringMap($hotel_info ?? []),
        TypeCoerce::toString($brHotelId),
        TypeCoerce::toString($booking['room_id']),
    );
    $editRoomsJson = json_encode($booking['rooms_data'], JSON_UNESCAPED_UNICODE);
    $view->assign('booking_data', array_merge($booking, [
        'age_categories' => $editLimits['age_categories'],
        'current_room_limits' => $editLimits['current_room_limits'],
        'rooms_data_json' => is_string($editRoomsJson) ? $editRoomsJson : '[]',
    ]));

    // Page setup
    $page_title = __('novoton_holidays.edit_booking');
    $view->assign('page_title', $page_title);
    Registry::set('navigation.dynamic.page_title', $page_title);
    fn_add_breadcrumb($page_title);

    // Use booking_form template for edit mode
    // Template will be rendered automatically by CS-Cart
