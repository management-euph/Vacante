<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\ProviderCartProduct;

/**
 * The CS-Cart product a Novoton booking goes on: the hotel's own product —
 * ?:novoton_hotels.product_id, or the product whose code is a configured
 * Novoton prefix + the hotel id (NVT123) — and active (Novoton has no
 * availability gate). Never simply the product_id the browser sent: that is
 * accepted only when it is one of the hotel's own products
 * (ProviderCartProduct).
 */
final class HotelCartProduct
{
    public static function resolve(string $hotelId, int $requestedProductId = 0): int
    {
        $owned = self::ownedProductIds($hotelId);
        // The product page the guest came from, when it is one of the hotel's own.
        $productId = in_array($requestedProductId, $owned, true) ? $requestedProductId : ($owned[0] ?? 0);

        return ProviderCartProduct::resolve(
            max(0, $requestedProductId),
            $productId,
            ProviderCartProduct::productStatus(...),
        );
    }

    /**
     * The hotel's products in Novoton's own records: the linked one first.
     *
     * @return list<int>
     */
    public static function ownedProductIds(string $hotelId): array
    {
        // Novoton hotel ids are numeric (novoton_hotels.hotel_id is an INT).
        if ($hotelId === '' || !ctype_digit($hotelId)) {
            return [];
        }
        $ids = [TypeCoerce::toInt(db_get_field('SELECT product_id FROM ?:novoton_hotels WHERE hotel_id = ?i', (int) $hotelId))];
        $codes = array_map(
            static fn (string $prefix): string => $prefix . $hotelId,
            array_values(array_filter(ConfigProvider::getProductCodePrefixes(), static fn (string $p): bool => $p !== '')),
        );
        if ($codes !== []) {
            foreach (TypeCoerce::toIntList(db_get_fields('SELECT product_id FROM ?:products WHERE product_code IN (?a) ORDER BY product_id', $codes)) as $id) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
    }
}
