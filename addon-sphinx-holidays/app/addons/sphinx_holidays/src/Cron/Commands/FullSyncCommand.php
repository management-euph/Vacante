<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Cron\Commands;

use Tygh\Addons\SphinxHolidays\Cron\CronDispatcher;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Cron command: run all sync modes in sequence.
 *
 * Executes the complete sync pipeline:
 *   destinations → hotels → assign_boards → package_routes → circuits →
 *   experiences → order_status → add_products → update_products →
 *   sync_images → process_image_queue → cleanup
 *
 * Note: discover_boards is excluded — it uses live API search per destination
 * and should run on its own cron schedule (mode=discover_boards).
 * Note: exchange_rates is handled by travel_core's centralized cron.
 *
 * Usage: php cron.php access_key=KEY mode=full
 */
class FullSyncCommand extends AbstractSyncCommand
{
    /**
     * @return list<string>
     */
    #[\Override]
    public static function getModes(): array
    {
        return ['full'];
    }

    /**
     * Ordered list of modes to execute in sequence.
     *
     * 'full' itself is excluded to prevent recursion.
     * 'discover_boards' is excluded — it performs live API searches per destination
     * (slow, ~15s each) and should run on its own cron schedule with batch resume.
     *
     * assign_boards runs after hotels to assign already-discovered boards as
     * CS-Cart product features.
     * update_products syncs changed hotel data to existing CS-Cart products.
     * sync_images populates the image queue from hotel DB records; the very
     * next step, process_image_queue, downloads and attaches those images so a
     * full run leaves products with complete galleries.
     */
    private const array SYNC_SEQUENCE = [
        'destinations',
        'hotels',
        'assign_boards',
        'package_routes',
        'circuits',
        'experiences',
        'order_status',
        'add_products',
        'update_products',
        'sync_images',
        'process_image_queue',
        'cleanup',
    ];

    public static function getDescription(): string
    {
        return 'Run all sync modes in sequence (full pipeline)';
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    #[\Override]
    public function execute(array $params = []): array
    {
        $startMs = (int)(microtime(true) * 1000);

        $this->output('=== FULL SYNC PIPELINE ===');
        $this->output('Modes: ' . implode(' → ', self::SYNC_SEQUENCE));
        $this->output('');

        $dispatcher = new CronDispatcher();
        $results = [];
        $totalSuccess = 0;
        $totalFailed = 0;

        foreach (self::SYNC_SEQUENCE as $mode) {
            if (!$dispatcher->hasMode($mode)) {
                $this->output("[SKIP] {$mode} — mode not registered");
                continue;
            }

            $this->output("──── {$mode} ────");
            $result = $dispatcher->dispatch($mode, $params);

            $success = TypeCoerce::toBool($result['success'] ?? false);
            $results[$mode] = $success;

            if ($success) {
                $totalSuccess++;
            } elseif (TypeCoerce::toBool($result['busy'] ?? false)) {
                $this->output("[INFO] {$mode} is already running, skipped.");
            } else {
                $totalFailed++;
                $stats = TypeCoerce::toStringMap($result['stats'] ?? []);
                $error = TypeCoerce::toString($result['error'] ?? $stats['error'] ?? 'unknown error');
                $this->output("[WARN] {$mode} finished with errors: {$error}");
            }

            $this->output('');
        }

        $durationMs = (int)(microtime(true) * 1000) - $startMs;

        $this->output('=== FULL SYNC COMPLETE ===');
        $this->output("Results: {$totalSuccess} succeeded, {$totalFailed} failed");
        $this->output('Duration: ' . round($durationMs / 1000, 1) . 's');

        // Log the composite result
        db_query(
            "INSERT INTO ?:sphinx_sync_log (sync_type, status, items_total, items_synced, items_failed, duration_ms, started_at, completed_at) VALUES ('full', ?s, ?i, ?i, ?i, ?i, NOW(), NOW())",
            $totalFailed === 0 ? 'completed' : 'failed',
            count(self::SYNC_SEQUENCE),
            $totalSuccess,
            $totalFailed,
            $durationMs,
        );

        return [
            'success' => $totalFailed === 0,
            'stats' => [
                'total' => count(self::SYNC_SEQUENCE),
                'synced' => $totalSuccess,
                'failed' => $totalFailed,
                'duration_ms' => $durationMs,
                'modes' => $results,
            ],
        ];
    }
}
