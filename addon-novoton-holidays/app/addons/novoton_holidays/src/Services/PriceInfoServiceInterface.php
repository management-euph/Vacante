<?php

declare(strict_types=1);

/**
 * PriceInfo Service Interface
 *
 * Contract for season-based price information for product tab display.
 * This is DIFFERENT from real-time booking prices (room_price,
 * PricingApiClient::getRoomPrice).
 *
 * @package NovotonHolidays
 * @since 3.5.0
 */

namespace Tygh\Addons\NovotonHolidays\Services;

interface PriceInfoServiceInterface
{
    /**
     * Get price info for hotel package (for product tab).
     *
     * @param string $hotelId Hotel ID
     * @param string $packageName Package name
     * @param string $lang Language
     * @return array<string, mixed>|null Parsed price info
     */
    public function getPriceInfo(string $hotelId, string $packageName, string $lang = 'UK'): ?array;

    /**
     * Get prices stored in database for product.
     *
     * @param int|string $productIdOrHotelId Product ID or Hotel ID
     * @param string|null $packageName Optional package name filter
     * @return array<string, mixed> Prices grouped by room
     */
    public function getStoredPrices(int|string $productIdOrHotelId, ?string $packageName = null): array;

    /**
     * Get last update time for product prices.
     *
     * @param int $productId Product ID
     * @return string|null Last update datetime
     */
    public function getLastUpdate(int $productId): ?string;

    /**
     * Get active package name for product.
     *
     * @param int $productId Product ID
     * @return string|null Package name
     */
    public function getActivePackage(int $productId): ?string;

    /**
     * Get seasons for hotel.
     *
     * @param string $hotelId Hotel ID
     * @return list<array<string, mixed>> Seasons with dates
     */
    public function getSeasons(string $hotelId): array;

    /**
     * Get early booking discounts for hotel.
     *
     * @param string $hotelId Hotel ID
     * @return list<array<string, mixed>> Early booking periods
     */
    public function getEarlyBooking(string $hotelId): array;

    /**
     * Get active early booking discount.
     *
     * @param string $hotelId Hotel ID
     * @param string|null $date Date to check (default: today)
     * @return array<string, mixed>|null Active discount or null
     */
    public function getActiveEarlyBooking(string $hotelId, ?string $date = null): ?array;

    /**
     * Get per-date calendar prices for a hotel.
     *
     * Returns the cheapest room's 1-night total for the given number
     * of adults, with commission and currency conversion applied.
     * Used for calendar price display on the product detail page.
     *
     * @param string $hotelId Hotel ID
     * @param string|null $targetCurrency Target currency code (null = display currency)
     * @param int $adults Number of adults (default 2)
     * @return array<string, mixed> ['prices' => [date => price], 'currency' => string]
     */
    public function getCalendarPrices(string $hotelId, ?string $targetCurrency = null, int $adults = 2): array;
}
