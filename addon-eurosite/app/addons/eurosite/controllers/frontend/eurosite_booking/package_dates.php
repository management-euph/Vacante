<?php

declare(strict_types=1);
/**
 * eurosite_booking.package_dates — AJAX JSON: one departure city's dates on a
 * Eurosite hotel's page in a package mode (board 16.2, Travel Core's
 * PackageBar.jsx), each with the stays offered, from the package routes
 * (PackageDepartures). No API call.
 *
 * Only a hotel the store sells is asked about.
 */

use Tygh\Addons\Eurosite\Services\ConfigProvider;
use Tygh\Addons\Eurosite\Services\PackageDepartures;
use Tygh\Addons\TravelCore\Helpers\RequestCoerce;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

$hotelId = strtoupper((string) preg_replace('/[^A-Za-z0-9_]/', '', RequestCoerce::string($_REQUEST, 'hotel_id')));

$answer = ['dates' => [], 'error' => false];
if ($hotelId !== '') {
    try {
        $answer['dates'] = PackageDepartures::dates(
            PackageDepartures::forHotel($hotelId, date('Y-m-d'), ConfigProvider::getPackageNights()),
            RequestCoerce::string($_REQUEST, 'transport'),
            RequestCoerce::int($_REQUEST, 'departure_id'),
        );
    } catch (\Throwable $e) {
        $answer['error'] = true;
        fn_log_event('general', 'runtime', ['message' => 'Eurosite package dates failed: ' . $e->getMessage(), 'hotel_id' => $hotelId]);
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
