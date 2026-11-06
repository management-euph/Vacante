<?php

declare(strict_types=1);

/**
 * Eurosite · getProductInfoRequest — hotel details (description, pictures,
 * coordinates) for one product.
 *
 * Usage (CLI):
 *   php product_info.php --country=RO --city=ROMM --product=RO0099 [--tourop=LA] [--limit=5]
 *   php product_info.php --code=EUS-LA-RO0486   (a store hotel; its city, in
 *       the docker store, from the store's own Eurosite hotel list)
 * Usage (browser):
 *   product_info.php?country=RO&city=ROMM&product=RO0099&limit=5
 *   product_info.php?code=EUS-LA-RO0486
 *
 * After the answer, the facility chips the store's hotel page shows: the
 * store's own parser (FacilityTextParser) over <DescriptionDet>, where the
 * operator writes "Facilitati: …". getProductInfo has no structured
 * facility list; Eurosite's numbered facilities are only a search filter.
 */

require __DIR__ . '/_eurosite_client.php';

if (euro_wants_help()) {
    euro_out_setup();
    echo "product_info — Eurosite hotel/product details\n"
       . "  --country=XX  --city=CODE  --product=CODE (all required)\n"
       . "  --tourop=CODE the hotel's tour operator (e.g. LA)\n"
       . "  --code=EUS-LA-RO0486  a store hotel instead: tour operator and hotel,\n"
       . "                and (docker store) its country and city\n"
       . "  --type=hotel  product type (default hotel)\n"
       . "  --limit=N     first N repeated rows only (pictures usually)\n";
    exit;
}

$cfg     = euro_config();
$country = strtoupper(euro_param('country', 'RO') ?? 'RO');
$city    = euro_param('city', '') ?? '';
$product = euro_param('product', '') ?? '';
$type    = euro_param('type', 'hotel') ?? 'hotel';
$tourop  = strtoupper(trim(euro_param('tourop', '') ?? ''));
if (preg_match('/^EUS-([A-Z0-9]+)-([A-Z0-9_]+)$/', strtoupper(trim(euro_param('code', '') ?? '')), $m) === 1) {
    [$tourop, $product] = [$m[1], $m[2]];
}
$storeHotel = $city === '' ? euro_store_hotel($tourop, $product) : [];
if ($storeHotel !== []) {
    [$country, $city] = [$storeHotel['country'] !== '' ? strtoupper($storeHotel['country']) : $country, $storeHotel['city']];
}
$limit   = (int) (euro_param('limit', '0') ?? '0');

if ($city === '' || $product === '') {
    euro_out_setup();
    echo "Missing --city= / --product= (find codes with hotel_search.php). --help for usage.\n";
    exit(1);
}

$details = '  <getProductInfoRequest>'
    . '<CountryCode>' . euro_esc($country) . '</CountryCode>'
    . '<CityCode>' . euro_esc($city) . '</CityCode>'
    . ($tourop !== '' ? '<TourOpCode>' . euro_esc($tourop) . '</TourOpCode>' : '')
    . '<ProductCode>' . euro_esc($product) . '</ProductCode>'
    . '<ProductType>' . euro_esc($type) . '</ProductType>'
    . '</getProductInfoRequest>';

if ($storeHotel !== []) {
    euro_out_setup();
    echo "Store hotel: {$storeHotel['name']} (EUS-{$tourop}-{$product}), country {$country}, city {$city}: from the store's Eurosite hotel list\n";
}

$body = euro_run($cfg, 'getProductInfoRequest', $details, $limit);

// The facility chips the store's hotel page shows: the store's own parser
// over <DescriptionDet> (the store's copy inside the docker store, the
// repo's from a checkout; skipped when neither is there).
foreach ([dirname(__DIR__, 2) . '/app/addons/eurosite/src/Services/FacilityTextParser.php',
          dirname(__DIR__, 2) . '/addon-eurosite/app/addons/eurosite/src/Services/FacilityTextParser.php'] as $parser) {
    if (is_file($parser)) {
        require_once $parser;
        break;
    }
}
$xml = $body !== '' ? @simplexml_load_string($body) : false;
if ($xml !== false && class_exists(\Tygh\Addons\Eurosite\Services\FacilityTextParser::class)) {
    $det = $xml->xpath('//DescriptionDet');
    $chips = \Tygh\Addons\Eurosite\Services\FacilityTextParser::facilities(is_array($det) && $det !== [] ? (string) $det[0] : '');
    euro_section('FACILITY CHIPS (as the store reads <DescriptionDet>)');
    echo $chips !== []
        ? implode("\n", array_map(static fn (string $chip): string => '  - ' . $chip, $chips)) . "\n"
        : "(none: the text has no \"Facilitati:\" line)\n";
}
