<?php
declare(strict_types=1);
/**
 * Sphinx Booking Controller — Package Quote Mode (JSON)
 *
 * "Camere și prețuri" on a hotel's page in a package mode (board 16.2,
 * travel_core PackageRooms.jsx): this hotel's packages for one transport,
 * departure city, date and length, the customer's rooms, each offer with its
 * price for the whole group (PackageHotelOffers).
 *
 * Only a hotel the store sells, and a departure its saved routes have
 * (PackageDepartures: that transport, city, date and length), with rooms
 * within the guest picker's limits, is asked about: the endpoint never relays
 * an arbitrary request to Sphinx.
 *
 * @package SphinxHolidays
 */
if (!defined('BOOTSTRAP')) { exit('Access denied'); }

use Tygh\Addons\SphinxHolidays\Helpers\SearchOccupancyResolver;
use Tygh\Addons\SphinxHolidays\Services\CircuitRoomOffers;
use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;
use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\SphinxHolidays\Services\PackageDepartures;
use Tygh\Addons\SphinxHolidays\Services\PackageHotelOffers;
use Tygh\Addons\TravelCore\Helpers\RequestCoerce;
use Tygh\Addons\TravelCore\Services\ProviderRoomLimit;

$hotelId = RequestCoerce::string($_REQUEST, 'hotel_id');
$transport = RequestCoerce::string($_REQUEST, 'transport');
$departureId = RequestCoerce::int($_REQUEST, 'departure_id');
$departureDate = RequestCoerce::string($_REQUEST, 'departure_date');
$nights = RequestCoerce::int($_REQUEST, 'nights');
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
$asked = preg_match('/^\d+$/', $hotelId) === 1
    && Container::getCartService()->resolveProductId($hotelId) > 0
    && PackageDepartures::offers(PackageDepartures::forHotel($hotelId, date('Y-m-d')), $transport, $departureId, $departureDate, $nights)
    && CircuitRoomOffers::validOccupancy($occupancy, ProviderRoomLimit::maxRooms('sphinx'));
if ($asked) {
    try {
        $answer = (new PackageHotelOffers(
            Container::getApi(),
            static fn (float $price): float => Container::getCartService()->applyCommission($price),
            ConfigProvider::isApiCacheEnabled() ? Container::getCacheService() : null,
            ConfigProvider::getCacheTtlSearch(),
            ConfigProvider::getSearchMaxPolls(),
            static function (): void {
                usleep(700_000);
            },
        ))->forHotel($hotelId, $transport, $departureId, $departureDate, $nights, $occupancy) + $answer;
    } catch (\Throwable $e) {
        fn_log_event('general', 'runtime', [
            'message' => 'Sphinx package quote failed: ' . $e->getMessage(),
            'hotel_id' => $hotelId,
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
