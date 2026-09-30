<?php
declare(strict_types=1);
/**
 * Sphinx Booking Controller — Cache Deals AJAX Mode
 *
 * Returns the package-search block's route options (destinations, departures,
 * transport) as JSON — LOCAL data from the cron-synced sphinx_package_routes
 * table, no provider API call. The "Best Deals" block this mode also fed
 * (hotels, packages and circuits straight from the provider cache, with no
 * store product behind them) is gone: guests book hotels from their product
 * pages only.
 *
 * @package SphinxHolidays
 * @since   1.1.0
 */
if (!defined('BOOTSTRAP')) { exit('Access denied'); }

use Tygh\Addons\TravelCore\Helpers\RequestCoerce;

header('Content-Type: application/json; charset=utf-8');

try {
    if (RequestCoerce::string($_REQUEST, 'type') === 'package_routes') {
        $options = (new \Tygh\Addons\SphinxHolidays\Repository\PackageRouteRepository())->getRouteOptions();
        echo json_encode(['success' => true] + $options);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown type.']);
} catch (\Throwable $e) {
    fn_log_event('general', 'runtime', ['message' => 'Sphinx cache_deals error: ' . $e->getMessage()]);
    echo json_encode(['success' => false, 'message' => 'Unable to load the route options.']);
}

exit;
