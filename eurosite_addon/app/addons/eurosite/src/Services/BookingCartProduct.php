<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Services;

/**
 * Which CS-Cart product a Eurosite booking's cart line goes on — by
 * product_id, never by product code (a code is optional in CS-Cart; every
 * product has an id).
 *
 * In order, the first product that exists and can be bought (status A or H;
 * CS-Cart drops a disabled product from the cart):
 *  1. the product page the guest booked from (its id rides in the server-side
 *     offer snapshot, so the form cannot change it);
 *  2. the hotel's own product (eurosite_hotels.product_id), for a booking
 *     started from a destination search;
 *  3. the hidden carrier product, only for a hotel that has no product.
 *
 * Downstream nothing keys on the line's product_id: the order hooks and the
 * booking submission read extra.eurosite_booking_id / extra.travel_booking.
 */
final class BookingCartProduct
{
    public const SOURCE_PAGE = 'page';

    public const SOURCE_HOTEL = 'hotel';

    public const SOURCE_CARRIER = 'carrier';

    public const SOURCE_NONE = 'none';

    /**
     * @param callable(int): string $statusOf the product's status, '' when it doesn't exist
     * @param callable(): int $ensureCarrier the carrier's id, created when missing; 0 on failure
     *
     * @return array{product_id: int, source: string}
     */
    public static function resolve(int $pageProductId, int $hotelProductId, callable $statusOf, callable $ensureCarrier): array
    {
        $candidates = [self::SOURCE_PAGE => $pageProductId, self::SOURCE_HOTEL => $hotelProductId];
        $checked = [];
        foreach ($candidates as $source => $productId) {
            if ($productId <= 0 || isset($checked[$productId])) {
                continue;
            }
            $checked[$productId] = true;
            if (self::canBeBought($statusOf($productId))) {
                return ['product_id' => $productId, 'source' => $source];
            }
        }

        $carrierId = $ensureCarrier();

        return $carrierId > 0
            ? ['product_id' => $carrierId, 'source' => self::SOURCE_CARRIER]
            : ['product_id' => 0, 'source' => self::SOURCE_NONE];
    }

    /** Active, or hidden (a gate-hidden hotel or the carrier): both stay in a cart. */
    public static function canBeBought(string $status): bool
    {
        return in_array($status, ['A', 'H'], true);
    }
}
