<?php
declare(strict_types=1);
/**
 * Sphinx Booking Controller
 * Path: app/addons/sphinx_holidays/controllers/frontend/sphinx_booking.php
 *
 * Mode dispatcher for Sphinx hotel booking flow.
 * Follows the same pattern as novoton_booking.php.
 *
 * Modes:
 *   sphinx_booking/search.php              - Hotel availability search (polling)
 *   sphinx_booking/booking_form.php        - Verify offer, show guest form
 *   sphinx_booking/add_to_cart.php         - Create booking, add to cart
 *   sphinx_booking/ajax_recalculate_price.php - AJAX price re-verification
 *
 * @package SphinxHolidays
 * @since   1.0.0
 */

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

//=============================================================================
// SANITIZE $_REQUEST — flatten array params to comma-separated strings
// to prevent "Array to string conversion" warnings in CS-Cart core dispatch.
//=============================================================================
foreach (['children_ages', 'ages'] as $_sphinx_param) {
    if (isset($_REQUEST[$_sphinx_param]) && is_array($_REQUEST[$_sphinx_param])) {
        $_REQUEST[$_sphinx_param] = implode(',', array_map(static fn ($_sphinx_v): int => intval(TypeCoerce::toString($_sphinx_v)), $_REQUEST[$_sphinx_param]));
    }
}
unset($_sphinx_param);

//=============================================================================
// UTILITY HELPERS
//=============================================================================

// Guest parsing and DOB validation are now in travel_core:
// \Tygh\Addons\TravelCore\Services\GuestDataService::parseAndValidateGuests()
// \Tygh\Addons\TravelCore\Services\GuestDataService::parseDob()

//=============================================================================
// MODE DISPATCHER
//
// CS-Cart has NO automatic per-mode subdirectory include — this allowlist IS
// the routing. Every mode file in sphinx_booking/ MUST be listed here or the
// mode 404s ("Page Not Found") even though the file exists. Regression: only
// 4 of 15 modes were routed, so search_poll 404ed on every poll and each
// availability search ended in "no hotels found" without ever fetching
// results. ControllerModeRoutingTest enforces file ↔ allowlist parity.
//=============================================================================

$_sphinx_mode_dir = __DIR__ . '/sphinx_booking';

$_sphinx_modes = [
    'search',
    'search_poll',
    'booking_form',
    'add_to_cart',
    'edit_booking',
    'update_booking',
    'ajax_recalculate_price',
    'offer_terms',
    'request_alternatives',
    'cache_deals',
    'circuit_search',
    'circuit_booking_form',
    'circuit_add_to_cart',
    'circuit_dates',
    // Experiences are deliberately UNROUTED: the provider does not support
    // them yet, so the half-shipped pages must not be publicly reachable.
    // The sync pipeline (ExperienceSyncCommand, sphinx_experiences) stays
    // dormant for when the provider enables the vertical.
    'package_search',
    'package_booking_form',
    'package_add_to_cart',
];

if (in_array($mode, $_sphinx_modes, true)) {
    $__sphinx_result = include $_sphinx_mode_dir . '/' . $mode . '.php';
    if ($__sphinx_result !== 1) {
        return $__sphinx_result;
    }
}
