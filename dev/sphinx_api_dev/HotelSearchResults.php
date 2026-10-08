<?php

declare(strict_types=1);

/**
 * Standalone Sphinx API hotel-search probe — search init + results poll.
 *
 * NOT part of any addon. No CS-Cart bootstrap, no addon autoload, no DB. It
 * drives the two-step hotels search directly: POST the search (which returns a
 * cursor, NOT offers), then GET the results repeatedly, following the cursor
 * until it is null, then lists the DISTINCT hotels found (count + names) followed
 * by every offer. One script on purpose — the results call only makes sense
 * with the cursor the search call just handed back.
 *
 * Endpoints:
 *   POST /api/v1/hotels/search             → { "cursor": "..." }
 *   GET  /api/v1/hotels/results?cursor=..  → { "data": [offers], "cursor": ...|null }
 *   (Authorization: Bearer <token>)
 *
 * Usage (CLI):
 *   php HotelSearchResults.php
 *   php HotelSearchResults.php destination_id=3713 check_in=2026-08-14 check_out=2026-08-21
 *   php HotelSearchResults.php immediate=1          # only confirmation=immediate offers
 *   php HotelSearchResults.php raw=1                # also dump the full JSON offers
 *   SPHINX_API_TOKEN=... SPHINX_API_URL=... php HotelSearchResults.php
 *   SPHINX_API_INSECURE=1 php HotelSearchResults.php    # skip TLS verify (dev only)
 *
 * Usage (browser — the folder is auto-served in the docker sandbox):
 *   HotelSearchResults.php
 *   HotelSearchResults.php?destination_id=3713&check_in=2026-08-14&check_out=2026-08-21&raw=1
 *
 * Overridable via query/CLI: destination_id, check_in, check_out, currency;
 * immediate=1 lists only instantly-confirmable offers, raw=1 dumps the JSON.
 * The occupancy keeps the default 2-room shape below (a nested array isn't
 * worth threading through query params for a probe). Credentials default to
 * the Sphinx DEV values committed in the sphinx_holidays addon.xml.
 */

// ── Config ─────────────────────────────────────────────────────────────────
const DEFAULT_API_URL   = 'https://api.sphinx2.christiantour.dev.ploi.imementohub.com';
const DEFAULT_API_TOKEN = ''; // set SPHINX_API_TOKEN; never commit a key

const DEFAULT_DESTINATION_ID = 3713;
const DEFAULT_CHECK_IN       = '2026-08-14';
const DEFAULT_CHECK_OUT      = '2026-08-21';
const DEFAULT_CURRENCY       = 'EUR';
const MAX_RESULT_POLLS       = 25;   // safety bound on the cursor loop
const POLL_PAUSE_SECONDS     = 1;    // pace between result pages (be polite)

$baseUrl  = rtrim((string) (getenv('SPHINX_API_URL') ?: DEFAULT_API_URL), '/');
$token    = (string) (getenv('SPHINX_API_TOKEN') ?: DEFAULT_API_TOKEN);
$insecure = in_array((string) getenv('SPHINX_API_INSECURE'), ['1', 'true', 'yes'], true);
$isCli    = PHP_SAPI === 'cli';

// ── Resolve inputs (query params in web mode, key=value args in CLI) ────────
$in = $_GET;
if ($isCli) {
    foreach (array_slice($argv, 1) as $arg) {
        if (str_contains($arg, '=')) {
            [$k, $v] = explode('=', $arg, 2);
            $in[$k] = $v;
        }
    }
}
$destinationId = (int) ($in['destination_id'] ?? DEFAULT_DESTINATION_ID);
$checkIn       = (string) ($in['check_in'] ?? DEFAULT_CHECK_IN);
$checkOut      = (string) ($in['check_out'] ?? DEFAULT_CHECK_OUT);
$currency      = (string) ($in['currency'] ?? DEFAULT_CURRENCY);
$showRaw       = !empty($in['raw']);
$immediateOnly = !empty($in['immediate']);   // list ONLY confirmation=immediate offers

$searchBody = [
    'check_in' => $checkIn,
    'check_out' => $checkOut,
    'destination_id' => $destinationId,
    'occupancy' => [
        ['adults' => 2, 'children_ages' => [5, 12]],
        ['adults' => 2, 'children_ages' => []],
    ],
    'currency' => $currency,
    'ignore_domains' => ['api.supplier.com', 'api.supplier.net'],
];

if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
}

/**
 * One Sphinx API call with Bearer auth. Returns raw body + HTTP code + error.
 *
 * @param array<string, mixed>|null $jsonBody null = GET, array = POST with JSON
 * @return array{code:int, body:string, error:string}
 */
function sphinx_call(string $url, string $token, bool $insecure, ?array $jsonBody = null): array
{
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT => 45,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => !$insecure,
        CURLOPT_SSL_VERIFYHOST => $insecure ? 0 : 2,
    ];
    if ($jsonBody !== null) {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = (string) json_encode($jsonBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    return ['code' => $code, 'body' => is_string($body) ? $body : '', 'error' => $err];
}

/** Coalesce a possibly-missing/empty scalar to a printable string. */
function s(mixed $v): string
{
    if ($v === null || $v === '') {
        return '(empty)';
    }

    return is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/** Pull the cursor from either the top level or a `data` wrapper. */
function extract_cursor(array $decoded): ?string
{
    $cursor = $decoded['cursor'] ?? (is_array($decoded['data'] ?? null) ? ($decoded['data']['cursor'] ?? null) : null);

    return (is_string($cursor) && $cursor !== '') ? $cursor : null;
}

$divider = str_repeat('=', 74);

echo "{$divider}\n";
echo "POST {$baseUrl}/api/v1/hotels/search\n";
echo "{$divider}\n";
echo "Request body:\n" . json_encode($searchBody, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";

// ── Step 1: search init ─────────────────────────────────────────────────────
$search = sphinx_call($baseUrl . '/api/v1/hotels/search', $token, $insecure, $searchBody);
if ($search['error'] !== '') {
    echo "TRANSPORT ERROR: {$search['error']}\n";
    echo "(If this is a TLS/certificate error on a dev box, retry with SPHINX_API_INSECURE=1)\n";
    exit;
}
echo "HTTP {$search['code']}\n";

$searchDecoded = json_decode($search['body'], true);
if (!is_array($searchDecoded)) {
    echo "Search response was not JSON. Raw body:\n{$search['body']}\n";
    exit;
}
$cursor = extract_cursor($searchDecoded);
if ($cursor === null) {
    echo "No cursor returned — the search did not start. Raw response:\n";
    echo json_encode($searchDecoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    exit;
}
echo 'Search started; cursor received (len ' . strlen($cursor) . ").\n\n";

// ── Step 2: poll results, following the cursor until it is null ─────────────
$offers = [];
$polls = 0;
while ($cursor !== null && $polls < MAX_RESULT_POLLS) {
    if ($polls > 0) {
        sleep(POLL_PAUSE_SECONDS);
    }
    $polls++;

    $res = sphinx_call($baseUrl . '/api/v1/hotels/results?cursor=' . rawurlencode($cursor), $token, $insecure);
    if ($res['error'] !== '') {
        echo "  poll {$polls}: TRANSPORT ERROR: {$res['error']}\n";
        break;
    }
    $decoded = json_decode($res['body'], true);
    if (!is_array($decoded)) {
        echo "  poll {$polls}: results response was not JSON. Raw body:\n{$res['body']}\n";
        break;
    }

    $page = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
    // A `data` that is an assoc wrapper (not a list of offers) means no offers here.
    if ($page !== [] && array_is_list($page)) {
        foreach ($page as $offer) {
            if (is_array($offer)) {
                $offers[] = $offer;
            }
        }
    }
    echo '  poll ' . $polls . ': +' . (array_is_list($page) ? count($page) : 0) . ' offer(s) (total ' . count($offers) . ")\n";

    $cursor = extract_cursor($decoded);
}
// Immediate-confirmation = bookable on the spot (no request/on-hold step).
$immediateCount = count(array_filter(
    $offers,
    static fn ($o): bool => is_array($o) && ($o['confirmation'] ?? '') === 'immediate',
));

echo "\nResults complete: {$polls} poll(s), " . count($offers) . " offer(s) for destination {$destinationId}"
    . " ({$immediateCount} with confirmation=immediate).\n";

if ($immediateOnly) {
    echo "Filter: showing ONLY confirmation=immediate offers.\n";
    $offers = array_values(array_filter(
        $offers,
        static fn ($o): bool => is_array($o) && ($o['confirmation'] ?? '') === 'immediate',
    ));
}
echo "\n";

// ── Display ─────────────────────────────────────────────────────────────────
if ($offers === []) {
    $why = $immediateOnly
        ? "No confirmation=immediate offers (of {$immediateCount})."
        : 'No offers. (Try different dates/destination, or add &raw=1 to inspect the raw responses.)';
    echo "{$why}\n";
    exit;
}

// ── Distinct hotels ─────────────────────────────────────────────────────────
// Many offers share one hotel (different rooms/boards/prices); collapse them by
// hotel_id so you can see WHICH hotels and HOW MANY, not just the raw offer count.
// Computed from the final $offers, so immediate=1 narrows this too. First-seen name
// wins; insertion order preserved (faithful to the order the API streamed them).
$hotels = [];
foreach ($offers as $offer) {
    if (!is_array($offer)) {
        continue;
    }
    $hid = $offer['hotel_id'] ?? null;
    $key = is_scalar($hid) ? (string) $hid : (string) json_encode($hid);
    if (!isset($hotels[$key])) {
        $hotels[$key] = ['id' => $hid, 'name' => $offer['hotel_name'] ?? null, 'offers' => 0];
    }
    $hotels[$key]['offers']++;
}

echo "{$divider}\n";
echo 'DISTINCT HOTELS (' . count($hotels) . " unique)\n";
echo "{$divider}\n";
$hotelNo = 0;
foreach ($hotels as $hotel) {
    $hotelNo++;
    echo '  ' . str_pad((string) $hotelNo, 3, ' ', STR_PAD_LEFT) . '. '
        . str_pad(s($hotel['name']), 44)
        . ' [hotel_id ' . s($hotel['id']) . ']  — ' . $hotel['offers'] . " offer(s)\n";
}
echo "\n";

echo "{$divider}\n";
echo "OFFERS\n";
echo "{$divider}\n";
foreach ($offers as $i => $offer) {
    $rooms = is_array($offer['rooms'] ?? null) ? $offer['rooms'] : [];
    $roomNames = [];
    foreach ($rooms as $room) {
        if (is_array($room)) {
            $roomNames[] = s($room['name'] ?? '');
        }
    }
    $pricing = is_array($offer['pricing'] ?? null) ? $offer['pricing'] : [];
    $price = $pricing['selling_price'] ?? $offer['selling_price'] ?? $offer['price'] ?? null;
    $cur = $pricing['currency'] ?? $offer['currency'] ?? $currency;

    echo '#' . ($i + 1) . '  ' . s($offer['hotel_name'] ?? null) . '  [hotel_id ' . s($offer['hotel_id'] ?? null) . "]\n";
    echo '     room   : ' . ($roomNames === [] ? '(none)' : implode(' | ', $roomNames)) . "\n";
    echo '     board  : ' . s($offer['meal_type_name'] ?? null) . "\n";
    echo '     price  : ' . s($price) . ' ' . s($cur) . '   (' . s($offer['confirmation'] ?? null) . ', must_verify=' . s($offer['must_verify'] ?? null) . ")\n";
    echo '     offer_id: ' . s($offer['offer_id'] ?? null) . "\n\n";
}

if ($showRaw) {
    echo "{$divider}\n";
    echo "RAW OFFERS (JSON)\n";
    echo "{$divider}\n";
    echo json_encode($offers, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
}
