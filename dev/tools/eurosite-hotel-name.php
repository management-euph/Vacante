<?php

/**
 * eurosite-hotel-name.php — where does a Eurosite hotel's name come from?
 *
 * WHY: some store products read "ALTUS CM", "4Cardinals Boutique CM"… while
 * the hotel itself is "Altus Hotel & Spa". The product name is written once,
 * when the product is created, from the hotel row the `hotels` cron synced
 * (getOwnHotelsRequest → <HotelName>), unless the SEO "product name" template
 * says otherwise. This page puts every place the name lives side by side for
 * one hotel, so you can see which one carries the suffix:
 *
 *   1. the store: ?:eurosite_hotels.name and the product's name per language
 *   2. live getOwnHotelsRequest for the hotel's city: its <Hotel> element, raw
 *   3. live getProductInfoRequest: <ProductName> and the start of the text
 *   4. live getHotelPriceRequest (one sample stay): <ProductName> of its offers
 *
 * Read-only: nothing is booked, written or cached. The request envelope (it
 * holds the account) is never printed — only the request details.
 *
 * WHERE: the fullstore sandbox only — docker/fullstore/docker-compose.yml
 * bind-mounts dev/ at /var/www/html/dev. Refuses non-localhost HTTP requests.
 *
 * USE:
 *   http://localhost:8080/dev/tools/eurosite-hotel-name.php?hotel=ALTUS
 *   ...?hotel=RO0451                     → by Eurosite product code
 *   ...?product_id=123                   → by store product id
 *   ...&check_in=2026-12-10&nights=5     → the stay for step 4 (default +30 days, 7 nights)
 *   ...&search=0                         → skip step 4
 *   CLI: docker compose exec app php /var/www/html/dev/tools/eurosite-hotel-name.php hotel=ALTUS
 *        (or php /repo/dev/tools/eurosite-hotel-name.php hotel=ALTUS on an older container)
 */

use Tygh\Addons\Eurosite\Services\Container;

$hn_is_cli = PHP_SAPI === 'cli';

if (!$hn_is_cli) {
    $hn_host = strtolower((string) strtok((string) ($_SERVER['HTTP_HOST'] ?? ''), ':'));
    if (!in_array($hn_host, ['localhost', '127.0.0.1', '[::1]'], true)) {
        http_response_code(403);
        exit("eurosite-hotel-name is a local sandbox tool - refusing non-localhost request\n");
    }
}

// dev/ is normally mounted inside the docroot. A container created before
// that mount still has the repo at /repo, so `php /repo/dev/tools/…` works too.
$hn_docroot = dirname(__DIR__, 2);
if (!is_file($hn_docroot . '/init.php') && is_file('/var/www/html/init.php')) {
    $hn_docroot = '/var/www/html';
}
if (!is_file($hn_docroot . '/init.php')) {
    exit("No CS-Cart init.php at {$hn_docroot} — this tool only works from the fullstore\n"
        . "container, where dev/ is mounted inside the CS-Cart docroot (see docker/fullstore).\n");
}

const AREA = 'A';
const ACCOUNT_TYPE = 'admin';
require $hn_docroot . '/init.php';
// After init: CS-Cart sends its own text/html header, and a browser would
// then swallow every <Tag> of the raw XML below.
if (!$hn_is_cli) {
    header('Content-Type: text/plain; charset=utf-8');
}

/** @return string request/CLI parameter */
function hn_param(string $key, string $default = ''): string
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

function hn_h(string $title): void
{
    echo "\n", str_repeat('=', 78), "\n", $title, "\n", str_repeat('=', 78), "\n";
}

/** Marks the names that end in (or contain) the stand-alone token "CM". */
function hn_flag(string $name): string
{
    return preg_match('/(^|\s)CM(\s|$)/u', $name) === 1 ? '   <-- has "CM"' : '';
}

/**
 * One live call: the raw response body, or null with the reason printed.
 * Only the request DETAILS are echoed — the envelope carries the account.
 */
function hn_call(string $requestType, string $detailsXml): ?\SimpleXMLElement
{
    echo "Request details:\n", $detailsXml, "\n\n";
    try {
        $xml = Container::getXmlBuilder()->envelope($requestType, $detailsXml, '901', date('Y-m-d\TH:i:s'));
        $raw = Container::getHttpClient()->post($xml);
    } catch (\Throwable $e) {
        echo "FAILED: ", $e->getMessage(), "\n";

        return null;
    }
    $doc = @simplexml_load_string($raw, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
    if (!$doc instanceof \SimpleXMLElement) {
        echo "FAILED: the answer is not XML (", strlen($raw), " bytes)\n";

        return null;
    }
    $details = $doc->ResponseDetails;
    foreach (($details instanceof \SimpleXMLElement ? $details->children() : null) ?? [] as $child) {
        if ($child->getName() === 'Errors') {
            echo "API ERROR:\n", (string) $child->asXML(), "\n";

            return null;
        }

        return $child;
    }
    echo "FAILED: no <ResponseDetails> in the answer\n";

    return null;
}

/** Pretty-printed XML of one element, long text nodes cut so the page stays readable. */
function hn_xml(\SimpleXMLElement $el, int $max = 4000): string
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

set_time_limit(300);

$hn_query = hn_param('hotel');
$hn_pid = (int) hn_param('product_id', '0');
if ($hn_query === '' && $hn_pid <= 0) {
    exit("Say which hotel: ?hotel=ALTUS (part of the name or the product code) or ?product_id=123\n");
}

// ---------------------------------------------------------------- 1. store
if ($hn_pid > 0) {
    $hn_rows = db_get_array('SELECT * FROM ?:eurosite_hotels WHERE product_id = ?i', $hn_pid);
} else {
    $hn_rows = db_get_array(
        'SELECT * FROM ?:eurosite_hotels WHERE product_code = ?s OR name LIKE ?s ORDER BY name LIMIT 6',
        strtoupper($hn_query),
        '%' . $hn_query . '%',
    );
}

hn_h('1. The store (?:eurosite_hotels and the CS-Cart product)');
if ($hn_rows === []) {
    exit("No synced Eurosite hotel matches \"{$hn_query}{$hn_pid}\". Run the hotels sync, or try another spelling.\n");
}
if (count($hn_rows) > 1) {
    echo count($hn_rows), " hotels match — showing the first; narrow with ?hotel=<product code>:\n";
    foreach ($hn_rows as $hn_r) {
        echo "  ", $hn_r['product_code'], "  ", $hn_r['name'], "  (", $hn_r['city_code'], ")\n";
    }
    echo "\n";
}
$hn_hotel = $hn_rows[0];
$hn_code = (string) $hn_hotel['product_code'];
$hn_tourop = (string) ($hn_hotel['tourop_code'] ?? '');
$hn_city = (string) ($hn_hotel['city_code'] ?? '');
$hn_country = (string) ($hn_hotel['country_code'] ?? '') ?: 'RO';
$hn_names = ['store hotel row' => (string) $hn_hotel['name']];

echo "Hotel code   : {$hn_code} (tour operator {$hn_tourop}, city {$hn_city}, country {$hn_country})\n";
echo "Synced name  : ", $hn_hotel['name'], hn_flag((string) $hn_hotel['name']), "\n";
echo "Last synced  : ", (string) ($hn_hotel['last_synced_at'] ?? ''), "\n";
$hn_product_id = (int) ($hn_hotel['product_id'] ?? 0);
if ($hn_product_id > 0) {
    echo "Product id   : {$hn_product_id}\n";
    foreach (db_get_array('SELECT lang_code, product FROM ?:product_descriptions WHERE product_id = ?i', $hn_product_id) as $hn_d) {
        echo "Product name [", $hn_d['lang_code'], "]: ", $hn_d['product'], hn_flag((string) $hn_d['product']), "\n";
        $hn_names['product name ' . $hn_d['lang_code']] = (string) $hn_d['product'];
    }
} else {
    echo "Product id   : none yet (the hotel has no store product)\n";
}
// What the product step would name it today: the SEO "product name" template
// rendered for this hotel (empty template → the synced name as is).
$hn_details = Container::hotels()->findWithDetails($hn_tourop, $hn_code) ?? $hn_hotel;
$hn_placeholders = \Tygh\Addons\Eurosite\Services\EurositeProductFactory::placeholders($hn_details);
$hn_lang = defined('CART_LANGUAGE') ? (string) CART_LANGUAGE : 'ro';
$hn_seo = fn_travel_core_apply_seo_fields('eurosite', $hn_placeholders, 0, \Tygh\Addons\Eurosite\Services\EurositeProductFactory::productCode($hn_tourop, $hn_code), $hn_lang);
$hn_new = trim((string) ($hn_seo['product'] ?? ''));
$hn_settings = fn_travel_core_seo_settings('eurosite');
echo "SEO name template ({$hn_lang}): ", trim((string) ($hn_settings['seo_product_name__' . $hn_lang] ?? $hn_settings['seo_product_name'] ?? '')) ?: '(empty: the synced name as is)', "\n";
echo "A new product would be named: ", $hn_new !== '' ? $hn_new : (string) $hn_placeholders['name'], hn_flag($hn_new !== '' ? $hn_new : (string) $hn_placeholders['name']), "\n";

// ---------------------------------------------------------------- 2. getOwnHotels
hn_h("2. Live getOwnHotelsRequest for city {$hn_city} — the source of the synced name");
$hn_own = hn_call('getOwnHotelsRequest', '<getOwnHotelsRequest><CityCode>' . htmlspecialchars($hn_city, ENT_XML1) . '</CityCode></getOwnHotelsRequest>');
if ($hn_own !== null) {
    $hn_found = false;
    foreach ($hn_own->Hotel ?? [] as $hn_oh) {
        if (trim((string) $hn_oh->HotelCode) === $hn_code) {
            $hn_found = true;
            $hn_names['getOwnHotels HotelName'] = trim((string) $hn_oh->HotelName);
            echo "<HotelName>: ", trim((string) $hn_oh->HotelName), hn_flag(trim((string) $hn_oh->HotelName)), "\n\nRaw <Hotel> element:\n", hn_xml($hn_oh), "\n";
        }
    }
    if (!$hn_found) {
        echo "This city's answer has no <Hotel> with code {$hn_code} (", count($hn_own->Hotel ?? []), " hotels in the answer).\n";
    }
}

// ---------------------------------------------------------------- 3. getProductInfo
hn_h('3. Live getProductInfoRequest — the hotel page details');
$hn_info = hn_call('getProductInfoRequest', '<getProductInfoRequest>'
    . '<ProductType>hotel</ProductType>'
    . '<CountryCode>' . htmlspecialchars($hn_country, ENT_XML1) . '</CountryCode>'
    . '<CityCode>' . htmlspecialchars($hn_city, ENT_XML1) . '</CityCode>'
    . '<TourOpCode>' . htmlspecialchars($hn_tourop, ENT_XML1) . '</TourOpCode>'
    . '<ProductCode>' . htmlspecialchars($hn_code, ENT_XML1) . '</ProductCode>'
    . '</getProductInfoRequest>');
if ($hn_info !== null) {
    $hn_p = $hn_info->Product ?? $hn_info;
    $hn_pname = trim((string) ($hn_p->ProductName ?? ''));
    $hn_names['getProductInfo ProductName'] = $hn_pname;
    echo "<ProductName>: ", $hn_pname, hn_flag($hn_pname), "\n";
    foreach (['Description', 'DescriptionDet'] as $hn_tag) {
        $hn_text = trim(preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode(html_entity_decode((string) ($hn_p->{$hn_tag} ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES | ENT_HTML5, 'UTF-8'))) ?? '');
        if ($hn_text !== '') {
            echo "<{$hn_tag}> starts: ", mb_substr($hn_text, 0, 300), mb_strlen($hn_text) > 300 ? '…' : '', "\n";
        }
    }
    unset($hn_p->Description, $hn_p->DescriptionDet, $hn_p->Pictures);
    echo "\nRaw <Product> (description and pictures left out):\n", hn_xml($hn_p), "\n";
}

// ---------------------------------------------------------------- 4. getHotelPrice
if (hn_param('search', '1') !== '0') {
    $hn_nights = max(1, min(21, (int) hn_param('nights', '7')));
    $hn_in = hn_param('check_in', date('Y-m-d', strtotime('+30 days')));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $hn_in)) {
        exit("check_in must be YYYY-MM-DD\n");
    }
    $hn_out = date('Y-m-d', (int) strtotime($hn_in . ' +' . $hn_nights . ' days'));
    hn_h("4. Live getHotelPriceRequest — what a search shows ({$hn_in} → {$hn_out}, 2 adults, city {$hn_city})");
    // The storefront's own search payload (the add-on's builder), for the whole city.
    $hn_payload = (new \ReflectionMethod(\Tygh\Addons\Eurosite\Api\EurositeApiClient::class, 'buildHotelPricePayload'))->invoke(Container::getApi(), [
        'country_code' => $hn_country,
        'city_code' => $hn_city,
        'tourop_code' => $hn_tourop,
        'check_in' => $hn_in,
        'check_out' => $hn_out,
        'currency' => 'EUR',
        'rooms' => [['code' => 'DB', 'adults' => 2, 'children' => []]],
    ]);
    $hn_price = hn_call('getHotelPriceRequest', (string) $hn_payload);
    if ($hn_price !== null) {
        $hn_seen = 0;
        foreach ($hn_price->Hotel ?? [] as $hn_ph) {
            $hn_prod = $hn_ph->Product ?? null;
            if ($hn_prod === null || trim((string) $hn_prod->ProductCode) !== $hn_code) {
                continue;
            }
            $hn_seen++;
            $hn_sname = trim((string) $hn_prod->ProductName);
            $hn_names['getHotelPrice ProductName'] = $hn_sname;
            echo "<ProductName>: ", $hn_sname, hn_flag($hn_sname), " (", count($hn_ph->Offers->Offer ?? []), " offers)\n";
            if ($hn_seen === 1) {
                echo "\nRaw <Product> of the first result:\n", hn_xml($hn_prod, 2500), "\n";
            }
        }
        if ($hn_seen === 0) {
            echo "No offer for {$hn_code} on these dates (", count($hn_price->Hotel ?? []), " hotels answered) — try other dates with &check_in=YYYY-MM-DD.\n";
        }
    }
}

// ---------------------------------------------------------------- verdict
hn_h('Summary — the name in each place');
foreach ($hn_names as $hn_where => $hn_name) {
    printf("%-28s %s%s\n", $hn_where, $hn_name, hn_flag($hn_name));
}
$hn_api = array_filter($hn_names, static fn (string $n, string $w): bool => !str_starts_with($w, 'store') && !str_starts_with($w, 'product name') && hn_flag($n) !== '', ARRAY_FILTER_USE_BOTH);
echo "\n", $hn_api !== []
    ? 'The "CM" comes from the API (' . implode(', ', array_keys($hn_api)) . "): the store copies the operator's name.\n"
    : "No live API answer above carries \"CM\": look at the SEO product-name template, or the product was renamed in the store.\n";
