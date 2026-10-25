<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Providers;

use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Addons\NovotonHolidays\Services\Container;
use Tygh\Addons\TravelCore\Contracts\IndicativePriceSourceInterface;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\IndicativeNightlyPrice;

/**
 * Novoton's indicative prices for the hotel page: its calendar prices
 * (PriceInfoService::getCalendarPrices — per date, the cheapest room's
 * one-night total for two adults, from the stored priceinfo), commission
 * included, in the store's primary currency. The title row's "from" price
 * is the lowest; the calendar shows them only while the store's
 * show_calendar_prices setting is on, as on the booking form.
 */
final class NovotonIndicativePrice implements IndicativePriceSourceInterface
{
    private const int ADULTS = 2;

    /** @var \Closure(string, string): array<array-key, mixed> hotel id, currency => getCalendarPrices() result */
    private readonly \Closure $calendar;

    /** @var \Closure(): bool */
    private readonly \Closure $showCalendar;

    /**
     * @param (\Closure(string, string): array<array-key, mixed>)|null $calendar tests inject the calendar
     * @param string|null $primaryCurrency tests pass it; null = CART_PRIMARY_CURRENCY
     * @param (\Closure(): bool)|null $showCalendar tests pass it; null = the show_calendar_prices setting
     */
    public function __construct(
        ?\Closure $calendar = null,
        private readonly ?string $primaryCurrency = null,
        ?\Closure $showCalendar = null,
    ) {
        $this->calendar = $calendar ?? static fn (string $hotelId, string $currency): array => Container::getInstance()
            ->priceInfoService()
            ->getCalendarPrices($hotelId, $currency, self::ADULTS);
        $this->showCalendar = $showCalendar ?? static fn (): bool => ConfigProvider::isShowCalendarPrices();
    }

    #[\Override]
    public function provider(): string
    {
        return 'novoton';
    }

    #[\Override]
    public function nightlyFromPrice(string $hotelId): ?float
    {
        $prices = $this->prices($hotelId);

        return $prices === [] ? null : min($prices);
    }

    #[\Override]
    public function nightlyCalendar(string $hotelId): array
    {
        return ($this->showCalendar)() ? $this->prices($hotelId) : [];
    }

    /** @return array<string, float> */
    private function prices(string $hotelId): array
    {
        $primary = $this->primaryCurrency
            ?? (defined('CART_PRIMARY_CURRENCY') ? TypeCoerce::toString(CART_PRIMARY_CURRENCY) : '');
        if ($hotelId === '' || $primary === '') {
            return [];
        }
        $calendar = ($this->calendar)($hotelId, $primary);
        $prices = $calendar['prices'] ?? null;

        return is_array($prices) ? IndicativeNightlyPrice::perNight($prices, date('Y-m-d')) : [];
    }
}
