<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Services;

/**
 * Which CS-Cart product a Eurosite booking's cart line goes on — by
 * product_id, never by product code (a code is optional in CS-Cart; every
 * product has an id). As for Sphinx and Novoton, a booking always goes on the
 * hotel's own product; there is no stand-in product.
 *
 * In order, the first product that exists and can be bought (status A or H;
 * CS-Cart drops a disabled product from the cart):
 *  1. the product page the guest booked from (its id rides in the server-side
 *     offer snapshot, so the form cannot change it);
 *  2. the hotel's own product (eurosite_hotels.product_id), when the page's
 *     product is disabled or gone.
 * None: the hotel is not a store product, so it cannot be booked online.
 *
 * Downstream nothing keys on the line's product_id: the order hooks and the
 * booking submission read extra.eurosite_booking_id / extra.travel_booking.
 */
final class BookingCartProduct
{
    public const SOURCE_PAGE = 'page';

    public const SOURCE_HOTEL = 'hotel';

    public const SOURCE_NONE = 'none';

    /**
     * @param callable(int): string $statusOf the product's status, '' when it doesn't exist
     *
     * @return array{product_id: int, source: string}
     */
    public static function resolve(int $pageProductId, int $hotelProductId, callable $statusOf): array
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

        return ['product_id' => 0, 'source' => self::SOURCE_NONE];
    }

    /**
     * The product for one offer snapshot: the page it was booked from, else
     * the hotel's own product.
     *
     * @param array<string, mixed> $snapshot an OfferContextStore snapshot
     * @param array<string, mixed>|null $hotelRow the offer's eurosite_hotels row
     *
     * @return array{product_id: int, source: string}
     */
    public static function forSnapshot(array $snapshot, ?array $hotelRow): array
    {
        $page = $snapshot['cart_product_id'] ?? 0;

        return self::forHotel(is_numeric($page) ? (int) $page : 0, $hotelRow);
    }

    /**
     * The product for a hotel, before any offer exists: the search refuses a
     * hotel with none, as it is not a store product.
     *
     * @param array<string, mixed>|null $hotelRow the eurosite_hotels row
     *
     * @return array{product_id: int, source: string}
     */
    public static function forHotel(int $pageProductId, ?array $hotelRow): array
    {
        $hotel = $hotelRow['product_id'] ?? 0;

        return self::resolve(
            $pageProductId,
            is_numeric($hotel) ? (int) $hotel : 0,
            static function (int $productId): string {
                $status = db_get_field('SELECT status FROM ?:products WHERE product_id = ?i', $productId);

                return is_string($status) ? $status : '';
            },
        );
    }

    /** Active, or hidden (the availability gate hides a hotel): both stay in a cart. */
    public static function canBeBought(string $status): bool
    {
        return in_array($status, ['A', 'H'], true);
    }
}
