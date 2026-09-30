<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Services;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * The store product a provider's booking goes into the cart on — never
 * simply the product_id the browser sent.
 *
 * The product must be:
 *  - that hotel's (or circuit's) own product in the PROVIDER's table (e.g.
 *    sphinx_hotels.product_id), so it belongs to that hotel and to that API
 *    supplier — not any store product;
 *  - buyable: status A, or H when the provider's availability gate hid it
 *    (a disabled product is dropped from the cart by CS-Cart, and one an
 *    admin hid by hand stays hidden).
 * A product_id in the request is accepted only when it is that same product.
 */
final class ProviderCartProduct
{
    /**
     * @param int $requestedId the product_id the request named (0 = none)
     * @param int $ownedId the product the provider's own table links to the hotel (0 = none)
     * @param callable(int): string $statusOf the product's status, '' when it doesn't exist
     * @param bool $gateHidden whether the provider's availability gate hid it
     *
     * @return int the product to use, 0 when the booking must be refused
     */
    public static function resolve(int $requestedId, int $ownedId, callable $statusOf, bool $gateHidden = false): int
    {
        if ($ownedId <= 0 || ($requestedId > 0 && $requestedId !== $ownedId)) {
            return 0;
        }

        return self::canBeBought($statusOf($ownedId), $gateHidden) ? $ownedId : 0;
    }

    public static function canBeBought(string $status, bool $gateHidden = false): bool
    {
        return $status === 'A' || ($status === 'H' && $gateHidden);
    }

    /** The product's status from ?:products, '' when it doesn't exist. */
    public static function productStatus(int $productId): string
    {
        return $productId > 0
            ? TypeCoerce::toString(db_get_field('SELECT status FROM ?:products WHERE product_id = ?i', $productId))
            : '';
    }
}
