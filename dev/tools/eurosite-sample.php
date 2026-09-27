<?php

/**
 * eurosite-sample.php — real Eurosite hotel data for the "Eurosite → Hotels"
 * mockup, from THIS store.
 *
 * WHY: the hotel-list mockup must show real hotels, real availability and
 * real prices. The cloud session that builds it cannot reach the Eurosite
 * host; this store can. The page reads the hotels the `hotels` cron already
 * synced (with their destination names), then asks the live API — READ-ONLY
 * getHotelPriceRequest, the same search the storefront runs — what each
 * destination offers for one sample stay. Nothing is booked, written or
 * cached; no credential is printed.
 *
 * WHERE: the fullstore sandbox only — docker/fullstore/docker-compose.yml
 * bind-mounts dev/ at /var/www/html/dev. Refuses non-localhost HTTP requests.
 *
 * USE:
 *   http://localhost:8080/dev/tools/eurosite-sample.php
 *       → JSON for the WHITELISTED destinations that have hotels (largest first,
 *         up to 40), stay +30 days, 7 nights, 2 adults — plus the whitelist and,
 *         per hotel, how many pictures the product-info cache holds
 *   ...?cities=RO0218,RO2M,ROBC   → those destinations instead
 *   ...?check_in=2026-11-02&nights=5&limit=10
 *   ...&download=1                → saved as eurosite-sample.json
 *   CLI: docker compose exec app php /var/www/html/dev/tools/eurosite-sample.php [cities=RO0218,RO2M]
 */

use Tygh\Addons\Eurosite\Services\Container;

$es_is_cli = PHP_SAPI === 'cli';

if (!$es_is_cli) {
    $es_host = strtolower((string) strtok((string) ($_SERVER['HTTP_HOST'] ?? ''), ':'));
    if (!in_array($es_host, ['localhost', '127.0.0.1', '[::1]'], true)) {
        http_response_code(403);
        exit("eurosite-sample is a local sandbox tool - refusing non-localhost request\n");
    }
}

$es_docroot = dirname(__DIR__, 2);
if (!is_file($es_docroot . '/init.php')) {
    exit("No CS-Cart init.php at {$es_docroot} — this tool only works from the fullstore\n"
        . "container, where dev/ is mounted inside the CS-Cart docroot (see docker/fullstore).\n");
}

const AREA = 'A';
const ACCOUNT_TYPE = 'admin';
require $es_docroot . '/init.php';

/** @return string request/CLI parameter */
function es_param(string $key, string $default = ''): string
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

if (!class_exists(Container::class)) {
    exit("The eurosite add-on is not installed/active in this store.\n");
}

set_time_limit(600);

$es_nights = max(1, min(21, (int) es_param('nights', '7')));
$es_check_in = es_param('check_in', date('Y-m-d', strtotime('+30 days')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $es_check_in)) {
    exit("check_in must be YYYY-MM-DD\n");
}
$es_check_out = date('Y-m-d', (int) strtotime($es_check_in . ' +' . $es_nights . ' days'));
$es_limit = max(1, min(60, (int) es_param('limit', '40')));

// Destination names and hotel counts come from this store's own sync.
$es_per_city = db_get_array(
    'SELECT h.city_code, h.country_code, COUNT(*) AS hotels, c.name AS city_name'
    . ' FROM ?:eurosite_hotels h LEFT JOIN ?:eurosite_cities c ON c.city_code = h.city_code'
    . " WHERE h.sync_status = 'active' GROUP BY h.city_code, h.country_code, c.name ORDER BY hotels DESC",
);
// The destination whitelist, and which synced destinations it allows.
$es_whitelist_repo = Container::whitelist();
$es_allowed = [];
foreach ($es_whitelist_repo->getCountryCodes() as $es_cc) {
    foreach ($es_whitelist_repo->getAllowedCityCodes($es_cc) as $es_city) {
        $es_allowed[$es_city] = true;
    }
}
foreach ($es_per_city as $es_i => $es_r) {
    $es_per_city[$es_i]['whitelisted'] = isset($es_allowed[(string) $es_r['city_code']]);
}

$es_wanted = array_values(array_filter(array_map('strtoupper', array_map('trim', explode(',', es_param('cities'))))));
if ($es_wanted === []) {
    $es_wanted = array_slice(array_column(array_filter($es_per_city, static fn (array $r): bool => $r['whitelisted']), 'city_code'), 0, $es_limit);
}
$es_city_meta = array_column($es_per_city, null, 'city_code');

// Pictures per hotel, from the product_info cache (filled by the product_info cron).
$es_pictures = [];
foreach (db_get_array('SELECT tourop_code, product_code, pictures_json FROM ?:eurosite_product_info_cache') as $es_pr) {
    $es_list = json_decode((string) ($es_pr['pictures_json'] ?? ''), true);
    $es_pictures[$es_pr['tourop_code'] . '|' . $es_pr['product_code']] = is_array($es_list) ? count($es_list) : 0;
}

$es_api = Container::getApi();
$es_hotel_repo = Container::hotels();
$es_rank = ['IM' => 3, 'OR' => 2, 'ST' => 1, '' => 0];

$es_out = [
    'source' => 'live getHotelPriceRequest + this store\'s ?:eurosite_hotels / ?:eurosite_cities',
    'fetched_at' => date('c'),
    'stay' => ['check_in' => $es_check_in, 'check_out' => $es_check_out, 'nights' => $es_nights, 'room' => 'DB', 'adults' => 2],
    'store_totals' => [
        'hotels' => (int) db_get_field("SELECT COUNT(*) FROM ?:eurosite_hotels WHERE sync_status = 'active'"),
        'destinations' => count($es_per_city),
        'linked_products' => (int) db_get_field('SELECT COUNT(*) FROM ?:eurosite_hotels WHERE product_id IS NOT NULL AND product_id > 0'),
    ],
    'whitelist' => array_map(static fn (array $r): array => [
        'country' => (string) ($r['country_code'] ?? ''), 'city' => (string) ($r['city_code'] ?? ''), 'type' => (string) ($r['selection_type'] ?? ''),
    ], $es_whitelist_repo->findAll()),
    'destinations_by_size' => array_map(static fn (array $r): array => [
        'code' => (string) $r['city_code'], 'name' => (string) ($r['city_name'] ?? ''), 'country' => (string) $r['country_code'],
        'hotels' => (int) $r['hotels'], 'whitelisted' => (bool) $r['whitelisted'],
    ], $es_per_city),
    'cities' => [],
    'errors' => [],
];

foreach ($es_wanted as $es_code) {
    $es_meta = $es_city_meta[$es_code] ?? ['country_code' => 'RO', 'city_name' => ''];
    $es_country = (string) ($es_meta['country_code'] ?: 'RO');

    $es_hotels = [];
    foreach ($es_hotel_repo->getByCity($es_code) as $es_row) {
        $es_pc = (string) ($es_row['product_code'] ?? '');
        $es_hotels[$es_pc] = [
            'code' => $es_pc,
            'tourop' => (string) ($es_row['tourop_code'] ?? ''),
            'name' => (string) ($es_row['name'] ?? ''),
            'stars' => 0,
            'class' => '',
            'availability' => '',
            'offers' => 0,
            'price' => null,
            'gross' => null,
            'currency' => '',
            'image' => '',
            'product_id' => isset($es_row['product_id']) ? (int) $es_row['product_id'] : null,
            'last_synced_at' => (string) ($es_row['last_synced_at'] ?? ''),
            'info_cached' => array_key_exists(((string) ($es_row['tourop_code'] ?? '')) . '|' . $es_pc, $es_pictures),
            'pictures' => $es_pictures[((string) ($es_row['tourop_code'] ?? '')) . '|' . $es_pc] ?? 0,
        ];
    }

    $es_tourops = $es_hotel_repo->getTouropCodesForCity($es_code) ?: [''];
    foreach ($es_tourops as $es_to) {
        try {
            $es_offers = $es_api->searchHotels([
                'country_code' => $es_country,
                'city_code' => $es_code,
                'tourop_code' => $es_to,
                'check_in' => $es_check_in,
                'check_out' => $es_check_out,
                'currency' => 'EUR',
                'rooms' => [['code' => 'DB', 'adults' => 2, 'children' => []]],
            ]);
        } catch (\Throwable $e) {
            $es_out['errors'][] = "{$es_code}/{$es_to}: " . $e->getMessage();
            continue;
        }
        foreach ($es_offers as $es_o) {
            $es_pc = $es_o->productCode;
            if (!isset($es_hotels[$es_pc])) {
                $es_hotels[$es_pc] = ['code' => $es_pc, 'tourop' => $es_to, 'name' => $es_o->productName, 'stars' => 0, 'class' => '',
                    'availability' => '', 'offers' => 0, 'price' => null, 'gross' => null, 'currency' => '', 'image' => '',
                    'product_id' => null, 'last_synced_at' => '', 'not_in_local_sync' => true,
                    'info_cached' => array_key_exists($es_to . '|' . $es_pc, $es_pictures), 'pictures' => $es_pictures[$es_to . '|' . $es_pc] ?? 0];
            }
            $es_h = &$es_hotels[$es_pc];
            $es_h['name'] = $es_o->productName !== '' ? $es_o->productName : $es_h['name'];
            $es_h['stars'] = $es_o->category;
            $es_h['class'] = $es_o->class;
            $es_h['image'] = $es_h['image'] !== '' ? $es_h['image'] : $es_o->firstImage;
            $es_h['offers']++;
            $es_code_av = $es_o->availabilityCode;
            if (($es_rank[$es_code_av] ?? 0) > ($es_rank[$es_h['availability']] ?? 0)) {
                $es_h['availability'] = $es_code_av;
            }
            if ($es_o->isBookable() && ($es_h['price'] === null || $es_o->price < $es_h['price'])) {
                $es_h['price'] = $es_o->price;
                $es_h['gross'] = $es_o->gross;
                $es_h['currency'] = $es_o->currency;
            }
            unset($es_h);
        }
    }

    $es_out['cities'][] = [
        'code' => $es_code,
        'name' => (string) ($es_meta['city_name'] ?? ''),
        'country' => $es_country,
        'whitelisted' => isset($es_allowed[$es_code]),
        'hotels' => array_values($es_hotels),
    ];
}

$es_json = (string) json_encode($es_out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

if (!$es_is_cli) {
    header('Content-Type: application/json; charset=utf-8');
    if (es_param('download') !== '') {
        header('Content-Disposition: attachment; filename="eurosite-sample.json"');
    }
}
echo $es_json, "\n";
