<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Services;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * One money format for the shared booking page, for every provider.
 *
 * Before this, the three providers each printed their own total: sphinx and
 * eurosite a hard-coded "1.798,00 €" in the API currency (never converted),
 * novoton the header currency via its own coefficient maths. The shopper
 * could see EUR on the booking page and $ in the cart for the same stay.
 *
 * Contract: callers pass an amount in the store's PRIMARY currency — the very
 * number their add_to_cart puts on the cart line (stored_price=Y). This class
 * converts it to the currency the shopper selected in the header and formats
 * it with that currency's CS-Cart settings (symbol, position, decimals,
 * separators), so the booking page shows exactly what cart and checkout show.
 *
 * The conversion is CS-Cart's own (fn_format_price_by_currency) when the
 * framework is loaded — the coefficient direction is core's business, not
 * ours. Tests inject the converter.
 */
final class MoneyFormatter
{
    /** @var \Closure(float): float */
    private readonly \Closure $toDisplay;

    /**
     * @param array<string, mixed> $currency CS-Cart currency row of the display
     *                                       currency (symbol, after, decimals, decimals_separator, thousands_separator)
     * @param (\Closure(float): float)|null $toDisplay primary → display amount; null = identity
     * @param bool $roundPrices travel_core "round prices" setting: whole units only
     */
    public function __construct(
        private readonly array $currency,
        ?\Closure $toDisplay = null,
        private readonly bool $roundPrices = false,
    ) {
        $this->toDisplay = $toDisplay ?? static fn (float $amount): float => $amount;
    }

    /**
     * The storefront formatter: the shopper's selected currency, CS-Cart's
     * conversion, the travel_core rounding setting.
     */
    public static function forStore(): self
    {
        $primary = defined('CART_PRIMARY_CURRENCY') ? TypeCoerce::toString(CART_PRIMARY_CURRENCY) : '';
        $display = CurrencyService::getDisplayCurrency();
        $currencies = TravelCoreConfig::getCurrencies();
        $row = TypeCoerce::toStringMap($currencies[$display] ?? null);
        if ($row === []) {
            $row = ['currency_code' => $display, 'symbol' => $display, 'after' => 'Y'];
        }

        $toDisplay = null;
        if ($primary !== '' && $display !== $primary && function_exists('fn_format_price_by_currency')) {
            $toDisplay = static fn (float $amount): float => TypeCoerce::toFloat(
                fn_format_price_by_currency($amount, $primary, $display),
            );
        }

        return new self($row, $toDisplay, TravelCoreConfig::isRoundPrices());
    }

    /** "1.798,00 €" / "$1,798.00" — plain text, safe to escape. */
    public function format(float $primaryAmount): string
    {
        return $this->formatDisplay(($this->toDisplay)($primaryAmount));
    }

    /**
     * Format an amount that is already in the display currency (e.g. a
     * per-night share of a converted total).
     */
    public function formatDisplay(float $amount): string
    {
        $decimals = $this->roundPrices ? 0 : max(0, TypeCoerce::toInt($this->currency['decimals'] ?? 2));
        $number = number_format(
            $this->roundPrices ? round($amount) : $amount,
            $decimals,
            TypeCoerce::toString($this->currency['decimals_separator'] ?? '.') ?: '.',
            TypeCoerce::toString($this->currency['thousands_separator'] ?? ','),
        );
        $symbol = TypeCoerce::toString($this->currency['symbol'] ?? '');
        if ($symbol === '') {
            $symbol = TypeCoerce::toString($this->currency['currency_code'] ?? '');
        }
        if ($symbol === '') {
            return $number;
        }

        return TypeCoerce::toString($this->currency['after'] ?? 'N') === 'Y'
            ? $number . ' ' . $symbol
            : $symbol . $number;
    }

    /** The primary amount converted to the display currency (unformatted). */
    public function toDisplay(float $primaryAmount): float
    {
        return ($this->toDisplay)($primaryAmount);
    }
}
