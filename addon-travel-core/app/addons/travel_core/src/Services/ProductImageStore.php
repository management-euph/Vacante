<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Services;

use Tygh\Addons\TravelCore\Contracts\ProductImageStoreInterface;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * A product's images in CS-Cart (?:images_links pairs, their detailed image
 * files), for a supplier's photo sync.
 *
 * Adding goes through fn_travel_core_attach_product_image_pair(), the path
 * Sphinx's images already take. Removing takes fn_delete_image_pair(), after
 * checking the pair is this product's.
 */
final class ProductImageStore implements ProductImageStoreInterface
{
    /** Image files larger than this are not read back. */
    private const int MAX_READ_BYTES = 25 * 1024 * 1024;

    /**
     * @param string $prefix the start of the stored file names, e.g. 'novoton'
     */
    public function __construct(private readonly string $prefix = 'travel')
    {
    }

    #[\Override]
    public function images(int $productId): array
    {
        $images = [];
        foreach (['M', 'A'] as $type) {
            // One product: its main pair comes back as the pair itself, its
            // additional pairs keyed by pair id.
            $pairs = fn_get_image_pairs($productId, 'product', $type, false, true);
            $pairs = is_array($pairs) ? (isset($pairs['pair_id']) ? [$pairs] : $pairs) : [];
            foreach ($pairs as $pair) {
                if (!is_array($pair)) {
                    continue;
                }
                $pairId = TypeCoerce::toInt($pair['pair_id'] ?? 0);
                if ($pairId <= 0) {
                    continue;
                }
                $detailed = is_array($pair['detailed'] ?? null) ? $pair['detailed'] : [];
                $file = TypeCoerce::toString($detailed['absolute_path'] ?? '');
                $images[] = ['pair_id' => $pairId, 'main' => $type === 'M', 'file' => $file !== '' && is_file($file) ? $file : ''];
            }
        }

        return $images;
    }

    #[\Override]
    public function read(string $file): ?string
    {
        if ($file === '' || !is_file($file) || !is_readable($file) || (int) filesize($file) > self::MAX_READ_BYTES) {
            return null;
        }
        $bytes = file_get_contents($file);

        return $bytes === false ? null : $bytes;
    }

    #[\Override]
    public function attach(int $productId, string $bytes, bool $main): int
    {
        $temp = function_exists('fn_create_temp_file')
            ? TypeCoerce::toString(fn_create_temp_file())
            : (string) tempnam(sys_get_temp_dir(), 'tc_img');
        if ($temp === '' || file_put_contents($temp, $bytes) === false) {
            return 0;
        }

        // Takes the temp file over: it is removed whatever happens.
        return fn_travel_core_attach_product_image_pair($productId, $temp, $this->prefix, $main);
    }

    #[\Override]
    public function remove(int $productId, int $pairId): bool
    {
        if (!$this->owns($productId, $pairId)) {
            return false;
        }
        fn_delete_image_pair($pairId, 'product');

        return !$this->owns($productId, $pairId);
    }

    #[\Override]
    public function makeMain(int $productId, int $pairId): void
    {
        db_query(
            "UPDATE ?:images_links SET type = 'M' WHERE pair_id = ?i AND object_id = ?i AND object_type = 'product'",
            $pairId,
            $productId,
        );
    }

    /**
     * Reads the database, so its answer changes once the pair is deleted.
     *
     * @phpstan-impure
     */
    private function owns(int $productId, int $pairId): bool
    {
        return TypeCoerce::toInt(db_get_field(
            "SELECT pair_id FROM ?:images_links WHERE pair_id = ?i AND object_id = ?i AND object_type = 'product'",
            $pairId,
            $productId,
        )) === $pairId;
    }
}
