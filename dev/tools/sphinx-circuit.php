<?php

/**
 * sphinx-circuit.php — what Sphinx sends for ONE circuit, and what the store
 * keeps of it, read-only.
 *
 * WHY: to see a circuit's real API content (destinations, departures,
 * included / not included, itinerary, images…) next to what the circuits sync
 * stored in sphinx_circuits and what its product's features are made of —
 * without reading code or the database. Answers "why is this feature empty?"
 * on one circuit.
 *
 *   1. The static API answer, GET /api/v1/static/circuits/:id, as JSON.
 *   2. The stored circuit (sphinx_circuits): what the sync kept, long JSON
 *      columns decoded, and the CS-Cart product it is linked to.
 *   3. The facts its product's features are made of (CircuitFeatures): the
 *      same code the sync and "Apply to all circuits" use.
 *   4. With &rates=1: the departures Sphinx prices for it (circuits/rates,
 *      18 months, one call a month: slow) — the months and the cities.
 *
 * Read-only: nothing is written or cached. The API address and key are never
 * printed.
 *
 * WHERE: the fullstore sandbox only — docker/fullstore/docker-compose.yml
 * bind-mounts dev/ at /var/www/html/dev. Refuses non-localhost HTTP requests.
 *
 * USE:
 *   http://localhost:8080/dev/tools/sphinx-circuit.php?circuit=641
 *   ...?product=1649                    → the circuit of this CS-Cart product
 *   ...&only=included,not_included      → only these keys of the API answer (comma list)
 *   ...&rates=1                         → also the rates: months and departure cities
 *   ...&api=0 / &stored=0 / &facts=0    → leave out part 1 / 2 / 3
 *   CLI: docker compose exec app php /var/www/html/dev/tools/sphinx-circuit.php circuit=641
 */

use Tygh\Addons\SphinxHolidays\Repository\CircuitRepository;
use Tygh\Addons\SphinxHolidays\Services\BookableCircuits;
use Tygh\Addons\SphinxHolidays\Services\CircuitFeatures;
use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;
use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

$sc_is_cli = PHP_SAPI === 'cli';

if (!$sc_is_cli) {
    $sc_host = strtolower((string) strtok((string) ($_SERVER['HTTP_HOST'] ?? ''), ':'));
    if (!in_array($sc_host, ['localhost', '127.0.0.1', '[::1]'], true)) {
        http_response_code(403);
        exit("sphinx-circuit is a local sandbox tool - refusing non-localhost request\n");
    }
}

// dev/ is normally mounted inside the docroot; an older container has the repo at /repo.
$sc_docroot = dirname(__DIR__, 2);
if (!is_file($sc_docroot . '/init.php') && is_file('/var/www/html/init.php')) {
    $sc_docroot = '/var/www/html';
}
if (!is_file($sc_docroot . '/init.php')) {
    exit("No CS-Cart init.php at {$sc_docroot} — this tool only works from the fullstore container.\n");
}

const AREA = 'A';
const ACCOUNT_TYPE = 'admin';
if ($sc_is_cli) {
    $_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';
    $_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    chdir($sc_docroot);
}
require $sc_docroot . '/init.php';
if (!$sc_is_cli) {
    header('Content-Type: text/plain; charset=utf-8');
}

if (!class_exists(Container::class)) {
    exit("The sphinx_holidays add-on is not installed/active in this store.\n");
}

set_time_limit(300);

/** A request / CLI parameter ("key=value" on the CLI). */
function sc_param(string $key, string $default = ''): string
{
    if (PHP_SAPI === 'cli') {
        foreach (array_slice((array) ($GLOBALS['argv'] ?? []), 1) as $arg) {
            if (is_string($arg) && str_starts_with($arg, $key . '=')) {
                return trim(substr($arg, strlen($key) + 1));
            }
        }

        return $default;
    }

    return isset($_GET[$key]) && is_scalar($_GET[$key]) ? trim((string) $_GET[$key]) : $default;
}

function sc_h(string $title): void
{
    echo "\n", str_repeat('=', 78), "\n", $title, "\n", str_repeat('=', 78), "\n";
}

function sc_json(mixed $value): string
{
    return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
}

/** The API address and key must not reach a pasted page. */
$sc_mask = static function (string $text): string {
    $base = ConfigProvider::getApiBaseUrl();
    $secrets = array_values(array_filter(
        [$base, (string) parse_url($base, PHP_URL_HOST), ConfigProvider::getApiKey()],
        static fn (string $secret): bool => strlen($secret) >= 4,
    ));

    return $secrets === [] ? $text : str_replace($secrets, '***', $text);
};

$sc_circuit = (int) sc_param('circuit');
$sc_product = (int) sc_param('product');
if ($sc_circuit <= 0 && $sc_product > 0) {
    $sc_circuit = TypeCoerce::toInt(db_get_field('SELECT circuit_id FROM ?:sphinx_circuits WHERE product_id = ?i', $sc_product));
    if ($sc_circuit <= 0) {
        exit("Product #{$sc_product} is not linked to a stored circuit.\n");
    }
}
if ($sc_circuit <= 0) {
    exit("Give a circuit id (?circuit=641) or the CS-Cart product of one (?product=1649).\n");
}

$sc_row = TypeCoerce::toStringMap(db_get_row('SELECT * FROM ?:sphinx_circuits WHERE circuit_id = ?i', $sc_circuit));
echo 'sphinx-circuit — circuit ', $sc_circuit;
echo $sc_row !== [] ? ' (' . TypeCoerce::toString($sc_row['name'] ?? '') . ')' : ' (not stored)';
echo ', product ', TypeCoerce::toInt($sc_row['product_id'] ?? 0) > 0 ? '#' . TypeCoerce::toInt($sc_row['product_id']) : '(none)', "\n";

$sc_api = Container::getApi();

// ---------------------------------------------------------------- 1. the API answer

if (sc_param('api', '1') !== '0') {
    sc_h('1. Sphinx — GET /api/v1/static/circuits/' . $sc_circuit);
    $sc_answer = $sc_api->getCircuit($sc_circuit);
    if ($sc_answer === null) {
        echo 'No answer: ', $sc_mask($sc_api->lastError()), "\n";
    } else {
        $sc_data = $sc_answer['data'] ?? $sc_answer;
        $sc_only = array_values(array_filter(array_map('trim', explode(',', sc_param('only')))));
        if ($sc_only !== [] && is_array($sc_data)) {
            $sc_data = array_intersect_key($sc_data, array_flip($sc_only));
        }
        echo sc_json($sc_data), "\n";
    }
}

// ---------------------------------------------------------------- 2. the stored circuit

if (sc_param('stored', '1') !== '0') {
    sc_h('2. Stored — sphinx_circuits (what the sync kept)');
    if ($sc_row === []) {
        echo "This circuit is not stored: the circuits sync has not kept it (no rate from today on, or not synced yet).\n";
    } else {
        foreach ($sc_row as $sc_column => $sc_value) {
            $sc_text = TypeCoerce::toString($sc_value);
            if ($sc_text !== '' && in_array($sc_text[0], ['[', '{'], true)) {
                $sc_decoded = json_decode($sc_text, true);
                $sc_text = $sc_decoded !== null ? "\n" . sc_json($sc_decoded) : $sc_text;
            }
            echo str_pad((string) $sc_column, 18), ' ', $sc_text === '' ? '(empty)' : $sc_text, "\n";
        }
    }
}

// ---------------------------------------------------------------- 3. the facts for the features

if (sc_param('facts', '1') !== '0') {
    sc_h('3. Facts for the product\'s features (CircuitFeatures — what the sync writes)');
    if ($sc_row === []) {
        echo "Nothing stored, so no facts.\n";
    } else {
        $sc_repo = new CircuitRepository();
        $sc_destinations = TypeCoerce::toIntList(json_decode(TypeCoerce::toString($sc_row['destination_ids'] ?? ''), true) ?: []);
        $sc_facts = CircuitFeatures::facts($sc_row, $sc_repo->countryNames($sc_destinations), date('Y-m'));
        echo sc_json(get_object_vars($sc_facts)), "\n";
        echo "\nAn empty list or value here is why its feature stays empty: the sync has not stored it (run a full circuits sync),\nor the API does not send it.\n";
    }
}

// ---------------------------------------------------------------- 4. the rates

if (sc_param('rates') === '1') {
    sc_h('4. Rates — circuits/rates, 18 months (the months and the departure cities the sync reads)');
    $sc_rates = new BookableCircuits($sc_api);
    $sc_bookable = $sc_rates->ids(date('Y-m-d'), [$sc_circuit], true);
    if ($sc_bookable === null) {
        echo 'The rates could not be read, or Sphinx prices no departure of this circuit from today on. ', $sc_mask($sc_api->lastError()), "\n";
    } else {
        echo sc_json($sc_rates->facts()[$sc_circuit] ?? []), "\n";
    }
}

echo "\nRead-only: nothing was written.\n";
