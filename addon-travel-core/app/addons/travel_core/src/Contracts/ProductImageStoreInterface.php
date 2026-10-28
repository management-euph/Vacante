<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Contracts;

/**
 * A product's images as a supplier's photo sync sees them: which there are,
 * what each file holds, and adding or removing one. Services\ProductImageStore
 * is CS-Cart's; tests use a fake.
 */
interface ProductImageStoreInterface
{
    /**
     * The product's images, the main one first.
     *
     * @return list<array{pair_id: int, main: bool, file: string}> file: its full path, '' when it cannot be read here
     */
    public function images(int $productId): array;

    /** The bytes of an image file; null when it cannot be read. */
    public function read(string $file): ?string;

    /** Adds an image from its bytes; the new image's pair id, 0 when nothing was added. */
    public function attach(int $productId, string $bytes, bool $main): int;

    /** Removes one of the product's images; false when it is not the product's. */
    public function remove(int $productId, int $pairId): bool;

    /** Makes one of the product's images its main image. */
    public function makeMain(int $productId, int $pairId): void;
}
