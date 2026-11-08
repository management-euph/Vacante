<?php

/**
 * sphinx-circuit-dates.php — the departures Sphinx prices for one circuit
 * (circuits/rates), read-only: why the circuits sync keeps it.
 *
 * WHY: the circuits sync keeps only the circuits Sphinx prices on a
 * departure from today on (BookableCircuits). A title can still read
 * "… 2025" while its departures are in 2027: this lists them, month by
 * month, as the sync asks.
 *
 * Read-only: rates only; nothing is written. The API address and key are
 * never printed.
 *
 * WHERE: the fullstore sandbox only — docker/fullstore/docker-compose.yml
 * bind-mounts dev/ at /var/www/html/dev. Refuses non-localhost HTTP requests.
 *
 * USE:
 *   http://localhost:8080/dev/tools/sphinx-circuit-dates.php?circuit=1550
 *   CLI: docker compose exec app php /var/www/html/dev/tools/sphinx-circuit-dates.php circuit=1550
 */

use Tygh\Addons\SphinxHolidays\Services\BookableCircuits;
use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;
use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

$cd_is_cli = PHP_SAPI === 'cli';

if (!$cd_is_cli) {
    $cd_host = strtolower((string) strtok((string) ($_SERVER['HTTP_HOST'] ?? ''), ':'));
    if (!in_array($cd_host, ['localhost', '127.0.0.1', '[::1]'], true)) {
        http_response_code(403);
        exit("sphinx-circuit-dates is a local sandbox tool - refusing non-localhost request\n");
    }
    header('Content-Type: text/plain; charset=utf-8');
}

$cd_docroot = dirname(__DIR__, 2);
if (!is_file($cd_docroot . '/init.php')) {
    exit("No CS-Cart init.php at {$cd_docroot} — this tool only works from the fullstore container.\n");
}

const AREA = 'A';
const ACCOUNT_TYPE = 'admin';
require $cd_docroot . '/init.php';

set_time_limit(300);

$cd_circuit = 0;
if ($cd_is_cli) {
    foreach (array_slice((array) ($GLOBALS['argv'] ?? []), 1) as $cd_arg) {
        if (is_string($cd_arg) && str_starts_with($cd_arg, 'circuit=')) {
            $cd_circuit = (int) substr($cd_arg, 8);
        }
    }
} else {
    $cd_circuit = (int) ($_GET['circuit'] ?? 0);
}
if ($cd_circuit <= 0) {
    exit("Give a circuit id: ?circuit=1550\n");
}

$cd_mask = static function (string $text): string {
    $base = ConfigProvider::getApiBaseUrl();
    $secrets = array_values(array_filter(
        [$base, (string) parse_url($base, PHP_URL_HOST), ConfigProvider::getApiKey()],
        static fn (string $secret): bool => strlen($secret) >= 4,
    ));

    return $secrets === [] ? $text : str_replace($secrets, '***', $text);
};

$cd_today = date('Y-m-d');
$cd_name = TypeCoerce::toString(db_get_field('SELECT name FROM ?:sphinx_circuits WHERE circuit_id = ?i', $cd_circuit));
echo 'sphinx-circuit-dates — circuit ', $cd_circuit, $cd_name !== '' ? ' (' . $cd_name . ')' : ' (not stored)', ', today ', $cd_today, "\n\n";

$cd_api = Container::getApi();
$cd_total = 0;
foreach (BookableCircuits::months($cd_today) as $cd_month) {
    $cd_dates = [];
    for ($cd_page = 1; $cd_page <= 20; $cd_page++) {
        $cd_response = $cd_api->getCircuitRates([
            'circuit_ids' => [$cd_circuit],
            'months' => [$cd_month],
            'pagination' => ['page' => $cd_page, 'per_page' => 100],
        ]);
        if ($cd_response === null) {
            echo $cd_month, ': the rates call failed — ', $cd_mask($cd_api->lastError()), "\n";
            break;
        }
        $cd_rates = TypeCoerce::toRowList($cd_response['results'] ?? []);
        foreach ($cd_rates as $cd_rate) {
            $cd_pricing = TypeCoerce::toStringMap($cd_rate['pricing'] ?? null);
            $cd_dates[] = sprintf(
                '%s%s %s %s%s',
                TypeCoerce::toString($cd_rate['departure_date'] ?? '(no date)'),
                TypeCoerce::toInt($cd_rate['circuit_id'] ?? 0) !== $cd_circuit ? ' [circuit ' . TypeCoerce::toInt($cd_rate['circuit_id'] ?? 0) . '!]' : '',
                number_format(TypeCoerce::toFloat($cd_pricing['selling_price'] ?? 0), 0, ',', '.'),
                TypeCoerce::toString($cd_pricing['currency'] ?? ''),
                TypeCoerce::toString($cd_rate['departure_date'] ?? '') < $cd_today ? ' (past)' : '',
            );
        }
        $cd_last = TypeCoerce::toInt(TypeCoerce::toStringMap($cd_response['meta'] ?? null)['last_page'] ?? 0);
        if ($cd_rates === [] || ($cd_last > 0 ? $cd_page >= $cd_last : count($cd_rates) < 100)) {
            break;
        }
    }
    $cd_total += count($cd_dates);
    echo $cd_month, ': ', $cd_dates === [] ? '-' : implode(' · ', $cd_dates), "\n";
}

echo "\n", $cd_total, " rates over 18 months. Read-only: nothing was written.\n";
