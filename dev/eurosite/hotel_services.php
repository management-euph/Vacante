<?php

declare(strict_types=1);

/**
 * Eurosite · getHotelServiceTypesRequest — a hotel offer's supplements
 * (spec p. 26 and p. 61): Type 2 meals, 6 other services, 7 transport, with
 * a CharterId when the transport is a charter. With --price=CODE, then
 * getHotelServicePriceRequest: what one of them costs for the persons given.
 * A supplement is booked with the hotel (AddBooking SuppServices, Code and
 * Type from here). Read-only: nothing is booked.
 *
 * Usage (CLI):
 *   php hotel_services.php --country=BG --city=BGNSPDAR --product=BG0063 --variant='0|1065798_1447_0' \
 *       --check-in=2027-07-03 [--nights=7] [--tourop=EU] [--price=1094 --type=2 --adults=2 --children=8]
 *   php hotel_services.php --code=EUS-LA-RO0486 --variant=… --check-in=…   (a store hotel; its city,
 *       in the docker store, from the store's own Eurosite hotel list)
 * Usage (browser):
 *   hotel_services.php?country=BG&city=BGNSPDAR&product=BG0063&variant=0|1065798_1447_0&check_in=2027-07-03
 *
 * The variant is an offer's <PackageVariantId> from hotel_search.php, for
 * the same stay. With the store's account, the offer picked by itself:
 * dev/tools/eurosite-packages.php (step 5).
 */

require __DIR__ . '/_eurosite_client.php';

if (euro_wants_help()) {
    euro_out_setup();
    echo "hotel_services — Eurosite supplements of a hotel offer (meals, other, transport)\n"
       . "  --country=XX  --city=CODE  --product=CODE   the hotel\n"
       . "  --code=EUS-LA-RO0486  a store hotel instead: tour operator and hotel,\n"
       . "                and (docker store) its country and city\n"
       . "  --tourop=CODE the hotel's tour operator (default EU)\n"
       . "  --variant=ID  the offer's PackageVariantId, from hotel_search.php (required)\n"
       . "  --check-in=Y-m-d  --nights=N (default 7)   the offer's stay\n"
       . "  --price=CODE  also the price of this supplement   --type=N its type (default 7)\n"
       . "  --adults=N    (default 2)    --children=8,5  child ages   (for --price)\n";
    exit;
}

$cfg      = euro_config();
$country  = strtoupper(euro_param('country', 'RO') ?? 'RO');
$city     = strtoupper(trim(euro_param('city', '') ?? ''));
$product  = strtoupper(trim(euro_param('product', '') ?? ''));
$tourop   = strtoupper(trim(euro_param('tourop', '') ?? ''));
if (preg_match('/^EUS-([A-Z0-9]+)-([A-Z0-9_]+)$/', strtoupper(trim(euro_param('code', '') ?? '')), $m) === 1) {
    [$tourop, $product] = [$m[1], $m[2]];
}
$storeHotel = $city === '' ? euro_store_hotel($tourop, $product) : [];
if ($storeHotel !== []) {
    [$country, $city] = [$storeHotel['country'] !== '' ? strtoupper($storeHotel['country']) : $country, $storeHotel['city']];
}
$tourop   = $tourop !== '' ? $tourop : 'EU';
$variant  = trim(euro_param('variant', '') ?? '');
$checkIn  = euro_param('check-in', euro_param('check_in', '')) ?? '';
$nights   = max(1, (int) (euro_param('nights', '7') ?? '7'));
$price    = trim(euro_param('price', '') ?? '');
$type     = trim(euro_param('type', '7') ?? '7');
$adults   = max(1, (int) (euro_param('adults', '2') ?? '2'));
$children = trim(euro_param('children', '') ?? '');

if ($city === '' || $product === '' || $variant === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkIn) !== 1) {
    euro_out_setup();
    echo "Needs --city=, --product= (or --code=), --variant= and --check-in=YYYY-MM-DD (from hotel_search.php). --help for usage.\n";
    exit(1);
}
$checkOut = date('Y-m-d', (int) strtotime($checkIn . ' + ' . $nights . ' days'));

$where = '<CountryCode>' . euro_esc($country) . '</CountryCode>'
    . '<CityCode>' . euro_esc($city) . '</CityCode>'
    . '<TourOpCode>' . euro_esc($tourop) . '</TourOpCode>'
    . '<ProductCode>' . euro_esc($product) . '</ProductCode>';
$stay = '<PeriodOfStay><CheckIn>' . euro_esc($checkIn) . '</CheckIn><CheckOut>' . euro_esc($checkOut) . '</CheckOut></PeriodOfStay>';

if ($storeHotel !== []) {
    euro_out_setup();
    echo "Store hotel: {$storeHotel['name']} (EUS-{$tourop}-{$product}), country {$country}, city {$city}: from the store's Eurosite hotel list\n";
}

euro_run($cfg, 'getHotelServiceTypesRequest', '  <getHotelServiceTypesRequest>' . $where
    . '<VariantId>' . euro_esc($variant) . '</VariantId>'
    . '<Language>RO</Language>'
    . $stay
    . '</getHotelServiceTypesRequest>');

if ($price !== '') {
    $pax = '';
    for ($i = 1; $i <= $adults; $i++) {
        $pax .= '<PaxName PaxType="adult">ADULT ' . $i . '</PaxName>';
    }
    if ($children !== '') {
        $ages = array_values(array_filter(array_map('intval', explode(',', $children)), static fn (int $a): bool => $a >= 0));
        foreach ($ages as $n => $age) {
            $pax .= '<PaxName PaxType="child" ChildAge="' . $age . '">COPIL ' . ($n + 1) . '</PaxName>';
        }
    }
    euro_run($cfg, 'getHotelServicePriceRequest', '  <getHotelServicePriceRequest>' . $where
        . '<CurrencyCode>EUR</CurrencyCode>'
        . '<VariantId>' . euro_esc($variant) . '</VariantId>'
        . '<Language>RO</Language>'
        . '<Services><Service>'
        . '<ServiceType>' . euro_esc($type) . '</ServiceType>'
        . '<ServiceCode>' . euro_esc($price) . '</ServiceCode>'
        . $stay
        . '<PaxNames>' . $pax . '</PaxNames>'
        . '</Service></Services>'
        . '</getHotelServicePriceRequest>');
}
