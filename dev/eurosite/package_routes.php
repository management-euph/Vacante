<?php

declare(strict_types=1);

/**
 * Eurosite · getPackageNVRoutesRequest — the package module's destinations,
 * departure cities and departure dates (spec "Pachete", p. 46). The spec
 * wants this kept in a local cache, refreshed now and then.
 *
 * Usage (CLI):      php package_routes.php [--transport=search|bus|plane] [--limit=20]
 * Usage (browser):  package_routes.php?transport=bus&limit=20
 *
 * transport: search = every package (default), bus = by coach only,
 * plane = by plane only; run bus and plane apart to tell them apart.
 * For the routes next to the store's own hotels, read-only, with the
 * store's account: dev/tools/eurosite-packages.php.
 */

require __DIR__ . '/_eurosite_client.php';

if (euro_wants_help()) {
    euro_out_setup();
    echo "package_routes — Eurosite package destinations, departures and dates\n"
       . "  --transport=search|bus|plane  (default search = every package)\n"
       . "  --limit=N   first N repeated rows only (the <Date> lists are long)\n";
    exit;
}

$cfg       = euro_config();
$transport = strtolower(trim(euro_param('transport', 'search') ?? 'search'));
$limit     = (int) (euro_param('limit', '0') ?? '0');

if (!in_array($transport, ['search', 'bus', 'plane'], true)) {
    euro_out_setup();
    echo "--transport must be search, bus or plane. --help for usage.\n";
    exit(1);
}

$details = '  <getPackageNVRoutesRequest>'
    . '<Transport>' . euro_esc($transport) . '</Transport>'
    . '</getPackageNVRoutesRequest>';

euro_run($cfg, 'getPackageNVRoutesRequest', $details, $limit);
