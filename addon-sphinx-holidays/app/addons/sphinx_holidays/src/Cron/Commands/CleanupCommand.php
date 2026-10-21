<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Cron\Commands;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Cron command: clean up orphan data, old logs, and expired cache.
 *
 * Usage: php cron.php access_key=KEY mode=cleanup
 */
class CleanupCommand extends AbstractSyncCommand
{
    /**
     * @return list<string>
     */
    #[\Override]
    public static function getModes(): array
    {
        return ['cleanup'];
    }

    #[\Override]
    public static function getDescription(): string
    {
        return 'Clean up orphan bookings, old sync logs, and expired cache entries';
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    #[\Override]
    public function execute(array $params = []): array
    {
        $startMs = (int)(microtime(true) * 1000);

        $this->output('Starting cleanup...');

        $cleaned = [
            'orphan_bookings' => 0,
            'old_logs' => 0,
            'expired_cache' => 0,
            'orphan_products' => 0,
        ];
        $errors = 0;

        // 1. Remove orphan bookings (order_id = 0, created more than 48h ago).
        // Via the repository so the shared ?:travel_bookings MIRROR rows go
        // too — the previous inline DELETE only purged ?:sphinx_bookings and
        // left mirror-only "Order ID -" rows in the admin grid forever.
        try {
            $cleaned['orphan_bookings'] = \Tygh\Addons\SphinxHolidays\Services\Container::getBookingRepository()
                ->deleteOrphans(48);
            $this->output("Orphan bookings removed: {$cleaned['orphan_bookings']}");
        } catch (\Throwable $e) {
            $errors++;
            $this->output('Orphan bookings cleanup failed: ' . $e->getMessage());
            fn_log_event('general', 'runtime', ['message' => 'Sphinx cleanup: orphan bookings failed: ' . $e->getMessage()]);
        }

        // 2. Trim sync log — keep latest 200 entries, delete older ones
        try {
            $cutoffId = TypeCoerce::toInt(db_get_field(
                'SELECT log_id FROM ?:sphinx_sync_log ORDER BY log_id DESC LIMIT 1 OFFSET 200',
            ));
            if ($cutoffId > 0) {
                $cleaned['old_logs'] = TypeCoerce::toInt(db_query('DELETE FROM ?:sphinx_sync_log WHERE log_id <= ?i', $cutoffId));
            }
            $this->output("Old sync log entries removed: {$cleaned['old_logs']}");
        } catch (\Throwable $e) {
            $errors++;
            $this->output('Sync log cleanup failed: ' . $e->getMessage());
            fn_log_event('general', 'runtime', ['message' => 'Sphinx cleanup: sync log failed: ' . $e->getMessage()]);
        }

        // 3. Delete expired cache entries
        try {
            $cleaned['expired_cache'] = TypeCoerce::toInt(db_query(
                'DELETE FROM ?:sphinx_cache WHERE expires_at < ?i',
                time(),
            ));
            $this->output("Expired cache entries removed: {$cleaned['expired_cache']}");
        } catch (\Throwable $e) {
            $errors++;
            $this->output('Cache cleanup failed: ' . $e->getMessage());
            fn_log_event('general', 'runtime', ['message' => 'Sphinx cleanup: cache cleanup failed: ' . $e->getMessage()]);
        }

        // 4. Unlink orphan product references (product deleted in CS-Cart but still referenced in sphinx_hotels)
        try {
            $cleaned['orphan_products'] = TypeCoerce::toInt(db_query(
                "UPDATE ?:sphinx_hotels h
                 LEFT JOIN ?:products p ON p.product_id = h.product_id
                 SET h.product_id = NULL, h.product_skip_reason = NULL, h.product_needs_update = 'N',
                     h.gate_hidden = 'N', h.gate_released = 'N'
                 WHERE h.product_id IS NOT NULL AND h.product_id > 0 AND p.product_id IS NULL",
            ));
            if ($cleaned['orphan_products'] > 0) {
                $this->output("Orphan product links cleared: {$cleaned['orphan_products']} (products deleted in CS-Cart, hotels eligible for re-creation)");
            }
        } catch (\Throwable $e) {
            $errors++;
            $this->output('Orphan product cleanup failed: ' . $e->getMessage());
            fn_log_event('general', 'runtime', ['message' => 'Sphinx cleanup: orphan products failed: ' . $e->getMessage()]);
        }

        $durationMs = (int)(microtime(true) * 1000) - $startMs;
        $total = array_sum($cleaned);
        $this->output("Cleanup complete: {$total} items removed/fixed in " . round($durationMs / 1000, 1) . 's');

        return [
            'success' => $errors === 0,
            'stats' => [
                'total' => $total,
                'synced' => $total,
                'failed' => $errors,
                'duration_ms' => $durationMs,
                'details' => $cleaned,
            ],
        ];
    }
}
