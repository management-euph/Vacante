<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Providers;

use Tygh\Addons\Eurosite\Repository\HotelRepository;
use Tygh\Addons\Eurosite\Services\EurositeProductFactory;
use Tygh\Addons\TravelCore\Contracts\HotelProductProviderInterface;
use Tygh\Addons\TravelCore\Dto\Hotel\HotelSeoData;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Eurosite implementation of HotelProductProviderInterface.
 *
 * A product is Eurosite's when its code is EUS-<tour op>-<hotel code> (see
 * EurositeProductFactory) AND a hotel row links to it. The code test runs
 * first, so every other provider's product costs no query here.
 *
 * Owning a product is what puts travel_core's booking form on its page; that
 * form searches `eurosite_booking.search` with the hotel_id this returns
 * (the Eurosite hotel code): the search of that one hotel.
 */
final class EurositeHotelProductProvider implements HotelProductProviderInterface
{
    private HotelRepository $hotels;

    public function __construct(?HotelRepository $hotels = null)
    {
        $this->hotels = $hotels ?? new HotelRepository();
    }

    #[\Override]
    public function resolveProduct(int $productId, string $productCode): ?HotelSeoData
    {
        if ($productId <= 0 || EurositeProductFactory::parseProductCode($productCode) === null) {
            return null;
        }
        try {
            $hotel = $this->hotels->findByProductId($productId);
        } catch (\Throwable) {
            return null;
        }
        if ($hotel === null) {
            return null;
        }
        $stars = TypeCoerce::toInt($hotel['category'] ?? 0);
        $pictures = EurositeProductFactory::pictures($hotel);

        return new HotelSeoData(
            hotelId: TypeCoerce::toString($hotel['product_code'] ?? ''),
            providerName: 'eurosite',
            name: EurositeProductFactory::displayName(TypeCoerce::toString($hotel['name'] ?? '')),
            classification: $stars > 0 ? $stars : null,
            propertyType: 'hotel',
            city: self::nullable(TypeCoerce::toString($hotel['city_name'] ?? '')),
            country: self::nullable(TypeCoerce::toString($hotel['country_name'] ?? '')),
            imageUrl: $pictures[0] ?? null,
        );
    }

    #[\Override]
    public function ownsHotelId(string $hotelId): bool
    {
        try {
            return $this->hotels->findByProductCode(TypeCoerce::toString($hotelId)) !== null;
        } catch (\Throwable) {
            return false; // contract: never throw from ownership probes
        }
    }

    #[\Override]
    public function productIdForHotelId(string $hotelId): ?int
    {
        try {
            $hotel = $this->hotels->findByProductCode(TypeCoerce::toString($hotelId));
        } catch (\Throwable) {
            return null;
        }
        $productId = $hotel !== null ? TypeCoerce::toInt($hotel['product_id'] ?? 0) : 0;

        return $productId > 0 ? $productId : null;
    }

    private static function nullable(string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}
