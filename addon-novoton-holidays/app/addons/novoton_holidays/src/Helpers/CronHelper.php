<?php

declare(strict_types=1);

/**
 * Novoton Holidays - Cron Helper
 *
 * Consolidates common cron operations:
 * - Authentication
 * - Header/footer output
 * - API initialization
 * - Product creation from hotel data
 *
 * Injectable: Use CronHelper::getInstance() or inject via constructor.
 * Testable: Use CronHelper::setInstance($mockHelper) in tests.
 *
 * @package NovotonHolidays
 * @since 3.1.0
 */

namespace Tygh\Addons\NovotonHolidays\Helpers;

use Tygh\Addons\NovotonHolidays\NovotonApi;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

class CronHelper
{
    /**
     * Singleton instance (replaceable for testing)
     */
    private static ?self $instance = null;

    /**
     * Get the singleton instance.
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Replace the singleton instance (for testing / DI).
     */
    public static function setInstance(?self $instance): void
    {
        self::$instance = $instance;
    }

    /**
     * Validate cron access key
     *
     * @param string $providedKey Key from request
     */
    public static function validateAccessKey(string $providedKey): bool
    {
        $storedKey = ConfigProvider::getCronAccessKey();

        if (empty($storedKey)) {
            return false;
        }

        return !empty($providedKey) && hash_equals($storedKey, $providedKey);
    }

    /**
     * Refuse a cron request: 403, plain text, non-zero exit, and a log line.
     *
     * Three things were wrong here, and the middle one was the dangerous one.
     *
     * IT RETURNED. Under BOOTSTRAP this set 403, then fn_redirect()'d to the
     * storefront home, then `return`ed — and the caller (novoton_cron.php) has
     * no exit after it, so termination depended entirely on fn_redirect()
     * exiting. If it ever did not, execution fell through to the dispatcher
     * UNAUTHENTICATED. It is now `never`, so the caller cannot get that wrong.
     *
     * IT REDIRECTED. A cron URL has no human at the other end to send to the
     * shop homepage, and the flash notification just accumulates in a session
     * nobody reads. A refused machine request should say so in its body.
     *
     * IT THREW ON CLI. The docblock claimed "exits with code 1"; the code threw
     * a RuntimeException, so the exit status depended on whoever caught it.
     *
     * @param string $message Error message
     */
    public static function sendAuthError(string $message): never
    {
        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
        }

        if (function_exists('fn_log_event')) {
            $from = TypeCoerce::toString($_SERVER['REMOTE_ADDR'] ?? '');
            fn_log_event('general', 'runtime', [
                'message' => "Novoton Holidays cron auth refused: {$message}"
                    . ($from === '' ? '' : " (from {$from})"),
            ]);
        }

        echo "ERROR: {$message}\n";

        exit(1);
    }

    /**
     * Initialize cron environment
     *
     * @param array<string, mixed> $params Request parameters (defaults to $_REQUEST)
     * @return array<string, mixed> ['api' => NovotonApi, 'logger' => SyncLogger, 'mode' => string]
     */
    public static function initialize(array $params = []): array
    {
        $rawMode = $params['mode'] ?? $_REQUEST['mode'] ?? 'resinfo';
        $mode = TypeCoerce::toString(preg_replace('/[^a-zA-Z0-9_]/', '', TypeCoerce::toString($rawMode)));

        header('Content-Type: text/plain; charset=utf-8');

        $api = new NovotonApi();
        $logger = new SyncLogger($mode);

        return [
            'api' => $api,
            'logger' => $logger,
            'mode' => $mode,
        ];
    }

    /**
     * Parse excluded resorts from request or settings
     *
     * @param array<string, mixed> $params
     * @return list<string>
     */
    public static function getExcludedResorts(array $params = []): array
    {
        $excludeResorts = $params['exclude_resorts'] ?? $_REQUEST['exclude_resorts'] ?? null;

        if (!empty($excludeResorts)) {
            if (is_array($excludeResorts)) {
                return TypeCoerce::toStringList(array_values(array_filter($excludeResorts)));
            }
            return TypeCoerce::toStringList(array_values(array_filter(array_map('trim', explode(',', TypeCoerce::toString($excludeResorts))))));
        }

        // Fall back to settings
        return ConfigProvider::getExcludedResorts();
    }

    /**
     * Print available modes help
     */
    public static function printAvailableModes(SyncLogger $logger): void
    {
        $logger->output('Available modes:');
        $logger->output('- hotel_info_batched: [RECOMMENDED] Batched hotel info sync with resume');
        $logger->output('    &status=1          - Check progress');
        $logger->output('    &force_full=1      - Force full sync (all hotels)');
        $logger->output('    &reset=1           - Reset/cancel in-progress sync');
        $logger->output('    &batch_size=100    - Hotels per batch (default: 100)');
        $logger->output('    &max_time=300      - Max seconds per run (default: 300)');
        $logger->output('    &unlimited=1       - No time limit (for CLI PHP usage)');
        $logger->output('- sync_priceinfo_batched: [RECOMMENDED] Batched priceinfo sync with resume');
        $logger->output('    &status=1          - Check progress');
        $logger->output('    &force_full=1      - Force full sync (all packages)');
        $logger->output('    &reset=1           - Reset/cancel in-progress sync');
        $logger->output('    &batch_size=50     - Packages per batch (default: 50)');
        $logger->output('    &max_time=300      - Max seconds per run (default: 300)');
        $logger->output('    &stale_hours=24    - Re-sync packages older than N hours');
        $logger->output('    &unlimited=1       - No time limit (for CLI PHP usage)');
        $logger->output('- hotel_list: Hotel list sync from API');
        $logger->output('- resinfo: Check ASK bookings status');
        $logger->output('- offers_update: Check for new/updated offers (&country=BULGARIA)');
        $logger->output('- add_hotels_as_products: Add hotels as products');
        $logger->output('- resort_list: Sync resort names from API (authoritative names for room_price)');
        $logger->output('- list_facilities: Sync facilities list from API');
        $logger->output('- exchange_rates: Update currency rates from BNR (daily)');
        $logger->output('');
        $logger->output('Recommended workflow:');
        $logger->output('  1. hotel_info_batched (every 5 min) - Smart hotel info sync with resume');
        $logger->output('     - First run: syncs all hotels (batched)');
        $logger->output('     - Daily: only new/changed hotels from offers_update');
        $logger->output('     - Every 6 months: automatic full re-sync');
        $logger->output('  2. sync_priceinfo_batched (every 5 min) - Smart priceinfo sync with resume');
        $logger->output('     - First run: syncs all packages (batched)');
        $logger->output('     - Daily: only stale packages (older than 24h)');
        $logger->output('     - Every 7 days: automatic full re-sync');
        $logger->output('  3. resort_list (weekly) - Sync resort names from API');
        $logger->output('  4. list_facilities (weekly) - Sync facilities list');
        $logger->output('  5. exchange_rates (daily) - Update BNR exchange rates');
    }
}
