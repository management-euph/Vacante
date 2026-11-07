<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Helpers;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * A package's or a circuit's search result priced as the shopper sees it,
 * as the hotel search does (search.php, search_poll.php): the price and the
 * struck "before" price (marketing_price) both with the store's
 * commission, and the discount between them. The results pages used to
 * strike the supplier's own marketing price beside the commissioned price:
 * the "before" price could come out below the price itself.
 *
 * Only these store figures reach the template; the supplier's own (its
 * selling and marketing prices, supplier_price, commission, ...) stay on
 * the server. No struck price unless it is above the price.
 */
final class ResultPricing
{
    /**
     * @param array<string, mixed> $pricing the result's pricing, as Sphinx sent it
     * @param \Closure(float): float $withCommission the store's commission (CartService::applyCommission)
     *
     * @return array{selling_price: float, marketing_price: float, discount: float, currency: string}
     */
    public static function forShopper(array $pricing, \Closure $withCommission): array
    {
        $selling = TypeCoerce::toFloat($pricing['selling_price'] ?? 0);
        $marketing = TypeCoerce::toFloat($pricing['marketing_price'] ?? 0);
        $price = $selling > 0 ? $withCommission($selling) : 0.0;
        $before = $price > 0 && $marketing > $selling ? $withCommission($marketing) : 0.0;
        $discount = $before > $price ? round($before - $price, 2) : 0.0;

        return [
            'selling_price' => $price,
            'marketing_price' => $discount > 0 ? $before : 0.0,
            'discount' => $discount,
            'currency' => TypeCoerce::toString($pricing['currency'] ?? ''),
        ];
    }
}
