<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Dto;

/**
 * A normalized Eurosite hotel offer — one <Hotel>/<Offer> pair from a
 * getHotelPriceResponse, flattened into the fields the storefront and the
 * booking flow need. Immutable value object; EurositeApiClient::searchHotels()
 * returns a list of these so consumers never touch SimpleXMLElement.
 *
 * @phpstan-type OfferArray array{
 *   product_code: string, product_name: string, country_code: string,
 *   city_code: string, city_name: string, category: int, class: string,
 *   first_image: string, latitude: string, longitude: string,
 *   currency: string, offer_type: string, availability: string,
 *   availability_code: string,
 *   check_in: string, check_out: string, price: float, gross: float,
 *   net: float, commission: float, variant_id: string, series_id: string,
 *   grila: string,
 *   rooms: list<array<string, string>>, meals: list<array<string, string>>
 * }
 */
final class HotelOffer
{
    /**
     * @param list<array<string, string>> $rooms Room rows: code/gcode/name/quantity/price
     * @param list<array<string, string>> $meals Meal rows: type/code/name/price
     */
    public function __construct(
        public readonly string $productCode,
        public readonly string $productName,
        public readonly string $countryCode,
        public readonly string $cityCode,
        public readonly string $cityName,
        public readonly int $category,
        public readonly string $class,
        public readonly string $firstImage,
        public readonly string $latitude,
        public readonly string $longitude,
        public readonly string $currency,
        public readonly string $offerType,
        public readonly string $availability,
        public readonly string $checkIn,
        public readonly string $checkOut,
        public readonly float $price,
        public readonly float $gross,
        public readonly float $net,
        public readonly float $commission,
        public readonly string $variantId,
        public readonly string $grila,
        public readonly array $rooms = [],
        public readonly array $meals = [],
        public readonly string $seriesId = '',
        public readonly string $availabilityCode = '',
    ) {
    }

    /**
     * The spec's availability code (IM Immediate, OR On request, ST Stop sale)
     * from `<Availability Code="…">`, falling back to the element's text when
     * the attribute is missing. '' when neither says.
     */
    public static function normalizeAvailability(string $code, string $text): string
    {
        $code = strtoupper(trim($code));
        if (in_array($code, ['IM', 'OR', 'ST'], true)) {
            return $code;
        }
        $t = strtolower((string) preg_replace('/[^a-z]/i', '', $text));

        return match (true) {
            $t === 'immediate' => 'IM',
            $t === 'onrequest' => 'OR',
            str_starts_with($t, 'stop') => 'ST',
            default => '',
        };
    }

    /**
     * Whether a booking may be attempted. The spec: "do not allow booking
     * attempts on Stop Sale products; they are shown for information only."
     */
    public function isBookable(): bool
    {
        return $this->availabilityCode !== 'ST';
    }

    /**
     * @return OfferArray
     */
    public function toArray(): array
    {
        return [
            'product_code' => $this->productCode,
            'product_name' => $this->productName,
            'country_code' => $this->countryCode,
            'city_code' => $this->cityCode,
            'city_name' => $this->cityName,
            'category' => $this->category,
            'class' => $this->class,
            'first_image' => $this->firstImage,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'currency' => $this->currency,
            'offer_type' => $this->offerType,
            'availability' => $this->availability,
            'availability_code' => $this->availabilityCode,
            'check_in' => $this->checkIn,
            'check_out' => $this->checkOut,
            'price' => $this->price,
            'gross' => $this->gross,
            'net' => $this->net,
            'commission' => $this->commission,
            'variant_id' => $this->variantId,
            'series_id' => $this->seriesId,
            'grila' => $this->grila,
            'rooms' => $this->rooms,
            'meals' => $this->meals,
        ];
    }
}
