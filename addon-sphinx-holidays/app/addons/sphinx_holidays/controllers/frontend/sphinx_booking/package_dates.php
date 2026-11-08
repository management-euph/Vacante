<?php
declare(strict_types=1);
/**
 * Sphinx Booking Controller — Package Dates Mode (JSON)
 *
 * The dates a hotel can be reached by one transport from one city, each
 * with its lengths (nights), for the package bar on the hotel's page
 * (board 16.2, travel_core PackageBar.jsx): PackageDepartures, from the
 * saved routes. No API call.
 *
 * Only a hotel the store sells is asked about.
 *
 * @package SphinxHolidays
 */
if (!defined('BOOTSTRAP')) { exit('Access denied'); }

use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\SphinxHolidays\Services\PackageDepartures;
use Tygh\Addons\TravelCore\Helpers\RequestCoerce;

$hotelId = RequestCoerce::string($_REQUEST, 'hotel_id');
$transport = RequestCoerce::string($_REQUEST, 'transport');
$departureId = RequestCoerce::int($_REQUEST, 'departure_id');

$answer = ['dates' => [], 'error' => false];
if (preg_match('/^\d+$/', $hotelId) === 1 && Container::getCartService()->resolveProductId($hotelId) > 0) {
    $answer['dates'] = PackageDepartures::dates(PackageDepartures::forHotel($hotelId, date('Y-m-d')), $transport, $departureId);
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
