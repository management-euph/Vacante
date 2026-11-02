<?php

declare(strict_types=1);

/**
 * Eurosite · getHotelPriceRequest — availability + price search (the main
 * "individual accommodations" search).
 *
 * Usage (CLI):
 *   php hotel_search.php --country=RO --city=ROMM --check-in=2026-08-10 \
 *       --nights=7 --adults=2 [--children=8,5] [--room=DB] [--limit=5]
 *       [--tourop=LA --product=RO0486]   (one hotel: the store's EUS-LA-RO0486)
 * Usage (browser):
 *   hotel_search.php?country=RO&city=ROMM&check_in=2026-08-10&nights=7&adults=2&limit=5
 */

require __DIR__ . '/_eurosite_client.php';

if (euro_wants_help()) {
    euro_out_setup();
    echo "hotel_search — Eurosite availability + price search\n"
       . "  --country=XX      2-letter country code (default RO)\n"
       . "  --city=CODE       city code from cities.php (required)\n"
       . "  --check-in=Y-m-d  (default: +30 days)   --nights=N (default 7)\n"
       . "  --adults=N        (default 2)\n"
       . "  --children=8,5    child ages, comma separated (default none)\n"
       . "  --room=CODE       room code for the occupancy (default DB)\n"
       . "  --tourop=CODE     only this tour operator's offers (e.g. LA)\n"
       . "  --product=CODE    only this hotel (needs --tourop): the store's\n"
       . "                    EUS-LA-RO0486 is --tourop=LA --product=RO0486\n"
       . "  --limit=N         first N repeated rows only\n";
    exit;
}

$cfg      = euro_config();
$country  = strtoupper(euro_param('country', 'RO') ?? 'RO');
$city     = euro_param('city', '') ?? '';
$checkIn  = euro_param('check-in', euro_param('check_in', date('Y-m-d', strtotime('+30 days')))) ?? '';
$nights   = max(1, (int) (euro_param('nights', '7') ?? '7'));
$adults   = max(1, (int) (euro_param('adults', '2') ?? '2'));
$children = trim(euro_param('children', '') ?? '');
$room     = euro_param('room', 'DB') ?? 'DB';
$limit    = (int) (euro_param('limit', '0') ?? '0');
$tourop   = strtoupper(trim(euro_param('tourop', '') ?? ''));
$product  = strtoupper(trim(euro_param('product', '') ?? ''));

if ($city === '') {
    euro_out_setup();
    echo "Missing --city= (find codes with cities.php). --help for usage.\n";
    exit(1);
}

if ($product !== '' && $tourop === '') {
    euro_out_setup();
    echo "--product= needs --tourop= too (Eurosite asks for both). --help for usage.\n";
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

$details = '  <getHotelPriceRequest>'
    . '<CountryCode>' . euro_esc($country) . '</CountryCode>'
    . '<CityCode>' . euro_esc($city) . '</CityCode>'
    . ($tourop !== '' ? '<TourOpCode>' . euro_esc($tourop) . '</TourOpCode>' : '')
    . ($product !== '' ? '<ProductCode>' . euro_esc($product) . '</ProductCode>' : '')
    . '<PeriodOfStay><CheckIn>' . euro_esc($checkIn) . '</CheckIn><CheckOut>' . euro_esc($checkOut) . '</CheckOut></PeriodOfStay>'
    . '<Rooms>' . $roomXml . '</Rooms>'
    . '</getHotelPriceRequest>';

euro_run($cfg, 'getHotelPriceRequest', $details, $limit);
