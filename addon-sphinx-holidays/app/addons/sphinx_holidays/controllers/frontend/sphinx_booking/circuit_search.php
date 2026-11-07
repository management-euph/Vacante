<?php
declare(strict_types=1);
/**
 * Sphinx Booking Controller — Circuit Search Mode
 *
 * Fetches circuit rates from the Sphinx API with optional filters.
 * Unlike hotel search (polling), circuits use a simple rates endpoint
 * with server-side pagination.
 *
 * @package SphinxHolidays
 * @since   1.1.0
 */
if (!defined('BOOTSTRAP')) { exit('Access denied'); }

use Tygh\Tygh;
use Tygh\Addons\SphinxHolidays\Helpers\ResultPricing;
use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Helpers\RequestCoerce;

try {
    $api = Container::getApi();
    /** @var \Smarty $view */
    $view = Tygh::$app['view'];

    $destination_id = RequestCoerce::int($_REQUEST, 'destination_id');
    $transport_type = RequestCoerce::string($_REQUEST, 'transport_type');
    $month = RequestCoerce::string($_REQUEST, 'month');
    $page = max(1, RequestCoerce::int($_REQUEST, 'page', 1));

    $searchParams = [
        'pagination' => ['page' => $page, 'per_page' => 20],
    ];

    if ($destination_id > 0) {
        $searchParams['destinatons'] = [$destination_id]; // API typo is intentional — matches spec
    }
    if (!empty($transport_type)) {
        $searchParams['transport_types'] = [$transport_type];
    }
    if (!empty($month)) {
        $searchParams['months'] = [$month];
    }

    $response = $api->getCircuitRates($searchParams);

    // Canonical decoded shape: the list is always under `results` (meta preserved).
    $allResults = TypeCoerce::toRowList($response['results'] ?? []);
    $meta = TypeCoerce::toStringMap($response['meta'] ?? null);

    // The store's prices, as the hotel search shows them: the price and the
    // struck "before" price both with commission, never the supplier's own
    // figures (ResultPricing).
    $withCommission = Container::getCartService()->applyCommission(...);
    foreach ($allResults as &$result) {
        $result['pricing'] = ResultPricing::forShopper(TypeCoerce::toStringMap($result['pricing'] ?? null), $withCommission);
    }
    unset($result);

    $view->assign('sphinx_circuit_results', $allResults);
    $view->assign('sphinx_circuit_meta', $meta);
    $view->assign('sphinx_circuit_params', [
        'destination_id' => $destination_id,
        'transport_type' => $transport_type,
        'month' => $month,
        'page' => $page,
        'currency' => ConfigProvider::getDefaultCurrency(),
    ]);

} catch (\Throwable $e) {
    fn_log_event('general', 'runtime', [
        'message' => 'Sphinx Circuit Search Error: ' . $e->getMessage(),
        'file'    => $e->getFile() . ':' . $e->getLine(),
    ]);
    fn_set_notification('E', __('error'),
        __('sphinx_holidays.search_error', ['[default]' => 'An error occurred while searching. Please try again later.']));
    /** @var \Smarty $errorView */
    $errorView = Tygh::$app['view'];
    $errorView->assign('sphinx_circuit_results', []);
}
