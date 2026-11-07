<?php

declare(strict_types=1);

/**
 * Eurosite · getCharterPriceRequest — the charter search (spec "Transport",
 * p. 86): the flights or buses out (<Charter Type="Departure">) and back
 * (Type="Return"), each offer with its times, seats, price and CharterId.
 * A trip searched both ways is booked both ways too. Read-only: nothing is
 * booked.
 *
 * Usage (CLI):
 *   php charter_search.php --from=ROBCH1 --to=GRHRK --date=2027-07-03 [--nights=7 | --return=2027-07-10]
 *       [--from-country=RO] [--to-country=GR] [--transport=search|plane|bus] [--flexible=0]
 *       [--adults=2] [--children=8,5] [--limit=5]
 * Usage (browser):
 *   charter_search.php?from=ROBCH1&to=GRHRK&date=2027-07-03&nights=7&limit=5
 *
 * The city codes come from charter_cities.php. A country defaults to its
 * city code's first two letters (ROBCH1 → RO), which is not always the
 * country (the spec lists Hong Kong, HKHNGKNG2, under CN): then give it.
 */

require __DIR__ . '/_eurosite_client.php';

if (euro_wants_help()) {
    euro_out_setup();
    echo "charter_search — Eurosite charter search (a flight or a bus, out and back)\n"
       . "  --from=CODE         departure city, from charter_cities.php (required)\n"
       . "  --to=CODE           arrival city, from charter_cities.php (required)\n"
       . "  --from-country=XX   (default: the code's first two letters)\n"
       . "  --to-country=XX     (default: the code's first two letters)\n"
       . "  --date=Y-m-d        the way out (required)\n"
       . "  --nights=N          the way back N days later (default 7), or --return=Y-m-d\n"
       . "  --flexible=0        the exact dates only (default: the days around them too)\n"
       . "  --transport=search|plane|bus  (default search = every charter)\n"
       . "  --adults=N          (default 2)    --children=8,5  child ages\n"
       . "  --limit=N           first N repeated rows only\n";
    exit;
}

$cfg         = euro_config();
$from        = strtoupper(trim(euro_param('from', '') ?? ''));
$to          = strtoupper(trim(euro_param('to', '') ?? ''));
$fromCountry = strtoupper(trim(euro_param('from-country', euro_param('from_country', substr($from, 0, 2))) ?? ''));
$toCountry   = strtoupper(trim(euro_param('to-country', euro_param('to_country', substr($to, 0, 2))) ?? ''));
$date        = euro_param('date', '') ?? '';
$nights      = max(1, (int) (euro_param('nights', '7') ?? '7'));
$return      = euro_param('return', '') ?? '';
$flexible    = (euro_param('flexible', '1') ?? '1') !== '0';
$transport   = strtolower(trim(euro_param('transport', 'search') ?? 'search'));
$adults      = max(1, (int) (euro_param('adults', '2') ?? '2'));
$children    = trim(euro_param('children', '') ?? '');
$limit       = (int) (euro_param('limit', '0') ?? '0');

if ($from === '' || $to === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || ($return !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $return) !== 1)) {
    euro_out_setup();
    echo "Needs --from=, --to= (from charter_cities.php) and --date=YYYY-MM-DD (--return=YYYY-MM-DD too, if given). --help for usage.\n";
    exit(1);
}
if (!in_array($transport, ['search', 'plane', 'bus'], true)) {
    euro_out_setup();
    echo "--transport must be search, plane or bus. --help for usage.\n";
    exit(1);
}
if ($return === '') {
    $return = date('Y-m-d', (int) strtotime($date . ' + ' . $nights . ' days'));
}

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

$details = '  <getCharterPriceRequest>'
    . '<Transport>' . euro_esc($transport) . '</Transport>'
    . '<Departure><CountryCode>' . euro_esc($fromCountry) . '</CountryCode><CityCode>' . euro_esc($from) . '</CityCode></Departure>'
    . '<Destination><CountryCode>' . euro_esc($toCountry) . '</CountryCode><CityCode>' . euro_esc($to) . '</CityCode></Destination>'
    . '<DepartureDate>' . euro_esc($date) . '</DepartureDate>'
    . '<CurrencyCode>EUR</CurrencyCode>'
    . '<ReturnDate>' . euro_esc($return) . '</ReturnDate>'
    . '<FlexibleDate>' . ($flexible ? 'true' : 'false') . '</FlexibleDate>'
    . '<Language>RO</Language>'
    . '<PaxNames>' . $pax . '</PaxNames>'
    . '</getCharterPriceRequest>';

euro_run($cfg, 'getCharterPriceRequest', $details, $limit);
