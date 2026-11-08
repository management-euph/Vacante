<?php

/**
 * seo-slug-check.php — what CS-Cart's own fn_generate_name() makes of hotel
 * names with marked letters, next to the add-ons' fn_travel_core_slugify(),
 * and which letters its literal_converter table converts. Read-only.
 *
 * WHY: fn_generate_name() converts letters only through that table and drops
 * whatever is left outside a-z 0-9 - . ; the add-ons make the address whole
 * first. This shows whether the table alone would do (Romanian ș ț ă â î,
 * Turkish ö ç ş ğ ü ı, and spaces).
 *
 * WHERE: the fullstore sandbox only — docker/fullstore/docker-compose.yml
 * bind-mounts dev/ at /var/www/html/dev. Refuses non-localhost HTTP requests.
 *
 * USE:
 *   http://localhost:8080/dev/tools/seo-slug-check.php
 *   CLI: docker compose exec app php /var/www/html/dev/tools/seo-slug-check.php
 */

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

$sc_is_cli = PHP_SAPI === 'cli';

if (!$sc_is_cli) {
    $sc_host = strtolower((string) strtok((string) ($_SERVER['HTTP_HOST'] ?? ''), ':'));
    if (!in_array($sc_host, ['localhost', '127.0.0.1', '[::1]'], true)) {
        http_response_code(403);
        exit("seo-slug-check is a local sandbox tool - refusing non-localhost request\n");
    }
    header('Content-Type: text/plain; charset=utf-8');
}

$sc_docroot = dirname(__DIR__, 2);
if (!is_file($sc_docroot . '/init.php')) {
    exit("No CS-Cart init.php at {$sc_docroot} — this tool only works from the fullstore container.\n");
}

const AREA = 'A';
const ACCOUNT_TYPE = 'admin';
require $sc_docroot . '/init.php';

echo "seo-slug-check — CS-Cart's fn_generate_name() and the add-ons' slug\n\n";

if (!function_exists('fn_generate_name') || !function_exists('fn_travel_core_slugify')) {
    exit("fn_generate_name() or fn_travel_core_slugify() is missing: is Travel Core active and up to date?\n");
}

$sc_names = [
    'Gözde Butik Otel Belek',
    'Çalış Beach Hotel',
    'Ağaoğlu Işıklı Resort',
    'Şirin Otel',
    'Hotel Țărănesc Șinaia',    // ș ț with a comma below (correct Romanian)
    'Hotel Ţărănesc Şinaia',    // ş ţ with a cedilla (older Romanian texts)
    'Mărăști Îngust Âmbru',
    'Akra -V',
    "St. Nicholas' Hotel & Spa",
];

echo "== 1. Names → address ==\n";
foreach ($sc_names as $sc_name) {
    echo $sc_name, "\n";
    echo '  CS-Cart alone (fn_generate_name): ', TypeCoerce::toString(fn_generate_name($sc_name)), "\n";
    echo '  add-ons (fn_travel_core_slugify): ', fn_travel_core_slugify($sc_name), "\n";
}

echo "\n== 2. CS-Cart's literal_converter table (schema) ==\n";
$sc_table = TypeCoerce::toStringMap(fn_get_schema('literal_converter', 'schema'));
echo count($sc_table), " entries\n";
$sc_missing = [];
foreach ([' ', 'ă', 'â', 'î', 'ș', 'ț', 'ş', 'ţ', 'Ă', 'Â', 'Î', 'Ș', 'Ț', 'Ş', 'Ţ', 'ö', 'ç', 'ğ', 'ü', 'ı', 'İ', 'Ö', 'Ç', 'Ğ', 'Ü'] as $sc_letter) {
    $sc_shown = $sc_letter === ' ' ? '(space)' : $sc_letter;
    if (array_key_exists($sc_letter, $sc_table)) {
        echo '  ', $sc_shown, ' → "', TypeCoerce::toString($sc_table[$sc_letter]), "\"\n";
    } else {
        echo '  ', $sc_shown, " → MISSING (dropped from the address)\n";
        $sc_missing[] = $sc_shown;
    }
}

echo "\n", $sc_missing === []
    ? 'Every letter above is in the table: fn_generate_name() alone would do for them.'
    : 'Not in the table: ' . implode(' ', $sc_missing) . ' — CS-Cart alone drops them; the add-ons convert them first.',
    "\nRead-only: nothing was written.\n";
