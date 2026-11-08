<?php

/**
 * sphinx-package-modes.php — which of this store's Sphinx hotels can be sold
 * accommodation only, by bus and by plane (board 16.2), read-only.
 *
 * WHY: board 16.2 puts "Doar cazare / Cu autocarul / Cu avionul" on the
 * hotel's own page, each mode only where the hotel has it. Before building
 * it, a few facts decide its shape:
 *   1. where the saved package routes go (?:sphinx_package_routes, from the
 *      package routes sync), by bus and by plane;
 *   2. how many of the store's hotels sit in each of those destinations (the
 *      hotel's own destination or one of its parents, ?:sphinx_destinations);
 *   3. which hotels a package search really returns there, by bus and by
 *      plane, and which of them the store sells (a buyable product);
 *   4. examples of a hotel with all three: its packages by bus and by plane,
 *      and its own accommodation-only offers (the hotel search);
 *   5. whether a package search can ask for one hotel: by hotel_ids, or by
 *      the hotel's own destination. The hotel search returns no offer with
 *      hotel_ids (controllers/frontend/sphinx_booking/search.php), so the
 *      hotel page searches the destination and keeps its hotel.
 *
 * Read-only: package and hotel searches only (never verify, customize or
 * book); nothing is written to the database or the cache. Prices are
 * Sphinx's (before the store's commission), then the store's (with it).
 * The API address and key are never printed.
 *
 * WHERE: the fullstore sandbox only — docker/fullstore/docker-compose.yml
 * bind-mounts dev/ at /var/www/html/dev. Refuses non-localhost HTTP requests.
 *
 * USE:
 *   http://localhost:8080/dev/tools/sphinx-package-modes.php
 *       → the routes, then the 3 destinations reached by bus and by plane
 *         with the most store hotels
 *   ...?sample=5             → search 5 destinations
 *   ...?arrival=87819        → search this destination only
 *   ...?from=Cluj&nights=7   → the departure city (its name contains) and the nights preferred
 *   ...?examples=5           → check accommodation only for 5 hotels (default 3)
 *   CLI: docker compose exec app php /var/www/html/dev/tools/sphinx-package-modes.php sample=5
 */

use Tygh\Addons\SphinxHolidays\Helpers\SearchOfferNormalizer;
use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;
use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\SphinxHolidays\SphinxApi;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\ProviderCartProduct;

$pm_is_cli = PHP_SAPI === 'cli';

if (!$pm_is_cli) {
    $pm_host = strtolower((string) strtok((string) ($_SERVER['HTTP_HOST'] ?? ''), ':'));
    if (!in_array($pm_host, ['localhost', '127.0.0.1', '[::1]'], true)) {
        http_response_code(403);
        exit("sphinx-package-modes is a local sandbox tool - refusing non-localhost request\n");
    }
    header('Content-Type: text/plain; charset=utf-8');
}

$pm_docroot = dirname(__DIR__, 2);
if (!is_file($pm_docroot . '/init.php')) {
    exit("No CS-Cart init.php at {$pm_docroot} — this tool only works from the fullstore\n"
        . "container, where dev/ is mounted inside the CS-Cart docroot (see docker/fullstore).\n");
}

const AREA = 'A';
const ACCOUNT_TYPE = 'admin';
require $pm_docroot . '/init.php';

set_time_limit(900);

/** The transports a package route and search name. */
const PM_TRANSPORTS = ['bus', 'flight'];

/** Days ahead of the first departure searched (a closer one may not sell). */
const PM_LEAD_DAYS = 3;

function pm_line(string $s = ''): void
{
    echo $s, "\n";
    if (PHP_SAPI !== 'cli') {
        flush();
    }
}

function pm_h(string $title): void
{
    pm_line();
    pm_line('== ' . $title . ' ==');
}

/** A parameter from the query string, or key=value on the command line. */
function pm_param(string $key, string $default = ''): string
{
    if (PHP_SAPI !== 'cli') {
        $value = $_GET[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : $default;
    }
    foreach (array_slice((array) ($GLOBALS['argv'] ?? []), 1) as $arg) {
        if (is_string($arg) && str_starts_with($arg, $key . '=')) {
            $value = trim(substr($arg, strlen($key) + 1));

            return $value !== '' ? $value : $default;
        }
    }

    return $default;
}

/** The text with the API address and key blanked out: the output may be shared. */
function pm_mask(string $text): string
{
    $base = ConfigProvider::getApiBaseUrl();
    $secrets = array_values(array_filter(
        [$base, (string) parse_url($base, PHP_URL_HOST), ConfigProvider::getApiKey()],
        static fn (string $secret): bool => strlen($secret) >= 4,
    ));

    return $secrets === [] ? $text : str_replace($secrets, '***', $text);
}

function pm_money(float $amount, string $currency): string
{
    return number_format($amount, 0, ',', '.') . ' ' . ($currency !== '' ? $currency : 'EUR');
}

/** Sphinx's price, then the store's (with its commission). */
function pm_prices(float $amount, string $currency): string
{
    return pm_money($amount, $currency) . ' (store ' . pm_money(Container::getCartService()->applyCommission($amount), $currency) . ')';
}

/**
 * The synced Sphinx hotels, each with whether the store sells it: a
 * product, active or hidden by the availability gate (ProviderCartProduct).
 *
 * @return array<string, array{name: string, destination_id: int, product_id: int, status: string, gate_hidden: bool, sellable: bool}>
 */
function pm_synced_hotels(): array
{
    $hotels = [];
    foreach ((array) db_get_array(
        'SELECT h.hotel_id, h.name, h.destination_id, h.product_id, h.gate_hidden, p.status
         FROM ?:sphinx_hotels h LEFT JOIN ?:products p ON p.product_id = h.product_id'
    ) as $row) {
        $row = TypeCoerce::toStringMap($row);
        $productId = TypeCoerce::toInt($row['product_id'] ?? 0);
        $hotels[TypeCoerce::toString($row['hotel_id'] ?? '')] = [
            'name' => TypeCoerce::toString($row['name'] ?? ''),
            'destination_id' => TypeCoerce::toInt($row['destination_id'] ?? 0),
            'product_id' => $productId,
            'status' => TypeCoerce::toString($row['status'] ?? ''),
            'gate_hidden' => TypeCoerce::toString($row['gate_hidden'] ?? '') === 'Y',
            'sellable' => $productId > 0
                && ProviderCartProduct::canBeBought(TypeCoerce::toString($row['status'] ?? ''), TypeCoerce::toString($row['gate_hidden'] ?? '') === 'Y'),
        ];
    }

    return $hotels;
}

/**
 * Each destination's parent (0 = none) and type (country, region, city…).
 *
 * @return array{0: array<int, int>, 1: array<int, string>}
 */
function pm_destinations(): array
{
    $parents = [];
    $types = [];
    foreach ((array) db_get_array('SELECT destination_id, parent_id, type FROM ?:sphinx_destinations') as $row) {
        $row = TypeCoerce::toStringMap($row);
        $id = TypeCoerce::toInt($row['destination_id'] ?? 0);
        $parents[$id] = TypeCoerce::toInt($row['parent_id'] ?? 0);
        $types[$id] = TypeCoerce::toString($row['type'] ?? '');
    }

    return [$parents, $types];
}

/**
 * A destination and its parents, nearest first.
 *
 * @param array<int, int> $parents
 * @return list<int>
 */
function pm_chain(int $destinationId, array $parents): array
{
    $chain = [];
    while ($destinationId > 0 && !in_array($destinationId, $chain, true) && count($chain) < 12) {
        $chain[] = $destinationId;
        $destinationId = $parents[$destinationId] ?? 0;
    }

    return $chain;
}

/**
 * The saved routes, each with its dates from $fromDate on (sorted).
 *
 * @return list<array{type: string, departure_id: int, departure: string, arrival_id: int, arrival: string, dates: list<string>, nights: int}>
 */
function pm_routes(string $fromDate): array
{
    $routes = [];
    foreach ((array) db_get_array(
        'SELECT transport_type, departure_id, departure_name, arrival_id, arrival_name, available_dates, duration
         FROM ?:sphinx_package_routes'
    ) as $row) {
        $row = TypeCoerce::toStringMap($row);
        $dates = json_decode(TypeCoerce::toString($row['available_dates'] ?? '[]'), true);
        $ahead = array_values(array_filter(
            TypeCoerce::toStringList(is_array($dates) ? $dates : []),
            static fn (string $date): bool => $date >= $fromDate,
        ));
        sort($ahead);
        $routes[] = [
            'type' => TypeCoerce::toString($row['transport_type'] ?? ''),
            'departure_id' => TypeCoerce::toInt($row['departure_id'] ?? 0),
            'departure' => TypeCoerce::toString($row['departure_name'] ?? ''),
            'arrival_id' => TypeCoerce::toInt($row['arrival_id'] ?? 0),
            'arrival' => TypeCoerce::toString($row['arrival_name'] ?? ''),
            'dates' => $ahead,
            'nights' => TypeCoerce::toInt($row['duration'] ?? 0),
        ];
    }

    return $routes;
}

/**
 * The route to search: from the departure city asked for and with the
 * nights asked for when there is one, else the one with the most dates
 * ahead; it is searched on its first date.
 *
 * @param list<array{type: string, departure_id: int, departure: string, arrival_id: int, arrival: string, dates: list<string>, nights: int}> $routes
 * @return array{type: string, departure_id: int, departure: string, arrival_id: int, arrival: string, dates: list<string>, nights: int}|null
 */
function pm_pick(array $routes, string $from, int $nights): ?array
{
    $routes = array_values(array_filter($routes, static fn (array $route): bool => $route['dates'] !== []));
    if ($routes === []) {
        return null;
    }
    $score = static fn (array $route): array => [
        $from !== '' && stripos($route['departure'], $from) !== false ? 0 : 1,
        $route['nights'] === $nights ? 0 : 1,
        -count($route['dates']),
    ];
    usort($routes, static fn (array $a, array $b): int => $score($a) <=> $score($b));

    return $routes[0];
}

/**
 * A search polled to its end (or $maxPolls pages): its offers, or why
 * there are none.
 *
 * @param array<string, mixed>|null $start what the search call answered
 * @param \Closure(string, string): (array<string, mixed>|null) $page the results call (search id, cursor)
 * @return array{error: string, offers: list<array<string, mixed>>}
 */
function pm_poll(SphinxApi $api, ?array $start, \Closure $page, int $maxPolls = 20): array
{
    $searchId = TypeCoerce::toString($start['search_id'] ?? '');
    $cursor = TypeCoerce::toString($start['cursor'] ?? '');
    if ($start === null || ($searchId === '' && $cursor === '')) {
        return ['error' => 'the search did not start: ' . pm_mask($api->lastError()), 'offers' => []];
    }
    $offers = [];
    for ($poll = 0; $poll < $maxPolls && $cursor !== ''; $poll++) {
        $answer = $page($searchId, $cursor);
        if ($answer === null) {
            return ['error' => 'the results call failed: ' . pm_mask($api->lastError()), 'offers' => $offers];
        }
        $rows = TypeCoerce::toRowList($answer['results'] ?? []);
        array_push($offers, ...$rows);
        $cursor = TypeCoerce::toString($answer['cursor'] ?? '');
        if ($rows === [] && $cursor !== '') {
            sleep(1);
        }
    }

    return ['error' => '', 'offers' => $offers];
}

/**
 * @param array<string, mixed> $params
 * @return array{error: string, offers: list<array<string, mixed>>}
 */
function pm_packages(SphinxApi $api, array $params): array
{
    return pm_poll($api, $api->searchPackages($params), $api->getPackageResults(...));
}

/**
 * @param array<string, mixed> $params
 * @return array{error: string, offers: list<array<string, mixed>>}
 */
function pm_stays(SphinxApi $api, array $params): array
{
    return pm_poll($api, $api->searchHotels($params), $api->getHotelResults(...));
}

/**
 * The search request as the store sends it (package_search.php, search.php).
 *
 * @param array<string, mixed> $params
 * @return array<string, mixed>
 */
function pm_request(array $params): array
{
    $params += ['occupancy' => [['adults' => 2, 'children_ages' => []]], 'currency' => ConfigProvider::getDefaultCurrency()];
    $ignore = ConfigProvider::getIgnoreDomains();
    if ($ignore !== '') {
        $params['ignore_domains'] = $ignore;
    }

    return $params;
}

/** @param array<string, mixed> $offer a package offer */
function pm_package_hotel(array $offer): string
{
    return TypeCoerce::toString(TypeCoerce::toStringMap($offer['hotel'] ?? null)['id'] ?? '');
}

/** @param array<string, mixed> $offer a package offer */
function pm_package_price(array $offer): float
{
    return TypeCoerce::toFloat(TypeCoerce::toStringMap($offer['pricing'] ?? null)['selling_price'] ?? 0);
}

/** @param array<string, mixed> $offer a package offer */
function pm_package_currency(array $offer): string
{
    return TypeCoerce::toString(TypeCoerce::toStringMap($offer['pricing'] ?? null)['currency'] ?? '');
}

/** "Double Room · Half Board · flight OTP → CFU (Tarom)" — or "· bus". @param array<string, mixed> $offer a package offer */
function pm_package_line(array $offer): string
{
    $hotel = TypeCoerce::toStringMap($offer['hotel'] ?? null);
    $rooms = array_filter(array_map(
        static fn (array $room): string => TypeCoerce::toString($room['name'] ?? ''),
        TypeCoerce::toRowList($hotel['rooms'] ?? []),
    ));
    $transport = '';
    $outbound = TypeCoerce::toRowList(TypeCoerce::toStringMap($offer['flight'] ?? null)['outbound'] ?? []);
    if ($outbound !== []) {
        $from = TypeCoerce::toString(TypeCoerce::toStringMap($outbound[0]['departure'] ?? null)['code'] ?? '');
        $to = TypeCoerce::toString(TypeCoerce::toStringMap($outbound[count($outbound) - 1]['arrival'] ?? null)['code'] ?? '');
        $airline = TypeCoerce::toString(TypeCoerce::toStringMap($outbound[0]['airline'] ?? null)['name'] ?? '');
        $transport = trim('flight ' . $from . ' → ' . $to . ($airline !== '' ? ' (' . $airline . ')' : ''));
    } elseif (!empty($offer['bus'])) {
        $transport = 'bus';
    }

    return implode(' · ', array_filter([implode(' + ', $rooms), TypeCoerce::toString($hotel['meal_type_name'] ?? ''), $transport]));
}

pm_line('sphinx-package-modes — accommodation only, by bus, by plane (' . date('Y-m-d H:i:s') . ')');

if (!class_exists(Container::class)) {
    exit("sphinx_holidays is not installed/active in this store.\n");
}

$pm_from_date = date('Y-m-d', (int) strtotime('+' . PM_LEAD_DAYS . ' days'));
$pm_sample = max(1, min(10, (int) pm_param('sample', '3')));
$pm_only = (int) pm_param('arrival', '0');
$pm_from = pm_param('from', 'Bucure');
$pm_nights = max(1, (int) pm_param('nights', '7'));
$pm_examples = max(0, min(10, (int) pm_param('examples', '3')));

try {
    $pm_api = Container::getApi();
} catch (\Throwable $e) {
    exit('The Sphinx API client is not available: ' . pm_mask($e->getMessage()) . "\n");
}

// ── 1. The saved routes ─────────────────────────────────────────────────
pm_h('1. Saved package routes (?:sphinx_package_routes), dates from ' . $pm_from_date);
$pm_routes = pm_routes($pm_from_date);
if ($pm_routes === []) {
    exit("No route saved: run the package routes sync first (Sphinx cron, package routes).\n");
}
$pm_types = array_count_values(array_column($pm_routes, 'type'));
ksort($pm_types);
foreach ($pm_types as $pm_type => $pm_count) {
    $pm_of = array_values(array_filter($pm_routes, static fn (array $r): bool => $r['type'] === $pm_type));
    $pm_dates = array_merge(...array_map(static fn (array $r): array => $r['dates'], $pm_of));
    sort($pm_dates);
    $pm_nights_list = array_unique(array_column($pm_of, 'nights'));
    sort($pm_nights_list);
    pm_line(sprintf(
        '%-7s %d routes · %d departure cities · %d destinations · nights %s · dates ahead: %s',
        $pm_type,
        $pm_count,
        count(array_unique(array_column($pm_of, 'departure_id'))),
        count(array_unique(array_column($pm_of, 'arrival_id'))),
        implode('/', $pm_nights_list),
        $pm_dates === [] ? 'none' : count(array_unique($pm_dates)) . ' (' . $pm_dates[0] . ' … ' . $pm_dates[count($pm_dates) - 1] . ')',
    ));
    pm_line('        from: ' . implode(', ', array_slice(array_unique(array_column($pm_of, 'departure')), 0, 12)));
}
$pm_unknown = array_diff(array_keys($pm_types), PM_TRANSPORTS);
if ($pm_unknown !== []) {
    pm_line('Other transport names in the routes: ' . implode(', ', $pm_unknown));
}

// ── 2. Destinations and the store's hotels in them ──────────────────────
pm_h("2. Destinations by bus and by plane, with the store's hotels (theirs or a parent destination)");
$pm_hotels = pm_synced_hotels();
[$pm_parents, $pm_dest_types] = pm_destinations();
/** @var array<int, array{name: string, routes: array<string, list<array{type: string, departure_id: int, departure: string, arrival_id: int, arrival: string, dates: list<string>, nights: int}>>, hotels: list<string>}> $pm_arrivals */
$pm_arrivals = [];
foreach ($pm_routes as $pm_route) {
    $pm_arrivals[$pm_route['arrival_id']] ??= ['name' => $pm_route['arrival'], 'routes' => [], 'hotels' => []];
    if ($pm_route['dates'] !== []) {
        $pm_arrivals[$pm_route['arrival_id']]['routes'][$pm_route['type']][] = $pm_route;
    }
}
foreach ($pm_hotels as $pm_hotel_id => $pm_hotel) {
    if (!$pm_hotel['sellable']) {
        continue;
    }
    foreach (pm_chain($pm_hotel['destination_id'], $pm_parents) as $pm_destination_id) {
        if (isset($pm_arrivals[$pm_destination_id])) {
            $pm_arrivals[$pm_destination_id]['hotels'][] = (string) $pm_hotel_id;
        }
    }
}
$pm_both = array_filter($pm_arrivals, static fn (array $a): bool => isset($a['routes']['bus'], $a['routes']['flight']));
uasort($pm_both, static fn (array $a, array $b): int => count($b['hotels']) <=> count($a['hotels']));
$pm_sellable = count(array_filter($pm_hotels, static fn (array $h): bool => $h['sellable']));
$pm_reached = count(array_unique(array_merge(...array_values(array_map(static fn (array $a): array => $a['hotels'], $pm_arrivals)))));
$pm_reached_both = count(array_unique(array_merge(...array_values(array_map(static fn (array $a): array => $a['hotels'], $pm_both)))));
$pm_with_product = array_filter($pm_hotels, static fn (array $h): bool => $h['product_id'] > 0);
$pm_statuses = array_count_values(array_map(
    static fn (array $h): string => ($h['status'] !== '' ? $h['status'] : 'no such product') . ($h['gate_hidden'] ? ' (gate-hidden)' : ''),
    $pm_with_product,
));
ksort($pm_statuses);
pm_line(sprintf(
    'Synced Sphinx hotels: %d · with a store product: %d · product status: %s',
    count($pm_hotels),
    count($pm_with_product),
    $pm_statuses === [] ? 'none' : implode(', ', array_map(static fn (string $k, int $v): string => "{$k} {$v}", array_keys($pm_statuses), $pm_statuses)),
));
pm_line('  (sellable = status A, or H when the availability gate hid it; D, or H set by hand, is not sold)');
pm_line(sprintf(
    'Store hotels (sellable): %d · in a destination with a package route: %d · with both bus and plane routes: %d',
    $pm_sellable,
    $pm_reached,
    $pm_reached_both,
));
pm_line(sprintf(
    'Destinations: %d by bus and by plane · %d by bus only · %d by plane only',
    count($pm_both),
    count(array_filter($pm_arrivals, static fn (array $a): bool => isset($a['routes']['bus']) && !isset($a['routes']['flight']))),
    count(array_filter($pm_arrivals, static fn (array $a): bool => isset($a['routes']['flight']) && !isset($a['routes']['bus']))),
));
foreach (array_slice($pm_both, 0, 15, true) as $pm_arrival_id => $pm_arrival) {
    pm_line(sprintf(
        '  %s (%d): %d store hotels · bus from %s · plane from %s',
        $pm_arrival['name'],
        $pm_arrival_id,
        count($pm_arrival['hotels']),
        implode(', ', array_unique(array_column($pm_arrival['routes']['bus'], 'departure'))),
        implode(', ', array_unique(array_column($pm_arrival['routes']['flight'], 'departure'))),
    ));
}

// The destinations to search: those reached both ways first, then those
// with the most store hotels; a city before a region before a country (a
// country-wide search is slow and may outlast the page); then the most routes.
$pm_rank = static function (int $id, array $a) use ($pm_dest_types): array {
    $type = $pm_dest_types[$id] ?? '';

    return [
        isset($a['routes']['bus'], $a['routes']['flight']) ? 0 : 1,
        -count($a['hotels']),
        $type === 'country' || $type === 'continent' ? 2 : ($type === 'region' ? 1 : 0),
        -count($a['routes']['flight'] ?? []) - count($a['routes']['bus'] ?? []),
    ];
};
$pm_ranked = array_filter($pm_arrivals, static fn (array $a): bool => $a['routes'] !== []);
uksort($pm_ranked, static fn (int $x, int $y): int => $pm_rank($x, $pm_ranked[$x]) <=> $pm_rank($y, $pm_ranked[$y]));
if ($pm_both === []) {
    pm_line('Destinations to search first (use ?arrival=ID for one):');
    foreach (array_slice($pm_ranked, 0, 15, true) as $pm_arrival_id => $pm_arrival) {
        pm_line(sprintf(
            '  %s (%d, %s): %d store hotels · plane from %s%s',
            $pm_arrival['name'],
            $pm_arrival_id,
            ($pm_dest_types[$pm_arrival_id] ?? '') !== '' ? $pm_dest_types[$pm_arrival_id] : 'type unknown',
            count($pm_arrival['hotels']),
            implode(', ', array_slice(array_unique(array_column($pm_arrival['routes']['flight'] ?? [], 'departure')), 0, 6)) ?: '-',
            isset($pm_arrival['routes']['bus']) ? ' · bus from ' . implode(', ', array_unique(array_column($pm_arrival['routes']['bus'], 'departure'))) : '',
        ));
    }
}

// ── 3. Package searches, by bus and by plane ────────────────────────────
$pm_targets = $pm_only > 0
    ? [$pm_only]
    : array_slice(array_keys($pm_ranked), 0, $pm_sample);
/** @var array<string, array<string, array{price: float, currency: string, line: string, offers: int, departure: string, date: string, nights: int, arrival_id: int}>> $pm_found hotel id => transport => its cheapest offer */
$pm_found = [];
foreach ($pm_targets as $pm_arrival_id) {
    $pm_arrival = $pm_arrivals[$pm_arrival_id] ?? null;
    if ($pm_arrival === null) {
        pm_h('3. Destination ' . $pm_arrival_id . ': no saved route goes there');
        continue;
    }
    pm_h('3. ' . $pm_arrival['name'] . ' (' . $pm_arrival_id . ')');
    foreach (PM_TRANSPORTS as $pm_type) {
        $pm_route = pm_pick($pm_arrival['routes'][$pm_type] ?? [], $pm_from, $pm_nights);
        if ($pm_route === null) {
            pm_line("  {$pm_type}: no route with a date ahead");
            continue;
        }
        $pm_date = $pm_route['dates'][0];
        pm_line(sprintf('  searching %s from %s on %s, %d nights…', $pm_type, $pm_route['departure'], $pm_date, $pm_route['nights']));
        $pm_result = pm_packages($pm_api, pm_request([
            'departure_id' => $pm_route['departure_id'],
            'destination_id' => $pm_arrival_id,
            'departure_date' => $pm_date,
            'nights' => $pm_route['nights'],
            'transport' => [$pm_type],
        ]));
        /** @var array<string, list<array<string, mixed>>> $pm_by_hotel */
        $pm_by_hotel = [];
        foreach ($pm_result['offers'] as $pm_offer) {
            $pm_by_hotel[pm_package_hotel($pm_offer)][] = $pm_offer;
        }
        unset($pm_by_hotel['']);
        $pm_in_store = array_filter(array_keys($pm_by_hotel), static fn (string $id): bool => ($pm_hotels[$id]['sellable'] ?? false) === true);
        pm_line(sprintf(
            '  %s from %s on %s, %d nights, 2 adults: %d offers, %d hotels, %d of them sold by the store',
            $pm_type,
            $pm_route['departure'],
            $pm_date,
            $pm_route['nights'],
            count($pm_result['offers']),
            count($pm_by_hotel),
            count($pm_in_store),
        ));
        if ($pm_result['error'] !== '') {
            pm_line('    ' . $pm_result['error']);
        }
        $pm_rows = [];
        foreach ($pm_by_hotel as $pm_hotel_id => $pm_offers) {
            usort($pm_offers, static fn (array $a, array $b): int => pm_package_price($a) <=> pm_package_price($b));
            $pm_cheapest = $pm_offers[0];
            $pm_rows[$pm_hotel_id] = [
                'price' => pm_package_price($pm_cheapest),
                'currency' => pm_package_currency($pm_cheapest),
                'line' => pm_package_line($pm_cheapest),
                'offers' => count($pm_offers),
                'departure' => $pm_route['departure'],
                'date' => $pm_date,
                'nights' => $pm_route['nights'],
                'arrival_id' => (int) $pm_arrival_id,
            ];
            $pm_found[(string) $pm_hotel_id][$pm_type] = $pm_rows[$pm_hotel_id];
        }
        uasort($pm_rows, static fn (array $a, array $b): int => $a['price'] <=> $b['price']);
        foreach (array_slice($pm_rows, 0, 8, true) as $pm_hotel_id => $pm_row) {
            $pm_hotel = $pm_hotels[(string) $pm_hotel_id] ?? null;
            pm_line(sprintf(
                '    - %s (%s): %d offers from %s · %s · %s',
                $pm_hotel['name'] ?? '(hotel not synced)',
                $pm_hotel_id,
                $pm_row['offers'],
                pm_prices($pm_row['price'], $pm_row['currency']),
                $pm_row['line'],
                ($pm_hotel['sellable'] ?? false) ? 'in the store: product #' . ($pm_hotel['product_id'] ?? 0) : 'not sold by the store',
            ));
        }
    }
}

// ── 4. Hotels with all three ────────────────────────────────────────────
pm_h('4. Hotels with all three: accommodation only, by bus, by plane');
$pm_all = array_filter($pm_found, static fn (array $modes): bool => isset($modes['bus'], $modes['flight']));
$pm_all_store = array_filter($pm_all, static fn (string $id): bool => ($pm_hotels[$id]['sellable'] ?? false) === true, ARRAY_FILTER_USE_KEY);
pm_line(sprintf(
    'Hotels with packages by bus and by plane in these searches: %d · sold by the store: %d',
    count($pm_all),
    count($pm_all_store),
));
foreach (array_slice($pm_all_store !== [] ? $pm_all_store : $pm_all, 0, $pm_examples, true) as $pm_hotel_id => $pm_modes) {
    $pm_hotel_id = (string) $pm_hotel_id;
    $pm_hotel = $pm_hotels[$pm_hotel_id] ?? null;
    pm_line();
    pm_line(sprintf(
        '%s (Sphinx hotel %s)%s',
        $pm_hotel['name'] ?? '(hotel not synced)',
        $pm_hotel_id,
        ($pm_hotel['sellable'] ?? false) ? ' — product #' . $pm_hotel['product_id'] . ', ' . fn_url('products.view?product_id=' . $pm_hotel['product_id'], 'C') : ' — not sold by the store',
    ));
    // Accommodation only, on the plane's dates: the store's own hotel search
    // (its destination, then this hotel's offers; hotel_ids returns none).
    $pm_flight = $pm_modes['flight'];
    $pm_destination = (int) ($pm_hotel['destination_id'] ?? 0);
    if ($pm_destination > 0) {
        $pm_stay = pm_stays($pm_api, pm_request([
            'check_in' => $pm_flight['date'],
            'check_out' => date('Y-m-d', (int) strtotime($pm_flight['date'] . ' +' . $pm_flight['nights'] . ' days')),
            'destination_id' => $pm_destination,
        ]));
        $pm_mine = array_values(array_filter(
            array_map(static fn (array $offer): array => SearchOfferNormalizer::flatten($offer), $pm_stay['offers']),
            static fn (array $offer): bool => TypeCoerce::toString($offer['hotel_id'] ?? '') === $pm_hotel_id,
        ));
        usort($pm_mine, static fn (array $a, array $b): int => TypeCoerce::toFloat($a['price'] ?? 0) <=> TypeCoerce::toFloat($b['price'] ?? 0));
        pm_line($pm_mine !== []
            ? sprintf(
                '  accommodation only: %d offers from %s, %s, %d nights',
                count($pm_mine),
                pm_prices(TypeCoerce::toFloat($pm_mine[0]['price'] ?? 0), TypeCoerce::toString($pm_mine[0]['currency'] ?? '')),
                $pm_flight['date'],
                $pm_flight['nights'],
            )
            : sprintf('  accommodation only: no offer on %s for %d nights%s', $pm_flight['date'], $pm_flight['nights'], $pm_stay['error'] !== '' ? ' — ' . $pm_stay['error'] : ''));
    } else {
        pm_line('  accommodation only: not checked (the hotel has no destination in ?:sphinx_hotels)');
    }
    foreach (PM_TRANSPORTS as $pm_type) {
        $pm_mode = $pm_modes[$pm_type];
        pm_line(sprintf(
            '  %s: from %s on %s, %d nights: %d offers from %s · %s',
            $pm_type === 'bus' ? 'by bus  ' : 'by plane',
            $pm_mode['departure'],
            $pm_mode['date'],
            $pm_mode['nights'],
            $pm_mode['offers'],
            pm_prices($pm_mode['price'], $pm_mode['currency']),
            $pm_mode['line'],
        ));
    }
}

// ── 5. Can a package search ask for one hotel? ──────────────────────────
pm_h('5. A package search for one hotel');
$pm_probe = null;
foreach ($pm_found as $pm_hotel_id => $pm_modes) {
    $pm_hotel_id = (string) $pm_hotel_id;
    if (isset($pm_modes['flight']) && ($pm_hotels[$pm_hotel_id]['destination_id'] ?? 0) > 0) {
        $pm_probe = [$pm_hotel_id, $pm_modes['flight']];
        if (($pm_hotels[$pm_hotel_id]['sellable'] ?? false) === true) {
            break;
        }
    }
}
if ($pm_probe === null) {
    pm_line('No hotel with a plane package and a known destination to ask with.');
} else {
    [$pm_hotel_id, $pm_mode] = $pm_probe;
    $pm_route_params = [
        'departure_date' => $pm_mode['date'],
        'nights' => $pm_mode['nights'],
        'transport' => ['flight'],
    ];
    $pm_departure = array_values(array_filter(
        $pm_routes,
        static fn (array $r): bool => $r['type'] === 'flight' && $r['arrival_id'] === $pm_mode['arrival_id'] && $r['departure'] === $pm_mode['departure'],
    ));
    if ($pm_departure !== []) {
        $pm_route_params['departure_id'] = $pm_departure[0]['departure_id'];
    }
    pm_line(sprintf(
        '%s (%s), by plane from %s on %s: %d offers when the route\'s destination (%d) is searched',
        $pm_hotels[$pm_hotel_id]['name'] ?? $pm_hotel_id,
        $pm_hotel_id,
        $pm_mode['departure'],
        $pm_mode['date'],
        $pm_mode['offers'],
        $pm_mode['arrival_id'],
    ));
    $pm_asks = ['hotel_ids only' => ['hotel_ids' => [(int) $pm_hotel_id]]];
    $pm_own = (int) ($pm_hotels[$pm_hotel_id]['destination_id'] ?? 0);
    if ($pm_own > 0 && $pm_own !== $pm_mode['arrival_id']) {
        $pm_asks['its own destination (' . $pm_own . ')'] = ['destination_id' => $pm_own];
    }
    foreach ($pm_asks as $pm_label => $pm_ask) {
        $pm_result = pm_packages($pm_api, pm_request($pm_route_params + $pm_ask));
        $pm_its = count(array_filter($pm_result['offers'], static fn (array $offer): bool => pm_package_hotel($offer) === $pm_hotel_id));
        pm_line(sprintf(
            '  %s: %d offers, %d of them this hotel\'s%s',
            $pm_label,
            count($pm_result['offers']),
            $pm_its,
            $pm_result['error'] !== '' ? ' — ' . $pm_result['error'] : '',
        ));
    }
}

pm_h('Done');
pm_line('Read-only: only searches were sent; nothing was booked, written or cached.');
