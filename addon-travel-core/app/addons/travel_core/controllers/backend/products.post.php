<?php

declare(strict_types=1);

/**
 * Travel Core — the admin product page's read-only "Circuit" tab
 * (docs/circuit-page-plan.md step 5): for a product a supplier claims as a
 * circuit, what its sync holds (CircuitAdminView), drawn by
 * hooks/products/tabs_extra.post.tpl. A product that is no circuit gets no tab.
 */

use Tygh\Addons\TravelCore\Helpers\RequestCoerce;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Repository\CircuitDepartureRepository;
use Tygh\Addons\TravelCore\Services\TravelProviderRegistry;
use Tygh\Addons\TravelCore\ViewModels\CircuitAdminView;
use Tygh\Registry;

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

if ($mode === 'update' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $productId = RequestCoerce::int($_REQUEST, 'product_id');
    $productCode = $productId > 0
        ? TypeCoerce::toString(db_get_field('SELECT product_code FROM ?:products WHERE product_id = ?i', $productId))
        : '';
    if ($productCode !== '') {
        try {
            $circuit = TravelProviderRegistry::resolveCircuit($productId, $productCode);
            if ($circuit !== null) {
                $circuitAdmin = (new CircuitAdminView())->build(
                    $circuit,
                    TravelProviderRegistry::circuitDetails($circuit),
                    (new CircuitDepartureRepository())->forCircuit($circuit->providerName, $circuit->circuitId, date('Y-m-d')),
                );
                Registry::set('navigation.tabs.travel_circuit', [
                    'title' => __('travel_core.circuit_admin_tab'),
                    'js' => true,
                ]);
                /** @var \Smarty $view */
                $view = Tygh::$app['view'];
                $view->assign('travel_circuit_admin', $circuitAdmin);
            }
        } catch (\Throwable $e) {
            // Never the product page's undoing: it shows without the tab.
            fn_log_event('general', 'runtime', ['message' => 'Circuit admin tab failed: ' . $e->getMessage()]);
        }
    }
}
