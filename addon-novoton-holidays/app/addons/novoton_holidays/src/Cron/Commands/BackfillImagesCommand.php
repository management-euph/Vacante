<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Cron\Commands;

use Tygh\Addons\NovotonHolidays\Constants;
use Tygh\Addons\NovotonHolidays\Cron\AbstractCronCommand;
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
 * Params:
 *   limit=N     products per run (default 100)
 *   status=1    print backlog counters, no processing
 *   force=1     re-attach ALL linked products (not just image-less ones)
 *   hotel_id=X  backfill a single hotel's product
 */
class BackfillImagesCommand extends AbstractCronCommand
{
    private const int DEFAULT_LIMIT = 100;
    private const int MAX_IMAGES = 10;

    /** Image source; defaults to the live API, injectable for tests. */
    private ?\Closure $imageFetcher = null;

    /** Attach sink; defaults to the travel_core URL pipeline, injectable for tests. */
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

    /** @param callable(int, list<string>, bool): int $attacher */
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

            $urls = $this->collectImageUrls($hid, $fetch);
            if ($urls === []) {
                $noUrls++;
                $this->output("  {$hid} | {$name}: no image URLs from API");
                continue;
            }

            $attached = TypeCoerce::toInt($attach($pid, $urls, true));
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
     * Fetch a hotel's image URLs from the API — identical construction to
     * AddProductsCommand / DiagnoseImageUrlsCommand.
     *
     * @return list<string>
     */
    private function collectImageUrls(string $hotelId, \Closure $fetch): array
    {
        $urls = [];
        try {
            $images = $fetch($hotelId);
            if ($images instanceof \SimpleXMLElement && isset($images->url)) {
                foreach ($images->url as $url) {
                    $urls[] = Constants::IMAGE_BASE_URL . str_replace(' ', '%20', (string) $url);
                    if (count($urls) >= self::MAX_IMAGES) {
                        break;
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->output('  WARN: image fetch failed: ' . $e->getMessage());
        }

        return $urls;
    }

    /**
     * Default attach sink: the proven travel_core URL pipeline. Kept as a
     * named method (not an inline closure) so the list<string> contract is
     * carried on a real signature that PHPStan can check.
     *
     * @param list<string> $urls
     */
    private function attachImages(int $productId, array $urls, bool $firstIsMain): int
    {
        return fn_travel_core_attach_images_from_urls($productId, $urls, $firstIsMain);
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
