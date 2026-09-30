<?php

declare(strict_types=1);

/**
 * Currency Service Interface
 *
 * Contract for currency resolution and conversion between provider API
 * currencies and the CS-Cart display currency.
 *
 * @package TravelCore
 * @since   1.0.0
 */

namespace Tygh\Addons\TravelCore\Contracts;

interface CurrencyServiceInterface
{
    /**
     * Get the API currency this service was constructed with.
     */
    public function getApiCurrency(): string;

    /**
     * Convert a price from the API currency to a target currency.
     *
     * @param float $apiPrice Price from API (in api_currency)
     * @param string|null $targetCurrency Target currency code (null = display currency)
     * @return float Converted price
     */
    public function convertFromApiCurrency(float $apiPrice, ?string $targetCurrency = null): float;

    /**
     * What an API amount is multiplied by to show it in $currency (null =
     * display currency): coefficient(api) / coefficient(currency).
     */
    public function displayFactor(?string $currency = null): float;

    /**
     * Convert all price fields in a search results array from API currency to display currency.
     *
     * @param array<string, mixed> $results Search results array
     * @return array<string, mixed> Results with converted prices
     */
    public function convertResultsCurrency(array $results): array;
}
