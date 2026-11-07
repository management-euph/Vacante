<?php

declare(strict_types=1);

/**
 * Eurosite · getPackageNVPriceRequest — the package search (spec "Pachete",
 * p. 50): hotels with transport from a departure city, each offer with its
 * PackageId + PackageVariantId (the booking keys) and its services (the bus
 * or the flights with times and seats, transfers, airport tax, package fee).
 * Read-only: nothing is booked.
 *
 * Usage (CLI):
 *   php package_search.php --country=BG --city=BGNIS --dep-city=ROBCH1 --transport=bus \
 *       --check-in=2027-07-03 --nights=7 [--dep-country=RO] [--zone=BGVAR] [--adults=2] \
 *       [--children=8,5] [--room=DB] [--tourop=LA] [--days=1] [--limit=5]
 * Usage (browser):
 *   package_search.php?country=BG&city=BGNIS&dep_city=ROBCH1&transport=bus&check_in=2027-07-03&nights=7&limit=5
 *
 * The cities and dates come from package_routes.php. For the answer next to
 * the store's own hotels: dev/tools/eurosite-packages.php.
 */

require __DIR__ . '/_eurosite_client.php';

if (euro_wants_help()) {
    euro_out_setup();
    echo "package_search — Eurosite package search (hotel + transport)\n"
       . "  --country=XX        destination country (default BG)\n"
       . "  --city=CODE         destination city, from package_routes.php (required)\n"
       . "  --zone=CODE         search the destination's whole zone instead\n"
       . "  --dep-country=XX    departure country (default RO)\n"
       . "  --dep-city=CODE     departure city, from package_routes.php (required)\n"
       . "  --transport=bus|plane|search  (default search)\n"
       . "  --check-in=Y-m-d    a departure date from package_routes.php (required)\n"
       . "  --nights=N          (default 7)    --days=N  search margin 1-3 days (default 1)\n"
       . "  --adults=N          (default 2)    --children=8,5  child ages\n"
       . "  --room=CODE         room code for the occupancy (default DB)\n"
       . "  --tourop=CODE       only this tour operator's offers\n"
       . "  --limit=N           first N repeated rows only\n";
    exit;
}

$cfg        = euro_config();
$country    = strtoupper(euro_param('country', 'BG') ?? 'BG');
$city       = strtoupper(trim(euro_param('city', '') ?? ''));
$zone       = strtoupper(trim(euro_param('zone', '') ?? ''));
$depCountry = strtoupper(euro_param('dep-country', euro_param('dep_country', 'RO')) ?? 'RO');
$depCity    = strtoupper(trim(euro_param('dep-city', euro_param('dep_city', '')) ?? ''));
$transport  = strtolower(trim(euro_param('transport', 'search') ?? 'search'));
$checkIn    = euro_param('check-in', euro_param('check_in', '')) ?? '';
$nights     = max(1, (int) (euro_param('nights', '7') ?? '7'));
$days       = max(1, min(3, (int) (euro_param('days', '1') ?? '1')));
$adults     = max(1, (int) (euro_param('adults', '2') ?? '2'));
$children   = trim(euro_param('children', '') ?? '');
$room       = euro_param('room', 'DB') ?? 'DB';
$tourop     = strtoupper(trim(euro_param('tourop', '') ?? ''));
$limit      = (int) (euro_param('limit', '0') ?? '0');

if ($city === '' || $depCity === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkIn) !== 1) {
    euro_out_setup();
    echo "Needs --city=, --dep-city= and --check-in=YYYY-MM-DD (from package_routes.php). --help for usage.\n";
    exit(1);
}
if (!in_array($transport, ['search', 'bus', 'plane'], true)) {
    euro_out_setup();
    echo "--transport must be search, bus or plane. --help for usage.\n";
    exit(1);
}

$checkOut = date('Y-m-d', (int) strtotime($checkIn . ' + ' . $nights . ' days'));

$roomXml = '<Room Code="' . euro_esc($room) . '" NoAdults="' . $adults . '"';
if ($children !== '') {
    $ages = array_filter(array_map('intval', explode(',', $children)), static fn (int $a): bool => $a >= 0);
    $roomXml .= ' NoChildren="' . count($ages) . '"><Children>';
    foreach ($ages as $age) {
        $roomXml .= '<Age>' . $age . '</Age>';
    }
    $roomXml .= '</Children></Room>';
} else {
    $roomXml .= '/>';
}

$details = '  <getPackageNVPriceRequest>'
    . '<CountryCode>' . euro_esc($country) . '</CountryCode>'
    . '<CityCode>' . euro_esc($city) . '</CityCode>'
    . ($zone !== '' ? '<Zone>' . euro_esc($zone) . '</Zone>' : '')
    . '<DepCountryCode>' . euro_esc($depCountry) . '</DepCountryCode>'
    . '<DepCityCode>' . euro_esc($depCity) . '</DepCityCode>'
    . '<Transport>' . euro_esc($transport) . '</Transport>'
    . ($tourop !== '' ? '<TourOpCode>' . euro_esc($tourop) . '</TourOpCode>' : '')
    . '<CurrencyCode>EUR</CurrencyCode>'
    . '<Language>RO</Language>'
    . '<OfferType>TOATE</OfferType>'
    . '<PeriodOfStay><CheckIn>' . euro_esc($checkIn) . '</CheckIn><CheckOut>' . euro_esc($checkOut) . '</CheckOut></PeriodOfStay>'
    . '<Days>' . $days . '</Days>'
    . '<Rooms>' . $roomXml . '</Rooms>'
    . '</getPackageNVPriceRequest>';

euro_run($cfg, 'getPackageNVPriceRequest', $details, $limit);
