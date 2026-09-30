<?php

declare(strict_types=1);

/**
 * Travel Core Currency Service
 *
 * Handles currency resolution and conversion between provider API currencies
 * and the CS-Cart display currency. Provider-agnostic: the API currency is
 * passed as a constructor parameter rather than read from a specific addon.
 *
 * @package TravelCore
 * @since   1.0.0
 */

namespace Tygh\Addons\TravelCore\Services;

use Tygh\Addons\TravelCore\Contracts\CurrencyServiceInterface;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

class CurrencyService implements CurrencyServiceInterface
{
    /**
     * @param string $apiCurrency Currency code the API uses (e.g. 'EUR', 'USD')
     */
    public function __construct(
        private readonly string $apiCurrency = 'EUR',
    ) {
    }

    /**
     * Get the current display currency code.
     * Returns CART_SECONDARY_CURRENCY (user's selected) or CART_PRIMARY_CURRENCY.
     *
     * @return string Currency code (e.g. 'USD', 'EUR', 'RON')
     */
    public static function getDisplayCurrency(): string
    {
        if (defined('CART_SECONDARY_CURRENCY')) {
            return TypeCoerce::toString(CART_SECONDARY_CURRENCY);
        }
        if (defined('CART_PRIMARY_CURRENCY')) {
            return TypeCoerce::toString(CART_PRIMARY_CURRENCY);
        }
        return 'EUR';
    }

    /**
     * Get the API currency this service was constructed with.
     *
     * @return string Currency code
     */
    #[\Override]
    public function getApiCurrency(): string
    {
        return $this->apiCurrency;
    }

    /**
     * Convert a price from the API currency to a target currency.
     *
     * CS-Cart's convention (fn_format_price_by_currency): a currency's
     * coefficient is what ONE unit of it is worth in the PRIMARY currency
     * (primary = 1). On a USD store with EUR at 1.28, 1 EUR = 1.28 USD, so:
     *   amount_in_primary = amount * coefficient(source)
     *   amount_in_target  = amount_in_primary / coefficient(target)
     *
     * The reverse maths turned a 795 EUR offer into $621.09 (795 / 1.28)
     * instead of $1,017.60.
     *
     * @param float $apiPrice Price from API (in api_currency)
     * @param string|null $targetCurrency Target currency code (null = display currency)
     * @return float Converted price
     */
    #[\Override]
    public function convertFromApiCurrency(float $apiPrice, ?string $targetCurrency = null): float
    {
        return round($apiPrice * $this->factor($targetCurrency ?? self::getDisplayCurrency()), 2);
    }

    /**
     * What an API amount is multiplied by to show it in the display currency
     * (or $currency): coefficient(api) / coefficient(display). 1.0 when the
     * currencies are the same or a coefficient is unknown.
     *
     * Search cards, the booking form and its JS multiply raw API amounts by
     * this factor; it is the same conversion convertFromApiCurrency() applies.
     */
    #[\Override]
    public function displayFactor(?string $currency = null): float
    {
        return $this->factor($currency ?? self::getDisplayCurrency());
    }

    private function factor(string $target): float
    {
        $source = $this->apiCurrency;
        if ($source === $target) {
            return 1.0;
        }

        $sourceCoefficient = self::coefficient($source);
        $targetCoefficient = self::coefficient($target);
        if ($sourceCoefficient === null || $targetCoefficient === null) {
            return 1.0;
        }

        return $sourceCoefficient / $targetCoefficient;
    }

    /**
     * The store's coefficient for a currency; null when the currency is not
     * in the store or its coefficient is not positive.
     */
    private static function coefficient(string $code): ?float
    {
        $currencies = TravelCoreConfig::getCurrencies();
        if (empty($currencies) && function_exists('fn_get_currencies')) {
            $currencies = fn_get_currencies();
        }
        $row = TypeCoerce::toStringMap(TypeCoerce::toStringMap($currencies)[$code] ?? null);
        if (!isset($row['coefficient'])) {
            return null;
        }
        $coefficient = TypeCoerce::toFloat($row['coefficient']);

        return $coefficient > 0 ? $coefficient : null;
    }

    /**
     * Convert all price fields in a search results array from API currency to display currency.
     *
     * @param array<string, mixed> $results Search results array
     * @return array<string, mixed> Results with converted prices
     */
    #[\Override]
    public function convertResultsCurrency(array $results): array
    {
        $source = $this->apiCurrency;
        $display = self::getDisplayCurrency();
        if ($source === $display) {
            return $results;
        }

        foreach ($results as &$result) {
            if (!is_array($result)) {
                continue;
            }
            if (isset($result['total_price'])) {
                $result['total_price'] = $this->convertFromApiCurrency(TypeCoerce::toFloat($result['total_price']));
            }
            if (isset($result['price_per_night'])) {
                $result['price_per_night'] = $this->convertFromApiCurrency(TypeCoerce::toFloat($result['price_per_night']));
            }
        }
        unset($result);

        return $results;
    }
}
