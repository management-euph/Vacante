<?php
declare(strict_types=1);
/**
 * Novoton Cron Job Handler (Legacy Entry Point)
 *
 * Usage: php cron.php access_key=YOUR_KEY mode=resinfo
 * Or:    http://domain.com/app/addons/novoton_holidays/cron.php?access_key=KEY&mode=resinfo
 *
 * Recommended: use index.php?dispatch=novoton_cron.run&access_key=KEY&mode=... instead.
 */

if (!defined('AREA')) {
    define('AREA', 'A');
    define('CONSOLE', true);
}

require dirname(__FILE__) . '/../../../init.php';

use Tygh\Addons\NovotonHolidays\Cron\CronDispatcher;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Addons\TravelCore\Cron\CronRunner;

[$accessKey, $mode, $params] = CronRunner::parseArgs();
CronRunner::authenticate(ConfigProvider::getCronAccessKey(), $accessKey, 'Novoton Holidays', 'novoton_holidays');
$mode = CronRunner::sanitizeMode($mode);

$api = _nvt_api();
$dispatcher = new CronDispatcher($api, null);

$onError = function (\Exception $e): void {
    $companyData = fn_get_company_data(0);
    $adminEmail = $companyData['company_users_department'] ?? '';
    if (!empty($adminEmail)) {
        fn_novoton_holidays_send_import_report_email([], 'cron_error', [
            'error' => $e->getMessage(),
            'time' => date('Y-m-d H:i:s'),
        ], 'Cron error notification');
    }
};

$runner = new CronRunner('Novoton', $dispatcher, 'full', $onError);
$runner->run($mode, $params);
