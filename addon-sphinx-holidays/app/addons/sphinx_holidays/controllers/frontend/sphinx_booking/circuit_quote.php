<?php
declare(strict_types=1);
/**
 * Sphinx Booking Controller — Circuit Quote Mode (JSON)
 *
 * The rooms of "Camera pentru tot circuitul" on a circuit's page (board
 * 16.3, travel_core CircuitBar.jsx): one departure, the customer's rooms,
 * each offer with its price for the whole group (CircuitRoomOffers).
 *
 * Only an active circuit of the store, a date not past and rooms within
 * the guest picker's limits are asked about, so the endpoint never relays
 * an arbitrary request to Sphinx.
 *
 * @package SphinxHolidays
 */
if (!defined('BOOTSTRAP')) { exit('Access denied'); }

use Tygh\Addons\SphinxHolidays\Helpers\SearchOccupancyResolver;
use Tygh\Addons\SphinxHolidays\Services\CircuitRoomOffers;
use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\TravelCore\Helpers\RequestCoerce;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\ProviderRoomLimit;

$circuitId = RequestCoerce::int($_REQUEST, 'circuit_id');
$departureId = RequestCoerce::int($_REQUEST, 'departure_id');
$departureDate = RequestCoerce::string($_REQUEST, 'departure_date');
$occupancy = SearchOccupancyResolver::resolve(
    RequestCoerce::string($_REQUEST, 'rooms_data'),
    max(1, RequestCoerce::int($_REQUEST, 'adults', 2)),
    max(1, RequestCoerce::int($_REQUEST, 'rooms', 1)),
    RequestCoerce::string($_REQUEST, 'children_ages'),
);

$answer = [
    'blocks' => [],
    'adults' => array_sum(array_column($occupancy, 'adults')),
    'children' => count(array_merge(...array_column($occupancy, 'children_ages'))),
    'error' => true,
];
$known = $circuitId > 0 && TypeCoerce::toInt(db_get_field(
    "SELECT circuit_id FROM ?:sphinx_circuits WHERE circuit_id = ?i AND sync_status = 'active'",
    $circuitId,
)) === $circuitId;
$asked = $known
    && preg_match('/^\d{4}-\d{2}-\d{2}$/', $departureDate) === 1
    && $departureDate >= date('Y-m-d')
    && CircuitRoomOffers::validOccupancy($occupancy, ProviderRoomLimit::maxRooms('sphinx'));
if ($asked) {
    try {
        $answer = (new CircuitRoomOffers(
            Container::getApi(),
            static fn (float $price): float => Container::getCartService()->applyCommission($price),
        ))->forDeparture($circuitId, $departureId, $departureDate, $occupancy, time()) + $answer;
    } catch (\Throwable $e) {
        fn_log_event('general', 'runtime', [
            'message' => 'Sphinx circuit quote failed: ' . $e->getMessage(),
            'circuit_id' => $circuitId,
        ]);
    }
}

// JSON, never the page around it (as travel_booking.booking_config).
$json = json_encode($answer, JSON_UNESCAPED_UNICODE);
while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
echo $json;
exit;
