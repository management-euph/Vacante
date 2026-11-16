<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Services;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Puts a circuit's photos in the image queue (?:sphinx_image_sync_queue),
 * which process_image_queue empties into the product's CS-Cart images, as
 * it does the hotels' (docs/circuit-page-plan.md, step 2).
 *
 * The queue groups its rows by a text key: a hotel's id, or here
 * "circuit:<id>", so a circuit never shares rows with a hotel of the same
 * number. One row per photo link: a photo already attached stays done, so
 * queueing the same photos again adds nothing.
 */
final class CircuitImageQueue
{
    /** The queue key of a circuit's rows. */
    public static function key(int $circuitId): string
    {
        return 'circuit:' . $circuitId;
    }

    /**
     * The photos to attach: the list the sync kept (details_json.images),
     * else the circuit's one image; each once, in order.
     *
     * @param mixed $detailsJson the details_json column (CircuitStaticContent::details())
     *
     * @return list<string>
     */
    public static function photoUrls(mixed $detailsJson, string $imageUrl): array
    {
        $details = TypeCoerce::toStringMap(is_string($detailsJson) ? json_decode($detailsJson, true) : $detailsJson);
        $urls = [];
        foreach (TypeCoerce::toStringList($details['images'] ?? null) as $url) {
            if (preg_match('#^https?://\S+$#i', $url) === 1) {
                $urls[$url] = true;
            }
        }
        if ($urls === [] && preg_match('#^https?://\S+$#i', $imageUrl) === 1) {
            $urls[$imageUrl] = true;
        }

        return array_keys($urls);
    }

    /**
     * Queues $urls for the product. The first new one becomes the product's
     * main image only when the product has none. A photo already in the queue
     * keeps its row: done stays done, pending stays pending, and one that
     * failed is retried only with $retryFailed.
     *
     * @param list<string> $urls
     *
     * @return int how many photos were new to the queue
     */
    public function queue(int $circuitId, int $productId, array $urls, bool $retryFailed, int $now): int
    {
        if ($circuitId <= 0 || $productId <= 0 || $urls === []) {
            return 0;
        }
        $key = self::key($circuitId);
        // Rows of an earlier product of this circuit (deleted since, a new
        // one made): its photos go to the new product as new ones.
        db_query('DELETE FROM ?:sphinx_image_sync_queue WHERE hotel_id = ?s AND product_id <> ?i', $key, $productId);
        $known = TypeCoerce::toStringList(db_get_fields(
            'SELECT image_url FROM ?:sphinx_image_sync_queue WHERE hotel_id = ?s',
            $key,
        ));
        $hasMain = TypeCoerce::toInt(db_get_field(
            "SELECT COUNT(*) FROM ?:images_links WHERE object_id = ?i AND object_type = 'product' AND type = 'M'",
            $productId,
        )) > 0;

        $new = 0;
        foreach ($urls as $url) {
            if (in_array($url, $known, true)) {
                continue;
            }
            db_query(
                "INSERT IGNORE INTO ?:sphinx_image_sync_queue
                 (hotel_id, product_id, image_url, is_main, status, created_at, updated_at)
                 VALUES (?s, ?i, ?s, ?i, 'pending', ?i, ?i)",
                $key,
                $productId,
                $url,
                $new === 0 && !$hasMain ? 1 : 0,
                $now,
                $now,
            );
            $new++;
        }

        if ($retryFailed) {
            db_query(
                "UPDATE ?:sphinx_image_sync_queue SET status = 'pending', error_message = NULL, updated_at = ?i
                 WHERE hotel_id = ?s AND status = 'failed'",
                $now,
                $key,
            );
        }

        return $new;
    }
}
