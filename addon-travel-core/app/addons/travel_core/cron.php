<?php
declare(strict_types=1);
/**
 * Travel Core - Cron Entry Point
 *
 * Centralized cron for shared travel operations (exchange rates).
 * Replaces per-addon exchange rate crons to avoid duplicate BNR requests.
 *
 * Usage (CLI):  php cron.php access_key=YOUR_KEY mode=exchange_rates
 * Usage (HTTP): http://domain.com/app/addons/travel_core/cron.php?access_key=KEY&mode=exchange_rates
 *
 * @package TravelCore
 * @since   1.1.0
 */

if (!defined('AREA')) {
    define('AREA', 'A');
    define('CONSOLE', true);
}

require dirname(__FILE__) . '/../../../init.php';

use Tygh\Registry;
use Tygh\Addons\TravelCore\Cron\CronKeyService;
use Tygh\Addons\TravelCore\Cron\CronRunner;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

[$accessKey, $mode, $params] = CronRunner::parseArgs();
CronRunner::authenticate(
    CronKeyService::getFor('travel_core'),
    $accessKey,
    'Travel Core'
);
$mode = CronRunner::sanitizeMode($mode);

// --- Validate mode ---
$supported_modes = ['exchange_rates'];

if (empty($mode) || !in_array($mode, $supported_modes, true)) {
    echo "Travel Core Cron - Available modes:\n";
    echo "  exchange_rates - Update BNR exchange rates (shared across all addons)\n";
    exit(1);
}

echo "[" . date('Y-m-d H:i:s') . "] Travel Core Cron - Mode: {$mode}\n\n";

// --- Execute ---
if ($mode === 'exchange_rates') {
    $commission = TypeCoerce::toFloat(Registry::get('addons.travel_core.currency_risk_commission'));

    $result = fn_travel_core_update_exchange_rates($commission, true);

    if (!is_array($result)) {
        $result = ['success' => false, 'message' => 'No response from exchange rate service'];
    }

    echo fn_travel_core_format_exchange_rate_output($result) . "\n";

    $exitCode = TypeCoerce::toBool($result['success'] ?? false) ? 0 : 1;
    echo "\n[" . date('Y-m-d H:i:s') . "] Cron job completed.\n";
    exit($exitCode);
}
