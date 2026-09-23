<?php
declare(strict_types=1);
/**
 * Travel Core - Tools & Cron Backend Controller
 *
 * Provides admin interface for managing cron jobs and running
 * maintenance tasks like BNR exchange rate updates.
 *
 * @package TravelCore
 * @since   1.1.0
 */

use Tygh\Registry;
use Tygh\Tygh;
use Tygh\Addons\TravelCore\Cron\CronKeyService;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Repository\OrderLinkCandidateRepository;

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if ($mode === 'run_exchange_rates') {
        $commission = TypeCoerce::toFloat(Registry::get('addons.travel_core.currency_risk_commission'));

        $result = fn_travel_core_update_exchange_rates($commission, true);

        if (!is_array($result)) {
            $result = ['success' => false, 'message' => 'No response from exchange rate service'];
        }

        if (!empty($result['success'])) {
            $parts = [];
            if (!empty($result['publishing_date'])) {
                $parts[] = 'BNR date: ' . TypeCoerce::toString($result['publishing_date']);
            }
            if (!empty($result['coefficients'])) {
                foreach (TypeCoerce::toStringMap($result['coefficients']) as $cur => $coeff) {
                    $coeffStr = TypeCoerce::toString($coeff);
                    $parts[] = "{$cur}: {$coeffStr}";
                }
            }
            $detail = !empty($parts) ? ' (' . implode(', ', $parts) . ')' : '';
            fn_set_notification('N', __('notice'), TypeCoerce::toString(__('travel_core.exchange_rates_updated')) . $detail);
        } else {
            fn_set_notification('E', __('error'), TypeCoerce::toString(__('travel_core.exchange_rates_failed')) . ': ' . TypeCoerce::toString($result['message'] ?? 'Unknown error'));
        }

        return [CONTROLLER_STATUS_REDIRECT, 'travel_tools.manage'];
    }

    if ($mode === 'link_booking_orders') {
        // Reconcile booking–order links: providers stamp order_id on their
        // booking rows during place_order_post, but a booking placed while a
        // submission bug (or older code) was live stays orphaned forever —
        // the Travel Bookings grid then shows "Order ID: -" although the
        // CS-Cart order exists. Walk the newest orders that actually contain
        // provider-booking items (OrderLinkCandidateRepository pre-filters —
        // each hook replay costs a full fn_get_order_info() per provider) and
        // fire the reconcilers (idempotent: linked bookings are untouched).
        $orderIds = (new OrderLinkCandidateRepository())->findSweepCandidates(500);

        $linked = 0;
        foreach ($orderIds as $reconcile_order_id) {
            fn_set_hook('travel_link_order_bookings', $reconcile_order_id, $linked);
        }

        fn_set_notification(
            'N',
            __('notice'),
            str_replace(
                ['[linked]', '[orders]'],
                [(string) $linked, (string) count($orderIds)],
                TypeCoerce::toString(__('travel_core.booking_orders_linked')),
            ),
        );

        return [CONTROLLER_STATUS_REDIRECT, 'travel_tools.manage'];
    }
}

if ($mode === 'manage') {
    $cron_key = CronKeyService::getFor('travel_core');
    $base_url = TypeCoerce::toString(Registry::get('config.http_location')) . '/';

    $cron_jobs = [];

    $cron_jobs['exchange_rates'] = [
        'name'        => __('travel_core.cron_exchange_rates'),
        'description' => __('travel_core.cron_exchange_rates_desc'),
        'url'         => !empty($cron_key)
            ? $base_url . "index.php?dispatch=travel_cron.run&access_key={$cron_key}&cron_mode=exchange_rates"
            : '',
        'schedule'    => __('travel_core.cron_schedule_daily'),
        'cpanel'      => '5 13 * * *',
        'run_action'  => 'run_exchange_rates',
    ];

    // On-demand maintenance (no external cron URL): backfills order links for
    // bookings orphaned by historical submission bugs. Idempotent.
    $cron_jobs['link_booking_orders'] = [
        'name'        => __('travel_core.link_booking_orders'),
        'description' => __('travel_core.link_booking_orders_desc'),
        'url'         => '',
        'schedule'    => '—',
        'cpanel'      => '—',
        'run_action'  => 'link_booking_orders',
    ];

    $view = Tygh::$app['view'];
    if (is_object($view) && method_exists($view, 'assign')) {
        $view->assign('cron_jobs', $cron_jobs);
        $view->assign('cron_key', $cron_key);
        $view->assign('base_url', $base_url);
    }
}
