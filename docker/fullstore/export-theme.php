<?php

/**
 * The hotel page half of export-theme.sh (run inside the app container):
 * a hotel page as a logged-out visitor gets it and, with dates, the
 * supplier's results for that stay (two adults, one room), the HTML the
 * hotel page swaps in under the search bar. For Sphinx, whose results
 * stream in after the page, also every search_poll answer the page would
 * read.
 *
 * Names a product, or a supplier: then it picks that supplier's hotels with
 * the most photos and keeps the first one with offers for the dates (or the
 * first one, saying so, when none has any).
 *
 * Usage: php export-theme.php <store url> <out dir> <product id|novoton|sphinx|eurosite> [check_in check_out]
 */

declare(strict_types=1);

const SUPPLIER_TABLES = [
    'novoton' => 'novoton_hotels',
    'sphinx' => 'sphinx_hotels',
    'eurosite' => 'eurosite_hotels',
];
// Hotels tried for offers, most photos first: each try is a live supplier
// search.
const CANDIDATES = 5;
const SPHINX_POLLS = 30;

[, $base, $out, $what, $checkIn, $checkOut] = $argv + array_fill(0, 6, '');

function say(string $line): void
{
    fwrite(STDERR, "[export-theme] $line\n");
}

/**
 * GET as a visitor. Keeps the store's session cookie between calls: a
 * Sphinx search and its polls must share one session.
 */
function get(string $url): string
{
    static $cookies = [];

    $header = [];
    if ($cookies !== []) {
        $header[] = 'Cookie: ' . implode('; ', array_map(
            static fn (string $name, string $value): string => "$name=$value",
            array_keys($cookies),
            $cookies,
        ));
    }
    $body = @file_get_contents($url, false, stream_context_create([
        'http' => ['timeout' => 120, 'header' => $header, 'ignore_errors' => true],
    ]));
    $responseHeaders = function_exists('http_get_last_response_headers')
        ? (http_get_last_response_headers() ?? [])
        : ($http_response_header ?? []);
    foreach ($responseHeaders as $line) {
        if (preg_match('/^Set-Cookie:\s*([^=;\s]+)=([^;]*)/i', $line, $m) === 1) {
            $cookies[$m[1]] = $m[2];
        }
    }
    if ($body === false) {
        say("could not fetch $url");

        return '';
    }

    return $body;
}

/** @return list<int> the supplier's active hotel products, most photos first */
function candidates(string $supplier): array
{
    mysqli_report(MYSQLI_REPORT_OFF);
    $db = @mysqli_connect(
        getenv('CSCART_DB_HOST') ?: 'db',
        getenv('CSCART_DB_USER') ?: 'cscart',
        getenv('CSCART_DB_PASSWORD') ?: 'cscart',
        getenv('CSCART_DB_NAME') ?: 'cscart',
    );
    if ($db === false) {
        say('cannot reach the store database');

        return [];
    }
    // entrypoint.sh installs the store with the cscart_ table prefix.
    $table = 'cscart_' . SUPPLIER_TABLES[$supplier];
    $rows = @mysqli_query($db, "SELECT h.product_id, COUNT(il.pair_id) AS photos
        FROM `$table` h
        JOIN cscart_products p ON p.product_id = h.product_id AND p.status = 'A'
        LEFT JOIN cscart_images_links il ON il.object_id = h.product_id AND il.object_type = 'product'
        WHERE h.product_id > 0
        GROUP BY h.product_id
        ORDER BY photos DESC, h.product_id
        LIMIT " . CANDIDATES);
    if ($rows === false) {
        say("no $table table: is the $supplier add-on installed?");

        return [];
    }

    return array_map(static fn (array $row): int => (int) $row[0], mysqli_fetch_all($rows));
}

/** Offer cards in a results page (Sphinx's loading skeleton is not one). */
function offerCount(string $html): int
{
    return (int) preg_match_all('/class="travel-offer-card(?![^"]*skeleton)/', $html);
}

/**
 * Drains a Sphinx search the way its results page does (search_poll until
 * no cursor is left).
 *
 * @return array{0: list<mixed>, 1: int} the poll answers, the offers in them
 */
function pollSphinx(string $base, string $html): array
{
    if (preg_match('/<div[^>]*id="sphinx-results-container"[^>]*>/', $html, $tag) !== 1
        || preg_match('/data-search-id="([^"]*)"/', $tag[0], $id) !== 1
        || $id[1] === '') {
        return [[], 0];
    }
    $searchId = html_entity_decode($id[1], ENT_QUOTES | ENT_HTML5);
    $answers = [];
    $offers = 0;
    $cursor = '';
    for ($i = 0; $i < SPHINX_POLLS; $i++) {
        $data = json_decode(get("$base/index.php?dispatch=sphinx_booking.search_poll&search_id=" . rawurlencode($searchId)
            . ($cursor !== '' ? '&cursor=' . rawurlencode($cursor) : '')), true);
        if (!is_array($data)) {
            break;
        }
        $answers[] = $data;
        $offers += is_array($data['results'] ?? null) ? count($data['results']) : 0;
        $cursor = is_string($data['next_cursor'] ?? null) ? $data['next_cursor'] : '';
        if (($data['status'] ?? '') === 'error' || $cursor === '') {
            break;
        }
        sleep(2);
    }

    return [$answers, $offers];
}

$ids = ctype_digit($what) ? [(int) $what] : candidates($what);
if ($ids === []) {
    if (!ctype_digit($what)) {
        say("no active $what hotel products found");
    }
    exit(0);
}

$saveAll = static function (int $productId, array $files) use ($out): void {
    foreach ($files as $name => $body) {
        file_put_contents("$out/$name", $body);
    }
    say("exported hotel product $productId");
};

$fallback = null;
foreach ($ids as $productId) {
    $files = [];
    $page = get("$base/index.php?dispatch=products.view&product_id=$productId");
    if ($page !== '') {
        $files["product-$productId.html"] = $page;
    }
    if ($checkIn === '' || $checkOut === '') {
        $saveAll($productId, $files);
        exit(0);
    }

    $config = json_decode(get("$base/index.php?dispatch=travel_booking.booking_config&product_id=$productId&design=a"), true);
    if (!is_array($config) || empty($config['searchDispatch'])) {
        say("product $productId has no booking form (not a supplier hotel?)");
        $fallback ??= [$productId, $files];
        continue;
    }
    $files["booking-config-$productId.json"] = (string) json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    $results = get("$base/index.php?" . http_build_query([
        'dispatch' => $config['searchDispatch'],
        'hotel_id' => $config['hotelId'] ?? '',
        'product_id' => $productId,
        'check_in' => $checkIn,
        'check_out' => $checkOut,
        'adults' => 2,
        'children' => 0,
        'rooms' => 1,
        'rooms_data' => json_encode([['adults' => 2, 'children' => 0, 'childrenAges' => []]]),
    ]));
    if ($results !== '') {
        $files["results-$productId-$checkIn.html"] = $results;
    }
    $offers = offerCount($results);
    if ($offers === 0 && str_contains($results, 'id="sphinx-results-container"')) {
        [$answers, $offers] = pollSphinx($base, $results);
        if ($answers !== []) {
            $files["poll-$productId-$checkIn.json"] = (string) json_encode($answers, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }
    }
    say("product $productId: $offers offer(s) for $checkIn → $checkOut");

    if ($offers > 0) {
        $saveAll($productId, $files);
        exit(0);
    }
    $fallback ??= [$productId, $files];
}

if ($fallback !== null) {
    say('no offers for these dates in the hotels tried: the export has no rooms to look at. Try other dates (a summer week).');
    $saveAll(...$fallback);
}
