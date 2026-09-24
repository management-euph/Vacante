<?php
declare(strict_types=1);
/**
 * Sphinx Holidays Cron Job Handler
 *
 * Usage:
 *   php cron.php access_key=YOUR_KEY mode=destinations
 *   curl "http://domain.com/app/addons/sphinx_holidays/cron.php?access_key=KEY&mode=destinations"
 */

if (!defined('AREA')) {
    define('AREA', 'A');
    define('CONSOLE', true);
}

require dirname(__FILE__) . '/../../../init.php';

use Tygh\Addons\SphinxHolidays\Cron\CronDispatcher;
use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;
use Tygh\Addons\TravelCore\Cron\CronRunner;

[$accessKey, $mode, $params] = CronRunner::parseArgs();
CronRunner::authenticate(ConfigProvider::getCronAccessKey(), $accessKey, 'Sphinx Holidays', 'sphinx_holidays');
$mode = CronRunner::sanitizeMode($mode);

$runner = new CronRunner('Sphinx', new CronDispatcher(), 'destinations');
$runner->run($mode, $params);
