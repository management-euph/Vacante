<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Cron\Commands;

use Tygh\Addons\SphinxHolidays\Api\ImageHelper;
use Tygh\Addons\TravelCore\Helpers\DebugLogger;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Cron command: process pending rows from the image sync queue.
 *
 * Downloads and attaches images that were queued by sync_images (hotels)
 * and sync_circuit_images (circuits).
 * Safe to run every 1-5 minutes — atomic status updates prevent double-processing
 * even when two cron instances run concurrently.
 *
 * Params:
 *   &batch_size=N    — rows to process per invocation (default 50)
 *   &limit=N         — cap total rows processed (default unlimited)
 *   &reset_failed=Y  — reset failed rows back to pending, then exit
 */
class ProcessImageQueueCommand extends AbstractSyncCommand
{
    /**
     * @return list<string>
     */
    #[\Override]
    public static function getModes(): array
    {
        return ['process_image_queue'];
    }

    private const int DEFAULT_BATCH = 50;

    #[\Override]
    public static function getDescription(): string
    {
        return 'Download and attach queued hotel and circuit images (run after sync_images, sync_circuit_images)';
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    #[\Override]
    public function execute(array $params = []): array
    {
        $startMs = (int)(microtime(true) * 1000);
        $batchSize = TypeCoerce::toInt($params['batch_size'] ?? self::DEFAULT_BATCH);
        $limit = TypeCoerce::toInt($params['limit'] ?? 0);
        $resetFailed = TypeCoerce::toString($params['reset_failed'] ?? '') === 'Y';

        if ($batchSize <= 0) {
            $batchSize = self::DEFAULT_BATCH;
        }

        if ($resetFailed) {
            $count = db_query(
                "UPDATE ?:sphinx_image_sync_queue SET status = 'pending', updated_at = ?i WHERE status = 'failed'",
                time(),
            );
            $n = is_int($count) ? $count : 0;
            $this->output("Reset {$n} failed row(s) back to pending.");
            return ['success' => true, 'stats' => ['reset' => $n]];
        }

        $stats = ['processed' => 0, 'completed' => 0, 'failed' => 0];

        while (true) {
            if ($limit > 0 && $stats['processed'] >= $limit) {
                break;
            }

            $fetchN = ($limit > 0) ? min($batchSize, $limit - $stats['processed']) : $batchSize;

            /** @var array<array<string, mixed>> $rows */
            $rows = db_get_array(
                "SELECT id, hotel_id, product_id, image_url, is_main
                 FROM ?:sphinx_image_sync_queue
                 WHERE status = 'pending'
                 ORDER BY id ASC
                 LIMIT ?i",
                $fetchN,
            );

            if (empty($rows)) {
                break;
            }

            $ids = array_map(static fn (array $r): int => TypeCoerce::toInt($r['id']), $rows);

            // Atomic claim: only rows still 'pending' get marked 'processing'.
            // If two cron instances run concurrently, each gets a distinct set.
            db_query(
                "UPDATE ?:sphinx_image_sync_queue
                 SET status = 'processing', attempts = attempts + 1, updated_at = ?i
                 WHERE id IN (?a) AND status = 'pending'",
                time(),
                $ids,
            );

            foreach ($rows as $row) {
                $rowId = TypeCoerce::toInt($row['id']);
                $productId = TypeCoerce::toInt($row['product_id'] ?? 0);
                $imageUrl = TypeCoerce::toString($row['image_url'] ?? '');
                $isMain = (bool) $row['is_main'];
                $hotelId = TypeCoerce::toString($row['hotel_id'] ?? '');

                $ok = fn_sphinx_holidays_add_product_image($productId, $imageUrl, $isMain);

                if ($ok) {
                    db_query(
                        "UPDATE ?:sphinx_image_sync_queue SET status = 'completed', updated_at = ?i WHERE id = ?i",
                        time(),
                        $rowId,
                    );
                    $stats['completed']++;
                } else {
                    $errMsg = ImageHelper::$lastDownloadError !== ''
                        ? ImageHelper::$lastDownloadError
                        : DebugLogger::$lastImageAttachError;

                    db_query(
                        "UPDATE ?:sphinx_image_sync_queue SET status = 'failed', error_message = ?s, updated_at = ?i WHERE id = ?i",
                        $errMsg,
                        time(),
                        $rowId,
                    );
                    $this->output("[{$hotelId}] row #{$rowId} FAILED: {$errMsg}");
                    $stats['failed']++;
                }

                $stats['processed']++;
            }
        }

        $durationMs = (int)(microtime(true) * 1000) - $startMs;

        $this->output("Done: {$stats['processed']} processed, {$stats['completed']} completed, {$stats['failed']} failed (" . round($durationMs / 1000, 1) . 's)');

        return [
            'success' => true,
            'stats' => array_merge($stats, ['duration_ms' => $durationMs]),
        ];
    }
}
