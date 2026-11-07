<?php

declare(strict_types=1);

/**
 * Eurosite · getCharterCitiesRequest — the charter module's cities (spec
 * "Transport", p. 84): the spec asks it twice, once for the departure
 * cities, once for the arrival ones.
 *
 * Usage (CLI):      php charter_cities.php [--type=departure|arrival|both] [--limit=20]
 * Usage (browser):  charter_cities.php?type=arrival&limit=20
 *
 * Read-only. The codes feed charter_search.php. For the cities next to the
 * store's own hotels, with the store's account, and a sample search:
 * dev/tools/eurosite-packages.php.
 */

require __DIR__ . '/_eurosite_client.php';

if (euro_wants_help()) {
    euro_out_setup();
    echo "charter_cities — Eurosite charter departure and arrival cities\n"
       . "  --type=departure|arrival|both  (default both: two calls)\n"
       . "  --limit=N   first N repeated rows only\n";
    exit;
}

$cfg   = euro_config();
$type  = strtolower(trim(euro_param('type', 'both') ?? 'both'));
$limit = (int) (euro_param('limit', '0') ?? '0');

if (!in_array($type, ['departure', 'arrival', 'both'], true)) {
    euro_out_setup();
    echo "--type must be departure, arrival or both. --help for usage.\n";
    exit(1);
}

foreach ($type === 'both' ? ['departure', 'arrival'] : [$type] as $departureType) {
    $details = '  <getCharterCitiesRequest>'
        . '<DepartureType>' . euro_esc($departureType) . '</DepartureType>'
        . '</getCharterCitiesRequest>';
    euro_run($cfg, 'getCharterCitiesRequest', $details, $limit);
}
