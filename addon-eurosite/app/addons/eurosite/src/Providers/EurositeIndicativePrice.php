<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Providers;

use Tygh\Addons\Eurosite\Repository\HotelRepository;
use Tygh\Addons\Eurosite\Services\ConfigProvider;
use Tygh\Addons\Eurosite\Services\EurositeProductFactory;
use Tygh\Addons\TravelCore\Contracts\IndicativePriceSourceInterface;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * Eurosite's "from" price for the hotel page title row: the hotel's
 * min_price from the availability check (the cheapest offer for one double
 * room, two adults, `availability_nights` nights, on near and peak dates —
 * AvailabilityPlan), in the store's primary currency, divided by those
 * nights. Only while that check found something bookable (immediate or on
 * request): a stop sale or no offer keeps the page without a price.
 */
final class EurositeIndicativePrice implements IndicativePriceSourceInterface
{
    private const array BOOKABLE = ['IM', 'OR'];

    /** @var \Closure(string): (array<string, mixed>|null) product code => eurosite_hotels row */
    private readonly \Closure $hotel;

    /** @var \Closure(string): float currency code => CS-Cart coefficient (0 when unknown) */
    private readonly \Closure $coefficient;

    /** @var \Closure(): int */
    private readonly \Closure $nights;

    /**
     * Tests inject the readers; the defaults read the store.
     *
     * @param (\Closure(string): (array<string, mixed>|null))|null $hotel
     * @param (\Closure(string): float)|null $coefficient
     * @param (\Closure(): int)|null $nights
     */
    public function __construct(?\Closure $hotel = null, ?\Closure $coefficient = null, ?\Closure $nights = null)
    {
        $this->hotel = $hotel ?? static fn (string $productCode): ?array => (new HotelRepository())->findByProductCode($productCode);
        $this->coefficient = $coefficient ?? static fn (string $currency): float => TypeCoerce::toFloat(db_get_field(
            'SELECT coefficient FROM ?:currencies WHERE currency_code = ?s',
            $currency,
        ));
        $this->nights = $nights ?? static fn (): int => ConfigProvider::getAvailabilityNights();
    }

    #[\Override]
    public function provider(): string
    {
        return 'eurosite';
    }

    #[\Override]
    public function nightlyFromPrice(string $hotelId): ?float
    {
        $row = $hotelId === '' ? null : ($this->hotel)($hotelId);
        if ($row === null || !in_array(TypeCoerce::toString($row['availability'] ?? ''), self::BOOKABLE, true)) {
            return null;
        }
        $stay = TypeCoerce::toFloat($row['min_price'] ?? 0);
        if ($stay <= 0) {
            return null;
        }
        $currency = TypeCoerce::toString($row['price_currency'] ?? '') ?: ConfigProvider::getDefaultCurrency();

        return EurositeProductFactory::toStorePrice($stay, ($this->coefficient)($currency)) / max(1, ($this->nights)());
    }

    /** Eurosite stores one price per hotel, not one per date: no calendar prices. */
    #[\Override]
    public function nightlyCalendar(string $hotelId): array
    {
        return [];
    }
}
