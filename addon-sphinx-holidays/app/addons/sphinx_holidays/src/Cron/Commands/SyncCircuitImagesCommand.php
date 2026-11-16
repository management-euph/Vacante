<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Cron\Commands;

use Tygh\Addons\SphinxHolidays\Repository\CircuitRepository;
use Tygh\Addons\SphinxHolidays\Services\CircuitImageQueue;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Cron command: queue the photos of the circuits that have a product
 * (docs/circuit-page-plan.md, step 2). process_image_queue then downloads
 * them and attaches them to the product, as for the hotels.
 *
 * The photos come from what the circuits sync kept (details_json.images,
 * else image_url): no API calls. Every run queues every photo again; a photo
 * already attached stays done (the queue keeps it as completed), so a run
 * adds only the photos Sphinx added since. A photo Sphinx removed stays on
 * the product.
 *
 * Params:
 *   &limit=N   circuits per run (default all)
 *   &force=Y   also retry the photos whose download failed
 */
class SyncCircuitImagesCommand extends AbstractSyncCommand
{
    /**
     * @return list<string>
     */
    #[\Override]
    public static function getModes(): array
    {
        return ['sync_circuit_images'];
    }

    #[\Override]
    public static function getDescription(): string
    {
        return 'Queue the photos of circuits with a product (run process_image_queue to download)';
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    #[\Override]
    public function execute(array $params = []): array
    {
        $startMs = (int) (microtime(true) * 1000);
        $limit = max(0, TypeCoerce::toInt($params['limit'] ?? 0));
        $retryFailed = TypeCoerce::toString($params['force'] ?? '') === 'Y';

        $circuits = (new CircuitRepository())->findLinkedForImages($limit);
        if ($circuits === []) {
            $this->output('No circuit has a product yet: run add_circuit_products first.');

            return ['success' => true, 'stats' => ['circuits' => 0, 'queued' => 0, 'without_photos' => 0]];
        }

        $queue = new CircuitImageQueue();
        $stats = ['circuits' => 0, 'queued' => 0, 'without_photos' => 0];
        foreach ($circuits as $circuit) {
            $circuitId = TypeCoerce::toInt($circuit['circuit_id'] ?? 0);
            $productId = TypeCoerce::toInt($circuit['product_id'] ?? 0);
            $urls = CircuitImageQueue::photoUrls($circuit['details_json'] ?? null, TypeCoerce::toString($circuit['image_url'] ?? ''));
            $stats['circuits']++;
            if ($urls === []) {
                $stats['without_photos']++;
                continue;
            }
            $queued = $queue->queue($circuitId, $productId, $urls, $retryFailed, time());
            $stats['queued'] += $queued;
            if ($queued > 0) {
                $this->output("[circuit {$circuitId}] " . TypeCoerce::toString($circuit['name'] ?? '') . " ... {$queued} new photo(s) queued");
            }
        }

        $seconds = round(((int) (microtime(true) * 1000) - $startMs) / 1000, 1);
        $this->output("Done: {$stats['circuits']} circuits, {$stats['queued']} photos queued, {$stats['without_photos']} without photos ({$seconds}s)");
        if ($stats['without_photos'] > 0) {
            $this->output('A circuit without photos: run a full circuits sync (full=1), which keeps the photo list.');
        }
        if ($stats['queued'] > 0) {
            $this->output('Next: process_image_queue downloads them and attaches them to the products.');
        }

        return ['success' => true, 'stats' => $stats];
    }
}
