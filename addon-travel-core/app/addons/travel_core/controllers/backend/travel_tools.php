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
use Tygh\Addons\TravelCore\Cron\CronHealth;
use Tygh\Addons\TravelCore\Cron\CronKeyChangeTracker;
use Tygh\Addons\TravelCore\Cron\CronKeyService;
use Tygh\Addons\TravelCore\Cron\CronOverview;
use Tygh\Addons\TravelCore\Cron\CronRunLog;
use Tygh\Addons\TravelCore\Helpers\RequestCoerce;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\TravelProviderRegistry;
use Tygh\Addons\TravelCore\Repository\OrderLinkCandidateRepository;

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if ($mode === 'run_exchange_rates') {
        $commission = TypeCoerce::toFloat(Registry::get('addons.travel_core.currency_risk_commission'));

        // Recorded like a scheduled run, but marked as an admin one: health
        // ignores those, so pressing Run now cannot make a broken crontab
        // look healthy.
        $result = CronRunLog::record(
            'travel_core',
            CronOverview::CORE_JOB,
            static function () use ($commission): array {
                $r = fn_travel_core_update_exchange_rates($commission, true);

                return is_array($r) ? $r : ['success' => false, 'message' => 'No response from exchange rate service'];
            },
        );

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

    if ($mode === 'generate_cron_key') {
        // The only place a cron key can be MINTED. The settings field stays
        // editable — an operator migrating a store may need to paste a
        // specific value — but typing a secret by hand is how this store ended
        // up with `1234` on three add-ons and nothing on the fourth, so the
        // page that shows the cron commands also offers a real one.
        $fresh = CronKeyService::generate();

        if ($fresh === '') {
            fn_set_notification('E', __('error'), TypeCoerce::toString(__('travel_core.tools_cron_key_failed')));
        } else {
            // 'W', not 'N': every existing crontab entry just stopped
            // authenticating. That is the point of rotating, and it is also
            // the thing that silently breaks scheduled work if unnoticed.
            fn_set_notification('W', __('warning'), TypeCoerce::toString(__('travel_core.tools_cron_key_rotated')));
        }

        // The key is NOT put in the notification. The page redirected to
        // prints it, and the cron URLs built from it, in full — a notification
        // is stored per-user and outlives the page that needs it.
        return [CONTROLLER_STATUS_REDIRECT, 'travel_tools.manage'];
    }

    if ($mode === 'cron_recopy_done') {
        // One page of the re-copy checklist ticked by hand. markDone() ignores
        // a page the checklist does not list, so a forged value adds nothing.
        CronKeyChangeTracker::markDone(RequestCoerce::string($_REQUEST, 'page'), time());

        return [CONTROLLER_STATUS_REDIRECT, 'travel_tools.manage'];
    }

    if ($mode === 'cron_recopy_dismiss') {
        CronKeyChangeTracker::dismiss();

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
    // Whether the key above is Core's own or the legacy per-add-on fallback.
    // The page says which, because on the fallback the consolidation has not
    // completed and the other providers may still be authenticating against
    // keys of their own — i.e. the state this whole move exists to end.
    $cron_key_is_shared = CronKeyService::isConfigured();
    $base_url = TypeCoerce::toString(Registry::get('config.http_location')) . '/';
    $now = time();
    $mask = '••••••••••••';
    // Numeric, so it reads the same in the English and Romanian admin.
    $fmt = static fn (int $ts): string => $ts > 0 ? date('d.m.Y H:i', $ts) : '';

    // ── Travel Core's own job ──
    // The one scheduled job Travel Core itself runs. Its command is shown
    // with the key MASKED; tools-cron.js reveals it on request and always
    // copies the real value (a masked command pasted into a crontab is a 403).
    $commands = CronOverview::coreCommands($cron_key, $base_url, defined('DIR_ROOT') ? TypeCoerce::toString(constant('DIR_ROOT')) : '');
    $core_job = [
        'mode'         => CronOverview::CORE_JOB,
        'cmd_url'      => $commands['url'],
        'cmd_cli'      => $commands['cli'],
        'cmd_masked'   => $cron_key === '' ? $commands['url'] : str_replace($cron_key, $mask, $commands['url']),
        'cpanel'       => '5 13 * * *',
        'run_action'   => 'run_exchange_rates',
        'record'       => CronOverview::coreJobRecord(),
    ];
    $core_job['started_fmt'] = $fmt($core_job['record']['started'] ?? 0);
    $core_job['finished_fmt'] = $fmt($core_job['record']['finished'] ?? 0);
    $core_health = CronOverview::coreHealth($now);
    $core_health_lists = CronHealth::lists($core_health);

    // ── Each provider's row, as the provider itself declared it ──
    $provider_rows = [];
    foreach (CronOverview::providerRows(TravelProviderRegistry::getCronProviders(), $now) as $row) {
        $row['url'] = TypeCoerce::toString(fn_url($row['dashboard'])) . ($row['anchor'] === '' ? '' : '#' . $row['anchor']);
        $row['last_at_fmt'] = $fmt($row['health']['last']['at'] ?? 0);
        $row['lists'] = CronHealth::lists($row['health']);
        $provider_rows[] = $row;
    }

    $used_by = array_merge(['Travel Core'], array_column($provider_rows, 'label'));
    $jobs_total = array_sum(array_column($provider_rows, 'jobs'));
    $attention = array_values(array_filter(
        array_merge([['label' => 'Travel Core', 'health' => $core_health]], $provider_rows),
        /** @param array{health: array{state: string}} $r */
        static fn (array $r): bool => CronHealth::needsAttention($r['health']['state']),
    ));

    // ── The re-copy checklist, opened by ANY change of the key ──
    $pages = array_merge(['travel_core'], array_column($provider_rows, 'addon'));
    $key_state = CronKeyChangeTracker::observe($cron_key, $pages, $now);
    $last_ok = ['travel_core' => $core_health['last_scheduled_ok']];
    $page_meta = ['travel_core' => ['label' => 'Travel Core', 'url' => '#travel-core-jobs']];
    foreach ($provider_rows as $row) {
        $last_ok[$row['addon']] = $row['health']['last_scheduled_ok'];
        $page_meta[$row['addon']] = ['label' => $row['label'], 'url' => $row['url']];
    }
    $recopy = [];
    foreach (CronKeyChangeTracker::checklist($key_state, $last_ok) as $entry) {
        if (!isset($page_meta[$entry['page']])) {
            continue; // a provider disabled since the change: nothing to re-copy there now
        }
        $recopy[] = $entry + $page_meta[$entry['page']];
    }
    $recopy_open = CronKeyChangeTracker::isOpen($key_state, $recopy);

    $view = Tygh::$app['view'];
    if (is_object($view) && method_exists($view, 'assign')) {
        $view->assign('cron_key', $cron_key);
        $view->assign('cron_key_is_shared', $cron_key_is_shared);
        $view->assign('cron_key_mask', $mask);
        $view->assign('cron_key_changed_at', $fmt($key_state['at']));
        $view->assign('base_url', $base_url);
        $view->assign('core_job', $core_job);
        $view->assign('core_health', $core_health);
        $view->assign('core_health_lists', $core_health_lists);
        $view->assign('provider_rows', $provider_rows);
        $view->assign('cron_used_by', $used_by);
        $view->assign('cron_jobs_total', $jobs_total);
        $view->assign('cron_attention', $attention);
        $view->assign('cron_recopy', $recopy);
        $view->assign('cron_recopy_open', $recopy_open);
        $view->assign('cron_recopy_done', count(array_filter(array_column($recopy, 'done'))));
    }
}
