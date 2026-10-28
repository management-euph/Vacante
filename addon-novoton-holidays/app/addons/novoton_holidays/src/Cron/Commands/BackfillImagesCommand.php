<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Cron\Commands;

use Tygh\Addons\NovotonHolidays\Constants;
use Tygh\Addons\NovotonHolidays\Cron\AbstractCronCommand;
use Tygh\Addons\NovotonHolidays\Services\HotelImageSync;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Re-attach hotel images to novoton products that are missing them.
 *
 * add_hotels_as_products attaches images at product-creation time, but a
 * transient provider hiccup on the per-hotel getHotelImages call (caught +
 * logged, product still created) leaves that product image-less — and
 * add_products never revisits it (it only processes UNLINKED hotels). This
 * is the recovery path: it finds linked products with zero image rows and
 * re-runs the exact same, proven attach pipeline.
 *
 * Naturally resumable with no state file: a product drops out of the
 * candidate set the moment it has at least one image. Re-runs hourly until
 * the backlog drains, then it's a no-op.
 *
 * Photos go through HotelImageSync, as at product creation: remembered, so
 * the weekly refresh_images knows them, and never added twice.
 *
 * Params:
 *   limit=N     products per run (default 100)
 *   status=1    print backlog counters, no processing
 *   force=1     check ALL linked products (not just image-less ones); a
 *               photo a product already has is not added again
 *   hotel_id=X  backfill a single hotel's product
 */
class BackfillImagesCommand extends AbstractCronCommand
{
    private const int DEFAULT_LIMIT = 100;

    /** Image source; defaults to the live API, injectable for tests. */
    private ?\Closure $imageFetcher = null;

    /** Attach sink; defaults to HotelImageSync, injectable for tests. */
    private ?\Closure $attacher = null;

    /** Pacing hook (microseconds); injectable for tests. */
    private ?\Closure $sleeper = null;

    /**
     * @return list<string>
     */
    #[\Override]
    public static function getModes(): array
    {
        return ['backfill_images'];
    }

    public static function getDescription(): string
    {
        return 'Re-attach hotel images to products missing them (recovery for failed image syncs)';
    }

    /** @param callable(string): \SimpleXMLElement $fetcher */
    public function setImageFetcher(callable $fetcher): void
    {
        $this->imageFetcher = $fetcher(...);
    }

    /** @param callable(string, int, list<string>): int $attacher hotel id, product id, photo paths => images added */
    public function setAttacher(callable $attacher): void
    {
        $this->attacher = $attacher(...);
    }

    /** @param callable(int): void $sleeper */
    public function setSleeper(callable $sleeper): void
    {
        $this->sleeper = $sleeper(...);
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(): array
    {
        if (!empty($this->getParam('status'))) {
            return $this->printStatus();
        }

        $force = !empty($this->getParam('force'));
        $limit = TypeCoerce::toInt($this->getParam('limit', self::DEFAULT_LIMIT));
        if ($limit <= 0) {
            $limit = self::DEFAULT_LIMIT;
        }

        $hotelId = TypeCoerce::toString($this->getParam('hotel_id', ''));
        $candidates = $hotelId !== ''
            ? $this->getSingle($hotelId)
            : $this->getCandidates($limit, $force);

        if ($candidates === []) {
            $this->output($force
                ? 'No linked products found.'
                : 'Nothing to backfill — every linked product already has images.');
            $stats = ['processed' => 0, 'attached' => 0, 'no_urls' => 0, 'remaining' => 0];
            $this->logComplete('backfill_images', $stats);

            return ['success' => true, 'stats' => $stats];
        }

        $this->output('Backfilling images for ' . count($candidates) . ' products...');
        $this->output('');

        $fetch = $this->imageFetcher ?? fn (string $hid): \SimpleXMLElement => $this->api->hotels()->getHotelImages($hid);
        $attach = $this->attacher ?? $this->attachImages(...);
        $sleeper = $this->sleeper ?? static function (int $us): void {
            usleep($us);
        };

        $processed = 0;
        $totalAttached = 0;
        $noUrls = 0;

        foreach ($candidates as $i => $hotel) {
            if ($i > 0) {
                $sleeper(Constants::API_DELAY_MODERATE); // pace the provider API
            }

            $hid = TypeCoerce::toString($hotel['hotel_id'] ?? '');
            $pid = TypeCoerce::toInt($hotel['product_id'] ?? 0);
            $name = TypeCoerce::toString($hotel['hotel_name'] ?? '');
            if ($hid === '' || $pid <= 0) {
                continue;
            }
            $processed++;

            $paths = $this->collectImagePaths($hid, $fetch);
            if ($paths === []) {
                $noUrls++;
                $this->output("  {$hid} | {$name}: no image URLs from API");
                continue;
            }

            $attached = TypeCoerce::toInt($attach($hid, $pid, $paths));
            $totalAttached += $attached;
            $this->output("  {$hid} | {$name} -> product #{$pid}: {$attached} image(s) attached");
        }

        $remaining = $this->countMissing();

        $this->output('');
        $this->output("Done: {$processed} products processed, {$totalAttached} images attached, {$noUrls} had no URLs. Remaining without images: {$remaining}.");

        $stats = [
            'processed' => $processed,
            'attached' => $totalAttached,
            'no_urls' => $noUrls,
            'remaining' => $remaining,
        ];
        $this->logComplete('backfill_images', $stats);
        $this->logToSyncTable('backfill_images', $processed, 0);

        return ['success' => true, 'stats' => $stats];
    }

    /**
     * A hotel's photo paths from the API, as HotelImageSync reads them (the
     * first 10, in Novoton's order).
     *
     * @return list<string>
     */
    private function collectImagePaths(string $hotelId, \Closure $fetch): array
    {
        try {
            return HotelImageSync::paths($fetch($hotelId)) ?? [];
        } catch (\Throwable $e) {
            $this->output('  WARN: image fetch failed: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * Default attach sink: HotelImageSync, as at product creation. A named
     * method, so the list<string> contract is on a signature PHPStan checks.
     *
     * @param list<string> $paths
     */
    private function attachImages(string $hotelId, int $productId, array $paths): int
    {
        return (new HotelImageSync())->sync($hotelId, $productId, $paths, date('Y-m-d H:i:s'))['added'];
    }

    /**
     * Linked products missing images (the backlog), oldest hotel first.
     *
     * @return list<array<string, mixed>>
     */
    private function getCandidates(int $limit, bool $force): array
    {
        if ($force) {
            return TypeCoerce::toRowList(db_get_array(
                'SELECT hotel_id, hotel_name, product_id FROM ?:novoton_hotels
                 WHERE product_id IS NOT NULL AND product_id > 0
                 ORDER BY hotel_id LIMIT ?i',
                $limit,
            ));
        }

        return TypeCoerce::toRowList(db_get_array(
            "SELECT h.hotel_id, h.hotel_name, h.product_id FROM ?:novoton_hotels h
             WHERE h.product_id IS NOT NULL AND h.product_id > 0
               AND NOT EXISTS (
                   SELECT 1 FROM ?:images_links il
                   WHERE il.object_id = h.product_id AND il.object_type = 'product'
               )
             ORDER BY h.hotel_id LIMIT ?i",
            $limit,
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function getSingle(string $hotelId): array
    {
        return TypeCoerce::toRowList(db_get_array(
            'SELECT hotel_id, hotel_name, product_id FROM ?:novoton_hotels
             WHERE hotel_id = ?s AND product_id IS NOT NULL AND product_id > 0',
            $hotelId,
        ));
    }

    private function countMissing(): int
    {
        return TypeCoerce::toInt(db_get_field(
            "SELECT COUNT(*) FROM ?:novoton_hotels h
             WHERE h.product_id IS NOT NULL AND h.product_id > 0
               AND NOT EXISTS (
                   SELECT 1 FROM ?:images_links il
                   WHERE il.object_id = h.product_id AND il.object_type = 'product'
               )",
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function printStatus(): array
    {
        $linked = TypeCoerce::toInt(db_get_field(
            'SELECT COUNT(*) FROM ?:novoton_hotels WHERE product_id IS NOT NULL AND product_id > 0',
        ));
        $missing = $this->countMissing();

        $this->output('Image backfill status:');
        $this->output("  Linked products: {$linked}");
        $this->output("  Missing images (backlog): {$missing}");

        return ['success' => true, 'stats' => ['linked' => $linked, 'missing' => $missing]];
    }
}
