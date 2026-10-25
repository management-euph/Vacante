<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Providers;

use Tygh\Addons\SphinxHolidays\Services\CalendarPriceBuilder;
use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;
use Tygh\Addons\SphinxHolidays\Services\Container;
use Tygh\Addons\TravelCore\Contracts\IndicativePriceSourceInterface;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\CurrencyService;
use Tygh\Addons\TravelCore\Services\IndicativeNightlyPrice;

/**
 * Sphinx's indicative prices for the hotel page: the hotel's stored calendar
 * prices (the calendar_prices cron — per check-in date, the cheapest stay of
 * N nights for two adults, raw API currency), with commission, in the
 * store's primary currency, divided by N. The title row's "from" price is
 * the lowest.
 */
final class SphinxIndicativePrice implements IndicativePriceSourceInterface
{
    /** Where the calendar_prices cron records the nights its stays cover. */
    public const string NIGHTS_STORAGE_KEY = 'sphinx_calendar_prices_nights';

    /** @var \Closure(string): string hotel id => calendar_prices_raw JSON ('' when none) */
    private readonly \Closure $rawCalendar;

    /** @var \Closure(float): float raw stay total => the stay's store price (commission, primary currency) */
    private readonly \Closure $toStorePrice;

    /** @var \Closure(): int */
    private readonly \Closure $nights;

    /**
     * Tests inject the readers; the defaults read the store.
     *
     * @param (\Closure(string): string)|null $rawCalendar
     * @param (\Closure(float): float)|null $toStorePrice
     * @param (\Closure(): int)|null $nights
     */
    public function __construct(?\Closure $rawCalendar = null, ?\Closure $toStorePrice = null, ?\Closure $nights = null)
    {
        $this->rawCalendar = $rawCalendar
            ?? static fn (string $hotelId): string => Container::getHotelRepository()->getCalendarPricesRaw($hotelId);
        $this->toStorePrice = $toStorePrice ?? static function (float $stay): float {
            $primary = defined('CART_PRIMARY_CURRENCY') ? TypeCoerce::toString(CART_PRIMARY_CURRENCY) : 'EUR';

            return (new CurrencyService(ConfigProvider::getDefaultCurrency()))
                ->convertFromApiCurrency(Container::getCartService()->applyCommission($stay), $primary);
        };
        $this->nights = $nights ?? static fn (): int => self::storedNights();
    }

    #[\Override]
    public function provider(): string
    {
        return 'sphinx';
    }

    #[\Override]
    public function nightlyFromPrice(string $hotelId): ?float
    {
        $stays = $this->stays($hotelId);

        return $stays === [] ? null : ($this->toStorePrice)(min($stays)) / $this->nightCount();
    }

    #[\Override]
    public function nightlyCalendar(string $hotelId): array
    {
        $nights = $this->nightCount();
        $calendar = [];
        foreach ($this->stays($hotelId) as $date => $stay) {
            $calendar[$date] = ($this->toStorePrice)($stay) / $nights;
        }

        return $calendar;
    }

    /** @return array<string, float> check-in date => raw stay total, from today on */
    private function stays(string $hotelId): array
    {
        if ($hotelId === '') {
            return [];
        }
        $raw = json_decode(($this->rawCalendar)($hotelId), true);

        return is_array($raw) ? IndicativeNightlyPrice::perNight($raw, date('Y-m-d')) : [];
    }

    private function nightCount(): int
    {
        return max(1, ($this->nights)());
    }

    /** The nights the last calendar_prices run priced; the cron's default when unknown. */
    public static function storedNights(): int
    {
        $stored = function_exists('fn_get_storage_data')
            ? TypeCoerce::toInt(fn_get_storage_data(self::NIGHTS_STORAGE_KEY))
            : 0;

        return $stored > 0 ? $stored : CalendarPriceBuilder::DEFAULT_NIGHTS;
    }
}
