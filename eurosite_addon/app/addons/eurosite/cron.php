<?php

declare(strict_types=1);
/**
 * Eurosite Touring — CLI/URL cron entry point (sphinx shape).
 *
 * Usage:
 *   php cron.php access_key=YOUR_KEY mode=full
 *   php cron.php access_key=YOUR_KEY mode=cities country=RO
 *   URL: http://domain.com/index.php?dispatch=eurosite_cron.run&access_key=KEY&cron_mode=hotels
 *
 * Modes: see CronDispatcher::getAvailableModes() (countries, own_cities,
 * cities, hotels, room_types, tags, product_info, full, cleanup).
 */

if (!defined('AREA')) {
    define('AREA', 'A');
    define('CONSOLE', true);
}

require dirname(__FILE__) . '/../../../init.php';

use Tygh\Addons\Eurosite\Cron\CronDispatcher;
use Tygh\Addons\Eurosite\Services\ConfigProvider;
use Tygh\Addons\TravelCore\Cron\CronRunner;

// Cron is a first-class entry point for schema-dependent work — apply any
// pending schema deltas before dispatching (per-request guarded, additive).
if (function_exists('fn_eurosite_ensure_schema')) {
    fn_eurosite_ensure_schema();
}

[$accessKey, $mode, $params] = CronRunner::parseArgs();
CronRunner::authenticate(ConfigProvider::getCronAccessKey(), $accessKey, 'Eurosite Touring', 'eurosite');
$mode = CronRunner::sanitizeMode($mode);

$runner = new CronRunner('Eurosite', new CronDispatcher(), 'full');
$runner->run($mode, $params);
