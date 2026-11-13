<?php
declare(strict_types=1);
/**
 * Travel Core - Feature Mappings › Circuits (and the Packages placeholder)
 *
 * The Circuits tab of the Feature Mappings page: which CS-Cart feature each
 * circuit fact is written to (CircuitFeatureConfig), and "Apply to all
 * circuits". Its own controller so each tab has its own URL; the Hotels tab
 * is travel_feature_mappings.
 *
 * @package TravelCore
 */

use Tygh\Tygh;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\CircuitFeatureConfig;
use Tygh\Addons\TravelCore\Services\CircuitFeatureMappingsPage;
use Tygh\Addons\TravelCore\Services\TravelProviderRegistry;

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

if (fn_allowed_for('MULTIVENDOR') || (defined('RESTRICTED_ADMIN') && RESTRICTED_ADMIN)) {
    return [CONTROLLER_STATUS_DENIED];
}

/** @var \Smarty $view */
$view = Tygh::$app['view'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Save which feature each circuit fact goes to
    if ($mode === 'update') {
        CircuitFeatureConfig::save(is_array($_REQUEST['circuit_features'] ?? null) ? $_REQUEST['circuit_features'] : []);
        fn_set_notification('N', __('notice'), __('travel_core.fmc_saved'));
    }

    // Write the mapped features to every circuit product now
    if ($mode === 'apply') {
        if (CircuitFeatureConfig::isEmpty(CircuitFeatureConfig::load())) {
            fn_set_notification('W', __('warning'), __('travel_core.fmc_nothing_mapped'));
        } else {
            $result = TravelProviderRegistry::applyCircuitFeatures();
            fn_set_notification('N', __('notice'), __('travel_core.fmc_applied', ['[count]' => $result['written']]));
            if ($result['notes'] !== []) {
                // Why some mapped features were not written: one line each, never silent.
                fn_set_notification('W', __('warning'), TypeCoerce::toString(__('travel_core.fmc_not_written')) . ': ' . implode('; ', $result['notes']));
            }
        }
    }

    return [CONTROLLER_STATUS_REDIRECT, 'travel_circuit_features.manage'];
}

// ── GET: Circuits tab — one feature per circuit fact ──
if ($mode === 'manage') {
    $lang = TypeCoerce::toString(DESCR_SL);
    $config = CircuitFeatureConfig::load();

    $view->assign('fm_tab', 'circuits');
    $view->assign('circuit_config', $config);
    $view->assign('circuit_fields', CircuitFeatureConfig::FIELDS);
    $view->assign('circuit_transports', CircuitFeatureConfig::TRANSPORTS);
    $view->assign('circuit_groups', CircuitFeatureMappingsPage::groups($lang));
    $view->assign('circuit_feature_list', CircuitFeatureMappingsPage::features($config['group_id'], $lang));
    $view->assign('transport_variants', CircuitFeatureMappingsPage::variants($config['features']['transport'], $lang));
    $view->assign('month_variants', CircuitFeatureMappingsPage::variants($config['features']['month'], $lang));
}

// ── GET: Packages tab — nothing to map yet ──
if ($mode === 'packages') {
    $view->assign('fm_tab', 'packages');
}
