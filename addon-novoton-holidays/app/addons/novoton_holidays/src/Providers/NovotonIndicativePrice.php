<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Providers;

use Tygh\Addons\NovotonHolidays\Services\Container;
use Tygh\Addons\TravelCore\Contracts\IndicativePriceSourceInterface;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\IndicativeNightlyPrice;

/**
 * Novoton's "from" price for the hotel page title row: the lowest of the
 * hotel's calendar prices (PriceInfoService::getCalendarPrices — per date,
 * the cheapest room's one-night total for two adults, from the stored
 * priceinfo), commission included, in the store's primary currency.
 */
final class NovotonIndicativePrice implements IndicativePriceSourceInterface
{
    private const int ADULTS = 2;

    /** @var \Closure(string, string): array<array-key, mixed> hotel id, currency => getCalendarPrices() result */
    private readonly \Closure $calendar;

    /**
     * @param (\Closure(string, string): array<array-key, mixed>)|null $calendar tests inject the calendar
     * @param string|null $primaryCurrency tests pass it; null = CART_PRIMARY_CURRENCY
     */
    public function __construct(?\Closure $calendar = null, private readonly ?string $primaryCurrency = null)
    {
        $this->calendar = $calendar ?? static fn (string $hotelId, string $currency): array => Container::getInstance()
            ->priceInfoService()
            ->getCalendarPrices($hotelId, $currency, self::ADULTS);
    }

    #[\Override]
    public function provider(): string
    {
        return 'novoton';
    }

    #[\Override]
    public function nightlyFromPrice(string $hotelId): ?float
    {
        $primary = $this->primaryCurrency
            ?? (defined('CART_PRIMARY_CURRENCY') ? TypeCoerce::toString(CART_PRIMARY_CURRENCY) : '');
        if ($hotelId === '' || $primary === '') {
            return null;
        }
        $calendar = ($this->calendar)($hotelId, $primary);
        $prices = $calendar['prices'] ?? null;

        return is_array($prices) ? IndicativeNightlyPrice::lowest($prices, date('Y-m-d')) : null;
    }
}
