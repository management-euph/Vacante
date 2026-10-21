<?php
declare(strict_types=1);
/**
 * Novoton Booking Controller
 * Path: app/addons/novoton_holidays/controllers/frontend/novoton_booking.php
 *
 * Refactored v2.7.0-A72:
 * - Service getters for lazy-loaded singletons
 * - Proper delegation to existing services (no duplicate helpers)
 * - _nvt_get_cached_hotel_info() adds caching to NovotonApi::getHotelInfo
 * - Validation helpers for DOB and child age checking
 *
 * Removed duplicate helpers - use services directly:
 * - CacheService::remember() instead of _nvt_cached_fetch()
 * - SearchService::parseSearchParams() for search parameter parsing
 * - SearchService::calculateRoomTotals() for room totals
 * - BookingService::createBooking() for bookings
 * - RoomPriceService::getRoomPrice() for room prices (has built-in caching)
 *
 * v2.8.0: Split into mode handler files under novoton_booking/ directory.
 * Each mode is in its own file for maintainability (~350 lines dispatcher
 * instead of ~3,400 lines monolith). Mode files:
 *   novoton_booking/search.php              - Hotel availability search
 *   novoton_booking/booking_form.php        - Guest entry form
 *   novoton_booking/add_to_cart.php         - Process booking into cart
 *   novoton_booking/edit_booking.php        - Edit existing cart booking
 *   novoton_booking/update_booking.php      - Save edited booking
 *   novoton_booking/request_alternatives.php - Alternative hotel request
 *   novoton_booking/ajax_recalculate_price.php - AJAX price recalculation
 */

use Tygh\Registry;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

//=============================================================================
// SERVICE LOADER
// A79: Load all services via ServiceLoader.php for lazy-loaded singletons
// Available functions:
//   _nvt_booking_service()   - Booking creation, cart, orders
//   _nvt_guest_service()     - Guest data parsing, validation
//   _nvt_search_service()    - Search parameter parsing
//   _nvt_price_service()     - Price calculations, commission
//   _nvt_security_service()  - Input validation, sanitization
//   _nvt_cache_service()     - API response caching
//   _nvt_hotel_repo()        - Hotel database access
//   _nvt_booking_repo()      - Booking database access
//=============================================================================

$service_loader = TypeCoerce::toString(Registry::get('config.dir.addons')) . 'novoton_holidays/src/Services/ServiceLoader.php';
if (file_exists($service_loader)) {
    require_once $service_loader;
}

// Legacy alias for backward compatibility
if (!function_exists('_nvt_get_cache_service')) {
    /** @return \Tygh\Addons\NovotonHolidays\Services\CacheServiceInterface */
    function _nvt_get_cache_service() {
        return _nvt_cache_service();
    }
}

// Service delegation helpers (remaining ones have live callers; the
// _nvt_get_search_service and _nvt_get_booking_service wrappers were
// dropped in the audit cleanup — zero callers anywhere in the three addons.)
if (!function_exists('_nvt_get_price_service')) {
    /** @return \Tygh\Addons\NovotonHolidays\Services\RoomPriceServiceInterface */
    function _nvt_get_price_service() {
        return _nvt_price_service();
    }
}
if (!function_exists('_nvt_get_security_service')) {
    /** @return \Tygh\Addons\NovotonHolidays\Services\SecurityServiceInterface */
    function _nvt_get_security_service() {
        return _nvt_security_service();
    }
}

//=============================================================================
// SANITIZE $_REQUEST / $_GET — prevent "Array to string conversion" warnings
// When the URL contains children_ages[]=5&children_ages[]=8, PHP parses these
// into arrays. CS-Cart's __() translation function (and other internal calls)
// may cast these to string, triggering warnings that corrupt JSON output.
// Fix: flatten known array params to comma-separated strings at controller top.
//=============================================================================
$_nvt_array_params = ['children_ages', 'ages'];
foreach ($_nvt_array_params as $_nvt_param) {
    foreach ([&$_REQUEST, &$_GET] as &$_nvt_superglobal) {
        if (isset($_nvt_superglobal[$_nvt_param]) && is_array($_nvt_superglobal[$_nvt_param])) {
            $_nvt_superglobal[$_nvt_param] = implode(',', array_map(static fn ($_nvt_v): int => intval(TypeCoerce::toString($_nvt_v)), $_nvt_superglobal[$_nvt_param]));
        }
    }
    unset($_nvt_superglobal);
}
unset($_nvt_array_params, $_nvt_param);

// Guest parsing and DOB validation are now in travel_core:
// \Tygh\Addons\TravelCore\Services\GuestDataService::parseAndValidateGuests()
// \Tygh\Addons\TravelCore\Services\GuestDataService::parseDob()

/**
 * Get hotel info from API with caching
 * Caches the room/board structure for 30 minutes
 *
 * @param string $hotel_id Hotel ID
 * @param bool $force Force fresh fetch
 * @return object|null Hotel info XML or null
 */
if (!function_exists('_nvt_get_cached_hotel_info')) {
/**
 * @param string $hotel_id
 * @param bool $force
 * @return \SimpleXMLElement|null
 */
function _nvt_get_cached_hotel_info($hotel_id, $force = false) {
    $cache_key = 'nvt_hotel_info_' . $hotel_id;

    // Try cache first (unless forced)
    if (!$force) {
        $cache = _nvt_get_cache_service();
        $cached = $cache->get($cache_key);
        if ($cached !== null) {
            // Convert cached array back to SimpleXMLElement if needed
            if (is_string($cached)) {
                try {
                    return simplexml_load_string($cached, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET) ?: null;
                } catch (Exception) {
                    // Cache corrupted, fetch fresh
                }
            }
            return $cached instanceof \SimpleXMLElement ? $cached : null;
        }
    }

    // Fetch from API
    $api = fn_novoton_holidays_get_api();
    if ($api === null) {
        return null;
    }

    $hotelInfo = $api->hotels()->getHotelInfo($hotel_id);

    // Cache the XML string (30 minutes - hotel room/board structure doesn't change often)
    $cache = _nvt_get_cache_service();
    $cache->set($cache_key, $hotelInfo->asXML(), 1800);

    return $hotelInfo;
}
} // end function_exists _nvt_get_cached_hotel_info

// A72: Use RoomPriceService::getRoomPrice() for room prices (it has built-in caching)
// Example: $priceService = _nvt_get_price_service(); $price = $priceService->getRoomPrice($params);

//=============================================================================
// MODE DISPATCHER
// Each mode handler lives in its own file under novoton_booking/ directory.
// The include returns a value if the mode handler uses return (e.g. redirects);
// we propagate that back to CS-Cart's controller framework.
//=============================================================================

$_nvt_mode_dir = __DIR__ . '/novoton_booking';

if ($mode === 'search') {
    $__nvt_result = include($_nvt_mode_dir . '/search.php');
    if ($__nvt_result !== 1) return $__nvt_result;

} elseif ($mode === 'booking_form') {
    $__nvt_result = include($_nvt_mode_dir . '/booking_form.php');
    if ($__nvt_result !== 1) return $__nvt_result;

} elseif ($mode === 'add_to_cart') {
    $__nvt_result = include($_nvt_mode_dir . '/add_to_cart.php');
    if ($__nvt_result !== 1) return $__nvt_result;

} elseif ($mode === 'book') {
    // Legacy redirect — only forward known booking parameters
    $allowed_keys = ['hotel_id', 'check_in', 'check_out', 'adults', 'children', 'rooms', 'package_name', 'room_id', 'board_id'];
    $bookingData = array_intersect_key($_REQUEST, array_flip($allowed_keys));
    $redirect_url = 'novoton_booking.booking_form?' . http_build_query($bookingData);
    return [CONTROLLER_STATUS_REDIRECT, $redirect_url];

} elseif ($mode === 'edit_booking') {
    $__nvt_result = include($_nvt_mode_dir . '/edit_booking.php');
    if ($__nvt_result !== 1) return $__nvt_result;

} elseif ($mode === 'update_booking') {
    $__nvt_result = include($_nvt_mode_dir . '/update_booking.php');
    if ($__nvt_result !== 1) return $__nvt_result;

} elseif ($mode === 'request_alternatives') {
    $__nvt_result = include($_nvt_mode_dir . '/request_alternatives.php');
    if ($__nvt_result !== 1) return $__nvt_result;

} elseif ($mode === 'ajax_recalculate_price') {
    // This mode always dies with JSON output; include never returns
    include($_nvt_mode_dir . '/ajax_recalculate_price.php');
}
