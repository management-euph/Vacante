<?php

declare(strict_types=1);
/**
 * Eurosite Touring — public cron controller.
 *
 * Accessible via URL for external cron services (server crontab, cPanel,
 * cron-job.org). Authentication via access_key parameter — no admin login.
 *
 * Note: uses 'cron_mode' (not 'mode') because CS-Cart reserves 'mode' for
 * the dispatch system.
 *
 * URLs:
 *   index.php?dispatch=eurosite_cron.run&access_key=KEY&cron_mode=full
 *   index.php?dispatch=eurosite_cron.run&access_key=KEY&cron_mode=countries
 *   index.php?dispatch=eurosite_cron.run&access_key=KEY&cron_mode=own_cities
 *   index.php?dispatch=eurosite_cron.run&access_key=KEY&cron_mode=cities[&country=RO]
 *   index.php?dispatch=eurosite_cron.run&access_key=KEY&cron_mode=hotels[&city=ROMM]
 *   index.php?dispatch=eurosite_cron.run&access_key=KEY&cron_mode=room_types
 *   index.php?dispatch=eurosite_cron.run&access_key=KEY&cron_mode=tags
 *   index.php?dispatch=eurosite_cron.run&access_key=KEY&cron_mode=product_info[&limit=100][&full=1]
 *   index.php?dispatch=eurosite_cron.run&access_key=KEY&cron_mode=availability[&city=ROMM]
 *   index.php?dispatch=eurosite_cron.run&access_key=KEY&cron_mode=add_products[&city=ROMM][&limit=100][&dry_run=1]
 *   index.php?dispatch=eurosite_cron.run&access_key=KEY&cron_mode=update_products[&city=ROMM][&limit=200]
 *   index.php?dispatch=eurosite_cron.run&access_key=KEY&cron_mode=cleanup
 */

use Tygh\Addons\Eurosite\Cron\CronDispatcher;
use Tygh\Addons\Eurosite\Services\ConfigProvider;
use Tygh\Addons\TravelCore\Cron\CronRunOutput;
use Tygh\Addons\TravelCore\Helpers\RequestCoerce;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

if (!defined('BOOTSTRAP')) {
    exit('Access denied');
}

// ── Authentication ──

$providedKey = RequestCoerce::string($_REQUEST, 'access_key');
$storedKey = ConfigProvider::getCronAccessKey();

header('Content-Type: text/plain; charset=utf-8');

if (empty($storedKey)) {
    http_response_code(403);
    echo "ERROR: Cron Access Key not configured in Eurosite Touring addon settings.\n";
    exit;
}

if (empty($providedKey) || !hash_equals($storedKey, $providedKey)) {
    // Noted for Travel Core -> Tools: a WRONG key here is what a crontab
    // still on an old key sends after a rotation.
    if (class_exists(\Tygh\Addons\TravelCore\Cron\CronRunLog::class)) {
        \Tygh\Addons\TravelCore\Cron\CronRunLog::refused('eurosite', !empty($providedKey));
    }
    http_response_code(403);
    echo "ERROR: Invalid or missing access key.\n";
    exit;
}

// ── Ensure schema deltas are applied in this (frontend) request ──
// init.php only self-heals when AREA === 'A'; the cron runs in the
// storefront area 'C' and is a first-class entry point for schema-dependent
// work, so it applies the deltas itself (per-request guarded, additive).
if (function_exists('fn_eurosite_ensure_schema')) {
    fn_eurosite_ensure_schema();
}

// A scheduled run: one request at a time and spaced, as Eurosite expects
// (Api\RequestPacer). A run started from the dashboard is not.
\Tygh\Addons\Eurosite\Api\RequestPacer::start();

// ── Parse mode + dispatch ──

$mode = (string) preg_replace('/[^a-z0-9_]/', '', strtolower(RequestCoerce::string($_REQUEST, 'cron_mode', 'full')));

echo CronRunOutput::startLine('Eurosite', $mode);

fn_log_event('general', 'runtime', [
    'message' => "Eurosite frontend cron started (mode: {$mode})",
]);

try {
    $dispatcher = new CronDispatcher();

    if (!$dispatcher->hasMode($mode)) {
        echo "Unknown mode: {$mode}\n\nAvailable modes:\n";
        foreach (CronDispatcher::getAvailableModes() as $m => $desc) {
            echo "  {$m} - {$desc}\n";
        }
    } else {
        // How it ended, its error and its figures, as the dashboard's Run prints it.
        CronRunOutput::dispatch($dispatcher, $mode, TypeCoerce::toStringMap($_REQUEST));
    }
} catch (\Throwable $e) {
    echo 'ERROR: ' . $e->getMessage() . "\n";
    fn_log_event('general', 'runtime', [
        'message' => 'Eurosite frontend cron error: ' . $e->getMessage(),
    ]);
}

exit;
