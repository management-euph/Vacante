<?php

declare(strict_types=1);
/**
 * eurosite_booking.package_quote — AJAX JSON: "Camere și prețuri" on a
 * Eurosite hotel's page in a package mode (board 16.2, Travel Core's
 * PackageRooms.jsx): the hotel's packages for one transport, departure city,
 * date and length, for the customer's rooms (PackageQuote).
 *
 * Only a hotel the store sells, on a departure its package routes have (that
 * transport, city, date and length), with rooms within the guest picker's
 * limits, is asked about: the endpoint never relays an arbitrary request to
 * Eurosite.
 */

use Tygh\Addons\Eurosite\Services\ConfigProvider;
use Tygh\Addons\Eurosite\Services\Container;
use Tygh\Addons\Eurosite\Services\PackageDepartures;
use Tygh\Addons\Eurosite\Services\PackageQuote;
use Tygh\Addons\Eurosite\Services\RoomOccupancy;
use Tygh\Addons\TravelCore\Helpers\RequestCoerce;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\ProviderRoomLimit;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

$hotelId = strtoupper((string) preg_replace('/[^A-Za-z0-9_]/', '', RequestCoerce::string($_REQUEST, 'hotel_id')));
$transport = RequestCoerce::string($_REQUEST, 'transport');
$departureId = RequestCoerce::int($_REQUEST, 'departure_id');
$departureDate = RequestCoerce::string($_REQUEST, 'departure_date');
$nights = RequestCoerce::int($_REQUEST, 'nights');
$childrenAges = [];
foreach (explode(',', RequestCoerce::string($_REQUEST, 'children_ages')) as $age) {
    if ($age !== '' && is_numeric($age)) {
        $childrenAges[] = (int) $age;
    }
}
$occupancy = RoomOccupancy::fromRequest(
    RequestCoerce::string($_REQUEST, 'rooms_data'),
    max(1, RequestCoerce::int($_REQUEST, 'adults', 2)),
    $childrenAges,
);
$totals = RoomOccupancy::totals($occupancy);

$answer = [
    'blocks' => [],
    'adults' => $totals['adults'],
    'children' => count($totals['children_ages']),
    'error' => true,
];

$hotel = $hotelId !== '' ? Container::hotels()->findByProductCode($hotelId) : null;
$departure = $hotel !== null
    ? PackageDepartures::offered(
        PackageDepartures::forHotel($hotelId, date('Y-m-d'), ConfigProvider::getPackageNights()),
        $transport,
        $departureId,
        $departureDate,
        $nights,
    )
    : null;
if ($hotel !== null && $departure !== null && PackageQuote::validOccupancy($occupancy, ProviderRoomLimit::maxRooms('eurosite'))) {
    // One search, past PHP's usual 30 s when Eurosite is slow.
    if (function_exists('set_time_limit')) {
        set_time_limit(60);
    }
    try {
        $answer['blocks'] = (new PackageQuote(
            Container::getApi(),
            Container::packageOfferTokens(),
            TypeCoerce::toString(__('travel_core.pdpa_r_room_only')),
        ))->blocks(
            [
                'product_code' => TypeCoerce::toString($hotel['product_code'] ?? ''),
                'tourop_code' => TypeCoerce::toString($hotel['tourop_code'] ?? ''),
                'country_code' => strtoupper(TypeCoerce::toString($hotel['country_code'] ?? '')),
                'city_code' => strtoupper(TypeCoerce::toString($hotel['city_code'] ?? '')),
            ],
            $departure,
            $transport,
            $departureDate,
            $nights,
            $occupancy,
            ConfigProvider::getDefaultCurrency(),
            ConfigProvider::getDefaultLanguage(),
        );
        $answer['error'] = false;
    } catch (\Throwable $e) {
        fn_log_event('general', 'runtime', ['message' => 'Eurosite package quote failed: ' . $e->getMessage(), 'hotel_id' => $hotelId]);
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
