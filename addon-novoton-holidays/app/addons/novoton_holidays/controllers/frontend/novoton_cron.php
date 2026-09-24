<?php
declare(strict_types=1);
/**
 * Novoton Holidays - Public Cron Controller
 *
 * Access via: index.php?dispatch=novoton_cron.run&access_key=YOUR_KEY&mode=resinfo
 *
 * All modes are handled by Command classes in Cron/Commands/.
 * See CronDispatcher::getAvailableModes() for the full list.
 */

use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Addons\NovotonHolidays\Helpers\SyncLogger;
use Tygh\Addons\NovotonHolidays\Helpers\CronHelper;
use Tygh\Addons\NovotonHolidays\Cron\CronDispatcher;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Helpers\RequestCoerce;

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

// Authentication
$provided_access_key = RequestCoerce::string($_REQUEST, 'access_key');
if (!CronHelper::validateAccessKey($provided_access_key)) {
    $storedKey = ConfigProvider::getCronAccessKey();
    if (empty($storedKey)) {
        CronHelper::sendAuthError('Cron Access Key not configured in addon settings.');
    } else {
        // Noted for Travel Core -> Tools: a WRONG key here is what a crontab
        // still on an old key sends after a rotation.
        if (class_exists(\Tygh\Addons\TravelCore\Cron\CronRunLog::class)) {
            \Tygh\Addons\TravelCore\Cron\CronRunLog::refused('novoton_holidays', $provided_access_key !== '');
        }
        CronHelper::sendAuthError('Invalid or missing API key.');
    }
}

// ── Ensure SEO template defaults are available in this (frontend) request ──
// The init.php self-heal probe only fires in the admin area (AREA 'A'); the
// cron runs in the storefront area (AREA 'C'), where addons.novoton_holidays.seo_*
// keys are absent from the Registry. Without them, product creation renders
// blank Page title / Meta description / Meta keywords. Seed them here (in-request)
// so add_hotels_as_products and other modes have the templates available.
if (function_exists('fn_novoton_holidays_seed_seo_defaults')) {
    fn_novoton_holidays_seed_seo_defaults();
}

$mode = TypeCoerce::toString(preg_replace('/[^a-zA-Z0-9_]/', '', RequestCoerce::string($_REQUEST, 'mode', 'resinfo')));

header('Content-Type: text/plain; charset=utf-8');

// Initialize logger
$logger = new SyncLogger($mode);
$logger->outputHeader($mode);

try {
    $api = _nvt_api();
    $dispatcher = new CronDispatcher($api, $logger);

    if (!$dispatcher->hasMode($mode)) {
        $logger->output("Unknown mode: {$mode}");
        $logger->output("");
        $logger->output("Available modes:");
        foreach (CronDispatcher::getAvailableModes() as $m => $desc) {
            $logger->output("  {$m} - {$desc}");
        }
    } else {
        $result = $dispatcher->dispatch($mode, TypeCoerce::toStringMap($_REQUEST));
        $logger->complete(TypeCoerce::toBool($result['success'] ?? true));
    }

} catch (\Exception $e) {
    $logger->output("ERROR: " . $e->getMessage());
    $logger->logEvent('cron_error', ['error' => $e->getMessage()]);
}

$logger->outputFooter();
exit;
