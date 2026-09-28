<?php
declare(strict_types=1);
/**
 * Travel Core - Cron Controller (frontend dispatch)
 *
 * Provides URL: index.php?dispatch=travel_cron.run&access_key=KEY&mode=exchange_rates
 *
 * This is the CS-Cart dispatch controller. For CLI usage, see cron.php.
 *
 * @package TravelCore
 * @since   1.1.0
 */

use Tygh\Addons\TravelCore\Cron\CronKeyService;
use Tygh\Addons\TravelCore\Cron\CronRunLog;
use Tygh\Addons\TravelCore\Cron\CronRunner;
use Tygh\Addons\TravelCore\Helpers\RequestCoerce;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Registry;

if (!defined('BOOTSTRAP')) { exit('Access denied'); }

// --- Authenticate ---
//
// Through the shared CronRunner rather than a hand-rolled check. The version
// this replaces ended both failures in a bare die(), which sends HTTP 200 with
// no Content-Type — so this endpoint reported healthy to any monitoring while
// refusing every request. The other three cron controllers already answer 403
// and exit; this one was the odd one out.
$storedKey = CronKeyService::getFor('travel_core');
$providedKey = RequestCoerce::string($_REQUEST, 'access_key');

CronRunner::authenticate($storedKey, $providedKey, 'Travel Core', 'travel_core');

if ($mode === 'run') {
    $cron_mode = isset($_REQUEST['mode']) ? preg_replace('/[^a-z0-9_]/', '', strtolower(RequestCoerce::string($_REQUEST, 'mode'))) : '';

    // Fix: 'mode' param was consumed by CS-Cart dispatch, use 'cron_mode' as fallback
    if (empty($cron_mode) || $cron_mode === 'run') {
        $cron_mode = isset($_REQUEST['cron_mode']) ? preg_replace('/[^a-z0-9_]/', '', strtolower(RequestCoerce::string($_REQUEST, 'cron_mode'))) : '';
    }

    $supported_modes = ['exchange_rates', 'expire_alternative_requests', 'balances'];

    if (empty($cron_mode) || !in_array($cron_mode, $supported_modes, true)) {
        header('Content-Type: text/plain');
        echo "Travel Core Cron - Available modes:\n";
        echo "  exchange_rates              - Update BNR exchange rates\n";
        echo "  expire_alternative_requests - Expire stale availability requests (&days=30) and purge old terminal rows (&purge_days=180)\n";
        echo "  balances                    - Deposit bookings: balance reminders (7 and 2 days before) and overdue alerts (daily)\n\n";
        echo "Usage: dispatch=travel_cron.run&access_key=KEY&cron_mode=exchange_rates\n";
        exit;
    }

    header('Content-Type: text/plain');
    echo "[" . date('Y-m-d H:i:s') . "] Travel Core Cron - Mode: {$cron_mode}\n\n";

    if ($cron_mode === 'balances') {
        $r = CronRunLog::record('travel_core', 'balances', static fn (): array => ['success' => true]
            + (new \Tygh\Addons\TravelCore\Services\BalanceReminder())->run(date('Y-m-d')));
        echo 'Balance reminders sent: ' . $r['reminded'] . ', overdue alerts: ' . $r['overdue'] . "\n";
    } elseif ($cron_mode === 'expire_alternative_requests') {
        // Lifecycle for the shared travel_alternative_requests table.
        // Expiry targets ONLY sphinx rows — they are internal-manual with no
        // provider workflow; novoton's own expire_requests cron propagates
        // its transitions into this table itself. The purge (PII hygiene)
        // deletes terminal expired/cancelled rows for all providers.
        $days = max(1, TypeCoerce::toInt($_REQUEST['days'] ?? 30));
        $purgeDays = max(1, TypeCoerce::toInt($_REQUEST['purge_days'] ?? 180));

        $repo = new \Tygh\Addons\TravelCore\Repository\AlternativeRequestRepository();
        // Recorded for Travel Core -> Tools, like every provider job.
        $counts = CronRunLog::record('travel_core', 'expire_alternative_requests', static fn (): array => [
            'success' => true,
            'expired' => $repo->expireOlderThan($days, 'sphinx'),
            'purged' => $repo->purgeOlderThan($purgeDays),
        ]);
        $expired = TypeCoerce::toInt($counts['expired']);
        $purged = TypeCoerce::toInt($counts['purged']);

        echo "Expired sphinx requests older than {$days} days: {$expired}\n";
        echo "Purged expired/cancelled rows older than {$purgeDays} days: {$purged}\n";
    } else {
        $commission = TypeCoerce::toFloat(Registry::get('addons.travel_core.currency_risk_commission'));

        // Recorded for Travel Core -> Tools, like every provider job.
        $result = CronRunLog::record('travel_core', 'exchange_rates', static function () use ($commission): array {
            $r = fn_travel_core_update_exchange_rates($commission, true);

            return is_array($r) ? $r : ['success' => false, 'message' => 'No response from exchange rate service'];
        });

        echo fn_travel_core_format_exchange_rate_output($result) . "\n";
    }

    echo "\n[" . date('Y-m-d H:i:s') . "] Cron job completed.\n";

    exit;
}
