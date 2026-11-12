<?php

/**
 * eurosite-circuits.php — what this store's Eurosite account returns for
 * circuits, read-only: the real shape of the answers, before anything is
 * built (the add-on has no circuit support yet; spec "Circuite", p. 107–127).
 *
 * WHY: the Sphinx circuit sync was written from the docs and the live data
 * disagreed (destinations came back as plain ids, not objects). For Eurosite
 * we look first. The questions this page answers:
 *
 *   1. Which countries / cities have circuits, and from which departure
 *      cities (CircuitSearchCityRequest).
 *   2. What a circuit looks like (CircuitSearchRequest, Month=13 = the next
 *      year): name, nights, description, the day-by-day program, one or
 *      several destinations (a multi-country circuit?), and every variant
 *      (date, charter dates, availability, price, services).
 *   3. Does the price depend on the departure city: the same city searched
 *      from each of its departure cities, cheapest variant side by side.
 *   4. What getProductInfoRequest (ProductType=circuit) adds: pictures, the
 *      destinations with their countries — and whether it matches the search.
 *   5. Optional services (CircuitSearchServiceRequest — the spec's example is
 *      a transfer, not an excursion) and the cancellation fees
 *      (CircuitFeesRequest) of the first circuit found.
 *   6. A summary of what we may rely on.
 *
 * Read-only: nothing is booked, written or cached; its own HTTP client, one
 * try a call, so the store's circuit breaker is never touched. Only the
 * request details are printed, never the envelope with the account, and the
 * API address / account are masked in any error.
 *
 * WHERE: the fullstore sandbox only (dev/ is mounted at /var/www/html/dev).
 * Refuses non-localhost HTTP requests.
 *
 * USE:
 *   http://localhost:8080/dev/tools/eurosite-circuits.php
 *       → the circuit cities, then a sample of circuits from the first cities
 *   ...?country=AT&city=ATVNN1&dep=ROIS     → search this city / departure instead
 *   ...&month=13&year=27                    → month 1-12 (13 = a year from today); year is 2 digits
 *   ...&name=craciun                        → only circuits whose name has this
 *   ...&sample=3                            → how many cities to search (default 3)
 *   ...&circuits=5                          → circuits shown per search (default 5)
 *   ...&adults=2&children=8,5&room=DB       → the persons asked (default DB, 2 adults)
 *   ...&currency=EUR                        → EUR or RON
 *   ...&prices=0 / &info=0 / &extras=0      → leave out step 3 / 4 / 5
 *   ...&raw=1                               → also the raw XML (cut) of each answer
 *   CLI: docker compose exec app php /var/www/html/dev/tools/eurosite-circuits.php sample=2
 */

use Tygh\Addons\Eurosite\EurositeHttpClient;
use Tygh\Addons\Eurosite\Services\ConfigProvider;
use Tygh\Addons\Eurosite\Services\Container;

// ---------------------------------------------------------------- reading the answers

/** The <ResponseDetails> child of an answer, or the reason there is none. */
function ck_details(string $raw): array
{
    $doc = @simplexml_load_string($raw, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
    if (!$doc instanceof \SimpleXMLElement) {
        return [null, 'the answer is not XML (' . strlen($raw) . ' bytes)'];
    }
    $details = $doc->ResponseDetails;
    foreach (($details instanceof \SimpleXMLElement ? $details->children() : null) ?? [] as $child) {
        if ($child->getName() === 'Errors') {
            return [null, 'API error: ' . trim(preg_replace('/\s+/', ' ', strip_tags(str_replace('><', '> <', (string) $child->asXML()))) ?? '')];
        }

        return [$child, ''];
    }

    return [null, 'no <ResponseDetails> in the answer'];
}

function ck_text(?\SimpleXMLElement $el): string
{
    return $el === null ? '' : trim(preg_replace('/\s+/u', ' ', (string) $el) ?? '');
}

/** The operator's text arrives HTML-escaped twice ("&amp;lt;br /&amp;gt;"): readable plain text. */
function ck_plain(string $text): string
{
    $text = html_entity_decode(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('#<br\s*/?>#i', "\n", $text) ?? $text;

    return trim(preg_replace('/[ \t\x{00A0}]+/u', ' ', strip_tags($text)) ?? '');
}

/** A cut single-line preview of a text. */
function ck_cut(string $text, int $max = 140): string
{
    $line = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

    return mb_strlen($line) > $max ? mb_substr($line, 0, $max) . '…' : $line;
}

/**
 * The countries / cities / departure cities of a CircuitSearchCityResponse.
 *
 * @return list<array{country_code: string, country_name: string, city_code: string, city_name: string, departures: list<array{code: string, name: string}>}>
 */
function ck_cities(\SimpleXMLElement $response): array
{
    $out = [];
    foreach ($response->Country ?? [] as $country) {
        foreach ($country->Cities->City ?? [] as $city) {
            $deps = [];
            foreach ($city->Departures->Departure ?? [] as $dep) {
                $deps[] = ['code' => ck_text($dep->CityCode), 'name' => ck_text($dep->CityName)];
            }
            $out[] = [
                'country_code' => ck_text($country->CountryCode),
                'country_name' => ck_text($country->CountryName),
                'city_code' => ck_text($city->CityCode),
                'city_name' => ck_text($city->CityName),
                'departures' => $deps,
            ];
        }
    }

    return $out;
}

/**
 * The circuits of a CircuitSearchResponse, with the facts this page judges.
 *
 * @return list<array<string, mixed>>
 */
function ck_circuits(\SimpleXMLElement $response): array
{
    $out = [];
    foreach ($response->Circuit ?? [] as $c) {
        $days = [];
        foreach ($c->DayDescriptions->DayDescription ?? [] as $d) {
            $days[] = ck_plain((string) $d);
        }
        $dests = [];
        foreach ($c->Destinations->CircuitDestination ?? [] as $d) {
            $dests[] = ck_text($d->CityName) . ' (' . ck_text($d->CityCode) . ')';
        }
        $variants = [];
        foreach ($c->Variants->Variant ?? [] as $v) {
            $info = $v->InfoCharter;
            $services = [];
            foreach ($v->Services->Service ?? [] as $s) {
                $services[] = ck_text($s->Type) . ':' . ck_cut(ck_text($s->Name), 40) . '=' . ck_text($s->ServicePrice);
            }
            $variants[] = [
                'unique_id' => ck_text($v->UniqueId),
                'dep_charter' => ck_text($v->DepartureCharter),
                'out' => ck_text($info->DepDate ?? null),
                'back' => ck_text($info->RetDate ?? null),
                'availability' => ck_text($v->Availability),
                'currency' => ck_text($v->CurrencyCode),
                'price' => ck_text($v->ProductPrice),
                'price_no_discount' => ck_text($v->PriceNoRedd),
                'rooms' => ck_text($v->Rooms->Room ?? null),
                'services' => $services,
            ];
        }
        $out[] = [
            'tourop' => ck_text($c->TourOpCode),
            'search_id' => ck_text($c->SearchId),
            'id' => ck_text($c->CircuitId),
            'name' => ck_text($c->Name),
            'period' => ck_text($c->Period),
            'description' => ck_plain((string) $c->Description),
            'days' => $days,
            'destinations' => $dests,
            'variants' => $variants,
        ];
    }

    return $out;
}

/** The lowest numeric price among a circuit's variants, or null. */
function ck_min_price(array $circuit): ?float
{
    $min = null;
    foreach ($circuit['variants'] as $v) {
        if (is_numeric($v['price']) && (float) $v['price'] > 0 && ($min === null || (float) $v['price'] < $min)) {
            $min = (float) $v['price'];
        }
    }

    return $min;
}

/** @return list<string> the API address, host and account — longest first (never printed) */
function ck_secrets(array $settings): array
{
    $url = is_string($settings['api_url'] ?? null) ? $settings['api_url'] : '';
    $host = parse_url($url, PHP_URL_HOST);
    $secrets = array_values(array_unique(array_filter([
        $url,
        is_string($host) ? $host : '',
        is_string($settings['api_user'] ?? null) ? $settings['api_user'] : '',
        is_string($settings['api_password'] ?? null) ? $settings['api_password'] : '',
    ], static fn (string $s): bool => strlen(trim($s)) >= 3)));
    usort($secrets, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

    return $secrets;
}

function ck_mask(string $text, array $secrets): string
{
    foreach ($secrets as $secret) {
        $text = str_ireplace($secret, '<hidden>', $text);
    }

    return $text;
}

function ck_esc(string $v): string
{
    return htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

if (defined('EUROSITE_CIRCUITS_LIBRARY_ONLY')) {
    return; // the reading functions only (a test harness)
}

// ---------------------------------------------------------------- the store

$ck_is_cli = PHP_SAPI === 'cli';

if (!$ck_is_cli) {
    $ck_host = strtolower((string) strtok((string) ($_SERVER['HTTP_HOST'] ?? ''), ':'));
    if (!in_array($ck_host, ['localhost', '127.0.0.1', '[::1]'], true)) {
        http_response_code(403);
        exit("eurosite-circuits is a local sandbox tool - refusing non-localhost request\n");
    }
}

$ck_docroot = dirname(__DIR__, 2);
if (!is_file($ck_docroot . '/init.php') && is_file('/var/www/html/init.php')) {
    $ck_docroot = '/var/www/html';
}
if (!is_file($ck_docroot . '/init.php')) {
    exit("No CS-Cart init.php at {$ck_docroot} — this tool only works from the fullstore\n"
        . "container, where dev/ is mounted inside the CS-Cart docroot (see docker/fullstore).\n");
}

const AREA = 'A';
const ACCOUNT_TYPE = 'admin';
if ($ck_is_cli) {
    $_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';
    $_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    chdir($ck_docroot);
}
require $ck_docroot . '/init.php';
if (!$ck_is_cli) {
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Accel-Buffering: no');
    while (ob_get_level() > 0) {
        @ob_end_flush();
    }
    ob_implicit_flush(true);
}

function ck_param(string $key, string $default = ''): string
{
    if (PHP_SAPI === 'cli') {
        foreach (array_slice((array) ($GLOBALS['argv'] ?? []), 1) as $arg) {
            if (str_starts_with((string) $arg, $key . '=')) {
                return trim(substr((string) $arg, strlen($key) + 1));
            }
        }

        return $default;
    }

    return isset($_GET[$key]) && is_scalar($_GET[$key]) ? trim((string) $_GET[$key]) : $default;
}

function ck_h(string $title): void
{
    echo "\n", str_repeat('=', 78), "\n", $title, "\n", str_repeat('=', 78), "\n";
}

/** Pretty-printed XML of one element, cut so the page stays readable. */
function ck_xml(\SimpleXMLElement $el, int $max = 5000): string
{
    $dom = new \DOMDocument('1.0', 'UTF-8');
    $dom->preserveWhiteSpace = false;
    $dom->formatOutput = true;
    $dom->loadXML((string) $el->asXML(), LIBXML_NONET);
    $out = (string) $dom->saveXML($dom->documentElement);

    return strlen($out) > $max ? substr($out, 0, $max) . "\n… (cut at {$max} bytes)" : $out;
}

if (!class_exists(Container::class)) {
    exit("The eurosite add-on is not installed/active in this store.\n");
}

set_time_limit(900);

$ck_settings = ConfigProvider::toClientSettings();
$ck_secrets = ck_secrets($ck_settings);
try {
    $ck_http = new EurositeHttpClient(['api_max_retries' => 1, 'api_timeout' => 120] + $ck_settings);
} catch (\Throwable $e) {
    exit('The Eurosite API is not configured in this store: ' . ck_mask($e->getMessage(), $ck_secrets) . "\n");
}
$ck_builder = Container::getXmlBuilder();

/**
 * One live call: prints the request details (never the envelope) and the time.
 *
 * @return array{0: ?\SimpleXMLElement, 1: string} the response details, or null and why
 */
function ck_call(string $requestType, string $detailsXml): array
{
    global $ck_http, $ck_builder, $ck_secrets;
    echo "Request details:\n", $detailsXml, "\n";
    $started = microtime(true);
    try {
        $raw = $ck_http->post($ck_builder->envelope($requestType, $detailsXml, '902', date('Y-m-d\TH:i:s')));
    } catch (\Throwable $e) {
        $why = ck_mask($e->getMessage(), $ck_secrets);
        echo sprintf("FAILED after %.1f s: %s\n", microtime(true) - $started, $why);

        return [null, $why];
    }
    [$details, $why] = ck_details($raw);
    $why = ck_mask($why, $ck_secrets);
    echo sprintf("Answered in %.1f s, %d bytes%s\n", microtime(true) - $started, strlen($raw), $why !== '' ? ' — ' . $why : '');

    return [$details, $why];
}

/** The <Rooms> of a search: the persons asked. */
function ck_rooms_xml(array $room): string
{
    $kids = '';
    if ($room['children'] !== []) {
        $kids = '<Children>' . implode('', array_map(static fn (int $a): string => '<Age>' . $a . '</Age>', $room['children'])) . '</Children>';
    }

    return '<Rooms><Room Code="' . ck_esc($room['code']) . '" NoAdults="' . $room['adults'] . '" NoChildren="' . count($room['children']) . '">' . $kids . '</Room></Rooms>';
}

/** A CircuitSearchRequest's details. */
function ck_search_xml(string $country, string $city, string $dep, string $currency, string $year, string $month, string $name, array $room): string
{
    return '<CircuitSearchRequest>'
        . '<CountryCode>' . ck_esc($country) . '</CountryCode>'
        . ($dep !== '' ? '<DepartureCode>' . ck_esc($dep) . '</DepartureCode>' : '')
        . '<CityCode>' . ck_esc($city) . '</CityCode>'
        . '<CurrencyCode>' . ck_esc($currency) . '</CurrencyCode>'
        . '<Year>' . ck_esc($year) . '</Year>'
        . '<Month>' . ck_esc($month) . '</Month>'
        . ($name !== '' ? '<ProductName>' . ck_esc($name) . '</ProductName>' : '')
        . ck_rooms_xml($room)
        . '</CircuitSearchRequest>';
}

/** One circuit, printed with what decides how we would store it. */
function ck_print_circuit(array $c, int $maxVariants = 4): void
{
    $min = ck_min_price($c);
    echo sprintf(
        "\n  • %s  [%s | %s]  %s nights/period  from %s\n",
        $c['name'] !== '' ? $c['name'] : '(no name)',
        $c['id'],
        $c['tourop'],
        $c['period'] !== '' ? $c['period'] : '?',
        $min !== null ? number_format($min, 2) . ' ' . ($c['variants'][0]['currency'] ?? '') : '(no price)',
    );
    echo sprintf(
        "    destinations (%d): %s\n",
        count($c['destinations']),
        $c['destinations'] === [] ? '(none)' : implode(' · ', $c['destinations']),
    );
    echo sprintf("    description: %d chars — %s\n", mb_strlen($c['description']), ck_cut($c['description']));
    echo sprintf("    program: %d day text(s)", count($c['days']));
    if ($c['days'] !== []) {
        echo ' — day 1: ', ck_cut($c['days'][0], 110);
    }
    echo "\n";
    echo sprintf("    variants: %d\n", count($c['variants']));
    foreach (array_slice($c['variants'], 0, $maxVariants) as $v) {
        echo sprintf(
            "      - %s  out %s  back %s  %s  %s %s (was %s)  room %s\n        services: %s\n",
            $v['unique_id'],
            $v['out'] !== '' ? $v['out'] : '-',
            $v['back'] !== '' ? $v['back'] : '-',
            $v['availability'],
            $v['price'],
            $v['currency'],
            $v['price_no_discount'] !== '' ? $v['price_no_discount'] : '-',
            $v['rooms'],
            $v['services'] === [] ? '(none)' : implode(' | ', array_slice($v['services'], 0, 5)),
        );
    }
    if (count($c['variants']) > $maxVariants) {
        echo '      … ', count($c['variants']) - $maxVariants, " more variant(s)\n";
    }
}

$ck_raw = ck_param('raw') === '1';
$ck_currency = strtoupper(ck_param('currency', 'EUR')) === 'RON' ? 'RON' : 'EUR';
$ck_year = ck_param('year', date('y'));
$ck_month = ck_param('month', '13');
$ck_name = ck_param('name');
$ck_sample = max(1, min(10, (int) ck_param('sample', '3')));
$ck_show = max(1, min(20, (int) ck_param('circuits', '5')));
$ck_room = [
    'code' => ck_param('room', 'DB'),
    'adults' => max(1, (int) ck_param('adults', '2')),
    'children' => array_values(array_filter(array_map('intval', explode(',', ck_param('children'))), static fn (int $a): bool => $a > 0)),
];
$ck_want_prices = ck_param('prices', '1') !== '0';
$ck_want_info = ck_param('info', '1') !== '0';
$ck_want_extras = ck_param('extras', '1') !== '0';

$ck_found = [];        // [circuit, city, dep] of every circuit seen
$ck_summary = ['cities' => 0, 'circuits' => 0, 'multi_dest' => 0, 'no_dest' => 0, 'no_days' => 0, 'no_price' => 0, 'variants' => 0];

// ---------------------------------------------------------------- 1. the cities

ck_h('1. Circuit cities — CircuitSearchCityRequest');
[$ck_resp, $ck_why] = ck_call('CircuitSearchCityRequest', '<CircuitSearchCityRequest/>');
$ck_cities = $ck_resp !== null ? ck_cities($ck_resp) : [];
$ck_summary['cities'] = count($ck_cities);
if ($ck_resp !== null && $ck_raw) {
    echo ck_xml($ck_resp, 3000), "\n";
}
if ($ck_cities === []) {
    echo "No circuit city came back", $ck_why !== '' ? " ({$ck_why})" : '', ".\n";
} else {
    $ck_by_country = [];
    foreach ($ck_cities as $c) {
        $ck_by_country[$c['country_name'] . ' (' . $c['country_code'] . ')'][] = $c;
    }
    echo count($ck_cities), ' cit', count($ck_cities) === 1 ? 'y' : 'ies', ' in ', count($ck_by_country), " countr", count($ck_by_country) === 1 ? 'y' : 'ies', ":\n";
    foreach ($ck_by_country as $country => $cities) {
        echo "\n  {$country}\n";
        foreach ($cities as $c) {
            $names = array_map(static fn (array $d): string => $d['name'] . ' (' . $d['code'] . ')', $c['departures']);
            echo sprintf("    - %s (%s)  departures: %s\n", $c['city_name'], $c['city_code'], $names === [] ? '(none: DepartureCode not needed)' : implode(', ', $names));
        }
    }
}

// ---------------------------------------------------------------- 2. the search

$ck_targets = [];
if (ck_param('city') !== '') {
    $ck_targets[] = ['country_code' => ck_param('country'), 'city_code' => ck_param('city'), 'city_name' => ck_param('city'), 'departures' => ck_param('dep') !== '' ? [['code' => ck_param('dep'), 'name' => ck_param('dep')]] : []];
} else {
    $ck_targets = array_slice($ck_cities, 0, $ck_sample);
}

foreach ($ck_targets as $t) {
    $dep = $t['departures'][0]['code'] ?? '';
    foreach ($t['departures'] as $d) {
        if (preg_match('/^ROBCH|bucure|buchar/i', $d['code'] . ' ' . $d['name']) === 1) {
            $dep = $d['code'];

            break;
        }
    }
    ck_h(sprintf('2. Circuits — CircuitSearchRequest, %s (%s)%s, Month=%s Year=%s', $t['city_name'], $t['city_code'], $dep !== '' ? ', from ' . $dep : '', $ck_month, $ck_year));
    [$ck_resp, $ck_why] = ck_call('CircuitSearchRequest', ck_search_xml((string) $t['country_code'], (string) $t['city_code'], $dep, $ck_currency, $ck_year, $ck_month, $ck_name, $ck_room));
    if ($ck_resp === null) {
        continue;
    }
    $circuits = ck_circuits($ck_resp);
    if ($ck_raw && isset($ck_resp->Circuit[0])) {
        echo "\nRaw first <Circuit>:\n", ck_xml($ck_resp->Circuit[0], 6000), "\n";
    }
    echo count($circuits), ' circuit(s) answered', count($circuits) > $ck_show ? " (first {$ck_show} shown)" : '', ".\n";
    foreach ($circuits as $i => $c) {
        $ck_summary['circuits']++;
        $ck_summary['variants'] += count($c['variants']);
        count($c['destinations']) > 1 && $ck_summary['multi_dest']++;
        $c['destinations'] === [] && $ck_summary['no_dest']++;
        $c['days'] === [] && $ck_summary['no_days']++;
        ck_min_price($c) === null && $ck_summary['no_price']++;
        $ck_found[] = ['c' => $c, 'country' => (string) $t['country_code'], 'city' => (string) $t['city_code'], 'dep' => $dep, 'deps' => $t['departures']];
        if ($i < $ck_show) {
            ck_print_circuit($c);
        }
    }
}

// ---------------------------------------------------------------- 3. price by departure city

if ($ck_want_prices) {
    ck_h('3. Does the price depend on the departure city?');
    $ck_done = false;
    foreach ($ck_found as $f) {
        if (count($f['deps']) < 2) {
            continue;
        }
        echo sprintf("Searching %s (%s) once per departure city, circuit %s [%s] side by side.\n", $f['city'], $f['country'], $f['c']['name'], $f['c']['id']);
        foreach (array_slice($f['deps'], 0, 5) as $d) {
            echo "\n-- from {$d['name']} ({$d['code']})\n";
            [$ck_resp] = ck_call('CircuitSearchRequest', ck_search_xml($f['country'], $f['city'], $d['code'], $ck_currency, $ck_year, $ck_month, $ck_name, $ck_room));
            if ($ck_resp === null) {
                continue;
            }
            $same = null;
            foreach (ck_circuits($ck_resp) as $c) {
                if ($c['id'] === $f['c']['id']) {
                    $same = $c;

                    break;
                }
            }
            if ($same === null) {
                echo "   this circuit is not offered from {$d['code']}\n";

                continue;
            }
            $min = ck_min_price($same);
            echo sprintf("   %d variant(s), cheapest %s %s\n", count($same['variants']), $min !== null ? number_format($min, 2) : '-', $same['variants'][0]['currency'] ?? '');
            foreach (array_slice($same['variants'], 0, 3) as $v) {
                echo sprintf("     %s  out %s  %s %s\n", $v['unique_id'], $v['out'] !== '' ? $v['out'] : '-', $v['price'], $v['currency']);
            }
        }
        $ck_done = true;

        break;
    }
    if (!$ck_done) {
        echo "No searched city has two or more departure cities: nothing to compare. Try ?country=..&city=..&dep=.. on a city listed above with several.\n";
    }
}

// ---------------------------------------------------------------- 4. getProductInfo

if ($ck_want_info) {
    ck_h('4. Product info — getProductInfoRequest, ProductType=circuit');
    $ck_seen = [];
    foreach ($ck_found as $f) {
        if (count($ck_seen) >= 2 || isset($ck_seen[$f['c']['id']])) {
            continue;
        }
        $ck_seen[$f['c']['id']] = true;
        $c = $f['c'];
        echo "\n-- {$c['name']} [{$c['id']}]\n";
        [$ck_resp] = ck_call('getProductInfoRequest', '<getProductInfoRequest><ProductType>circuit</ProductType><TourOpCode>' . ck_esc($c['tourop']) . '</TourOpCode><ProductCode>' . ck_esc($c['id']) . '</ProductCode></getProductInfoRequest>');
        if ($ck_resp === null) {
            continue;
        }
        $p = $ck_resp->Product ?? $ck_resp;
        $pictures = [];
        foreach ($p->Pictures->Picture ?? [] as $pic) {
            $pictures[] = ck_text($pic);
        }
        $dests = [];
        foreach ($p->Destinations->Destination ?? [] as $d) {
            $dests[] = ck_text($d->CityName) . ' (' . ck_text($d->CountryName) . ')';
        }
        $days = [];
        foreach ($p->DayDescriptions->DayDescription ?? [] as $d) {
            $days[] = ck_plain((string) $d);
        }
        $info = ck_plain((string) $p->Description);
        echo sprintf("   name: %s\n", ck_text($p->ProductName));
        echo sprintf("   country/city on the product: %s / %s\n", ck_text($p->CountryName) !== '' ? ck_text($p->CountryName) : '-', ck_text($p->CityName) !== '' ? ck_text($p->CityName) : '-');
        echo sprintf("   destinations (%d): %s\n", count($dests), $dests === [] ? '(none)' : implode(' · ', $dests));
        echo sprintf("   description: %d chars (search had %d) — %s\n", mb_strlen($info), mb_strlen($c['description']), ck_cut($info));
        echo sprintf("   program: %d day text(s) (search had %d)\n", count($days), count($c['days']));
        echo sprintf("   pictures: %d%s\n", count($pictures), $pictures !== [] ? ' — first: ' . ck_cut($pictures[0], 100) : '');
        echo sprintf("   movie link: %s\n", ck_text($p->MovieLink) !== '' ? ck_text($p->MovieLink) : '-');
        if ($ck_raw) {
            echo "\nRaw <Product> (cut):\n", ck_xml($p, 3500), "\n";
        }
    }
    if ($ck_seen === []) {
        echo "No circuit found in step 2 to ask about.\n";
    }
}

// ---------------------------------------------------------------- 5. extras and fees

if ($ck_want_extras) {
    ck_h('5. Optional services and cancellation fees of the first circuit found');
    $first = $ck_found[0] ?? null;
    if ($first === null) {
        echo "No circuit found in step 2.\n";
    } else {
        $c = $first['c'];
        echo "{$c['name']} [{$c['id']}]\n\n-- CircuitSearchServiceRequest (CircuitDep=1, as in the spec's example)\n";
        [$ck_resp] = ck_call('CircuitSearchServiceRequest', '<CircuitSearchServiceRequest><CircuitId>' . ck_esc($c['id']) . '</CircuitId><CircuitDep>1</CircuitDep></CircuitSearchServiceRequest>');
        if ($ck_resp !== null) {
            $n = 0;
            foreach ($ck_resp->Services->Service ?? [] as $s) {
                $n++;
                echo sprintf("   [%s] %s  code %s  CharterId %s  HasPrice %s\n", ck_text($s->Type), ck_text($s->Name), ck_text($s->Code), ck_text($s->CharterId) !== '' ? ck_text($s->CharterId) : '-', ck_text($s->HasPrice));
            }
            echo $n === 0 ? "   no optional service\n" : '';
            if ($ck_raw) {
                echo ck_xml($ck_resp, 2500), "\n";
            }
        }
        $uid = $c['variants'][0]['unique_id'] ?? '';
        echo "\n-- CircuitFeesRequest (UniqueId {$uid})\n";
        if ($uid === '') {
            echo "   the circuit has no variant: skipped\n";
        } else {
            [$ck_resp] = ck_call('CircuitFeesRequest', '<CircuitFeesRequest><UniqueId>' . ck_esc($uid) . '</UniqueId></CircuitFeesRequest>');
            if ($ck_resp !== null) {
                $n = 0;
                foreach ($ck_resp->Service ?? [] as $s) {
                    $n++;
                    echo sprintf("   %s → %s: %s %s (%s%%)\n", ck_text($s->DStart), ck_text($s->DStop), ck_text($s->Amount), ck_text($s->CurrencyCode), ck_text($s->Percent));
                }
                echo $n === 0 ? "   no fee period\n" : '';
            }
        }
    }
}

// ---------------------------------------------------------------- 6. summary

ck_h('6. Summary — what to rely on');
echo sprintf("Circuit cities listed:                %d\n", $ck_summary['cities']);
echo sprintf("Circuits seen in the searches:        %d (%d variants)\n", $ck_summary['circuits'], $ck_summary['variants']);
echo sprintf("  with more than one destination:     %d  (a multi-country circuit is possible if > 0)\n", $ck_summary['multi_dest']);
echo sprintf("  with no destination at all:         %d\n", $ck_summary['no_dest']);
echo sprintf("  with no day-by-day program:         %d\n", $ck_summary['no_days']);
echo sprintf("  with no price:                      %d\n", $ck_summary['no_price']);
echo "\nRead against the plan: the search returns the program, destinations and variants with price, so a\n";
echo "sync can be built on CircuitSearch alone; getProductInfo adds the pictures. Paste this page (the\n";
echo "account is never printed) to settle the open questions: price by departure, multi-destination,\n";
echo "pictures, and whether any optional excursion exists (step 5 shows transfers only).\n";
