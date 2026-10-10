<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Services;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Where a refused or failed Eurosite booking step sends the guest: back to
 * the hotel's product page, with the stay they searched for. The product
 * page's booking engine reads check_in / check_out / rooms_data from the URL
 * and re-runs the search inline, so the guest sees the offers again instead
 * of an empty page. Without dates it restores the guest's last inline search.
 *
 * No product known: the home page (there is no destination search).
 */
final class BookingReturnUrl
{
    public const HOME = 'index.index';

    /**
     * @param list<array{adults: int, children_ages: list<int>}> $occupancy one entry per room
     */
    public static function forProduct(int $productId, array $occupancy = [], string $checkIn = '', string $checkOut = ''): string
    {
        if ($productId <= 0) {
            return self::HOME;
        }
        $query = ['product_id' => $productId];
        if ($checkIn !== '' && $checkOut !== '') {
            $query['check_in'] = $checkIn;
            $query['check_out'] = $checkOut;
        }
        if ($occupancy !== []) {
            ['adults' => $adults, 'children_ages' => $ages] = RoomOccupancy::totals($occupancy);
            $query['adults'] = $adults;
            $query['children'] = count($ages);
            $query['children_ages'] = implode(',', $ages);
            $query['rooms'] = count($occupancy);
            // The engine's rooms_data shape
            $query['rooms_data'] = (string) json_encode(array_map(
                static fn (array $room): array => [
                    'adults' => $room['adults'],
                    'children' => count($room['children_ages']),
                    'childrenAges' => $room['children_ages'],
                ],
                $occupancy,
            ));
        }

        return 'products.view?' . http_build_query($query);
    }

    /**
     * The same, filled from an offer snapshot; $occupancy overrides the
     * snapshot's rooms (e.g. with the children's ages corrected).
     *
     * @param array<string, mixed> $snapshot an OfferContextStore snapshot
     * @param list<array{adults: int, children_ages: list<int>}>|null $occupancy
     */
    public static function forSnapshot(array $snapshot, int $productId, ?array $occupancy = null): string
    {
        return self::forProduct(
            $productId,
            $occupancy ?? RoomOccupancy::fromSnapshot($snapshot),
            TypeCoerce::toString($snapshot['check_in'] ?? ''),
            TypeCoerce::toString($snapshot['check_out'] ?? ''),
        );
    }

    /**
     * The product page a request names (return_product_id), kept only when it
     * is a Eurosite hotel's product — it is only ever a redirect target.
     *
     * @param array<array-key, mixed> $request
     */
    public static function requestedProductId(array $request): int
    {
        $productId = TypeCoerce::toInt($request['return_product_id'] ?? 0);

        return $productId > 0 && Container::hotels()->findByProductId($productId) !== null ? $productId : 0;
    }
}
