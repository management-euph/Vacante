<?php
declare(strict_types=1);
/**
 * Sphinx Booking Controller — Circuit Dates Mode (JSON)
 *
 * The departure dates of a circuit from one city, each with its price (2
 * adults, commission included), for the circuit bar on the circuit's page
 * (board 16.3, travel_core CircuitBar.jsx): CircuitDepartureDates.
 *
 * Only an active circuit of the store is asked about, so the endpoint never
 * relays an arbitrary id to Sphinx.
 *
 * @package SphinxHolidays
 */
if (!defined('BOOTSTRAP')) { exit('Access denied'); }

use Tygh\Addons\SphinxHolidays\Services\CircuitDepartureDates;
use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\TravelCore\Helpers\RequestCoerce;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

$circuitId = RequestCoerce::int($_REQUEST, 'circuit_id');
$departureId = RequestCoerce::int($_REQUEST, 'departure_id');

$answer = ['departures' => [], 'error' => false];
$known = $circuitId > 0 && TypeCoerce::toInt(db_get_field(
    "SELECT circuit_id FROM ?:sphinx_circuits WHERE circuit_id = ?i AND sync_status = 'active'",
    $circuitId,
)) === $circuitId;
if ($known) {
    try {
        $answer = (new CircuitDepartureDates(
            Container::getApi(),
            static fn (float $price): float => Container::getCartService()->applyCommission($price),
        ))->forDeparture($circuitId, $departureId, new \DateTimeImmutable('today'));
    } catch (\Throwable $e) {
        fn_log_event('general', 'runtime', [
            'message' => 'Sphinx circuit dates failed: ' . $e->getMessage(),
            'circuit_id' => $circuitId,
        ]);
        $answer['error'] = true;
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
