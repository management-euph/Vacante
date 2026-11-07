<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Helpers;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Helpers\ResultPricing;

/**
 * The package and circuit results pages struck the supplier's own
 * marketing price beside the commissioned price: with a 15 % commission a
 * 927 € offer "reduced from" 1 030 € read "1.030,00 ~~ 1.066,05 EUR" — the
 * price before the discount below the price. Both now carry the store's
 * commission, as in the hotel search.
 */
final class ResultPricingTest extends TestCase
{
    /** @return \Closure(float): float */
    private static function commission(float $rate): \Closure
    {
        return static fn (float $price): float => round($price * (1 + $rate), 2);
    }

    public function testTheStruckPriceCarriesTheSameCommissionAsThePrice(): void
    {
        $pricing = ResultPricing::forShopper(
            ['marketing_price' => 1030.0, 'discount' => 103.0, 'selling_price' => 927.0, 'currency' => 'EUR'],
            self::commission(0.15),
        );

        self::assertSame(1066.05, $pricing['selling_price']);
        self::assertSame(1184.5, $pricing['marketing_price']);
        self::assertSame(118.45, $pricing['discount'], 'the discount between the two store prices');
        self::assertGreaterThan($pricing['selling_price'], $pricing['marketing_price']);
    }

    /** Only the store's figures reach the template: no supplier price, commission or "original" price. */
    public function testOnlyTheStoresFiguresAreKept(): void
    {
        $pricing = ResultPricing::forShopper(
            ['marketing_price' => 900.0, 'discount' => 0, 'selling_price' => 900.0, 'supplier_price' => 810.0, 'commission' => 90.0, 'currency' => 'EUR'],
            self::commission(0.1),
        );

        self::assertSame(['selling_price' => 990.0, 'marketing_price' => 0.0, 'discount' => 0.0, 'currency' => 'EUR'], $pricing);
    }

    public function testNoStruckPriceUnlessItIsAboveThePrice(): void
    {
        $rounded = static fn (float $price): float => round($price * 1.1);

        self::assertSame(0.0, ResultPricing::forShopper(['selling_price' => 500.0], self::commission(0.1))['marketing_price'], 'no marketing price');
        self::assertSame(0.0, ResultPricing::forShopper(['selling_price' => 500.0, 'marketing_price' => 480.0], self::commission(0.1))['marketing_price'], 'below the price');
        self::assertSame(
            [110.0, 0.0, 0.0],
            array_values(array_intersect_key(
                ResultPricing::forShopper(['selling_price' => 100.0, 'marketing_price' => 100.3], $rounded),
                ['selling_price' => 0, 'marketing_price' => 0, 'discount' => 0],
            )),
            'equal once the store rounds its prices',
        );
    }

    public function testAResultWithoutAPriceShowsNone(): void
    {
        self::assertSame(
            ['selling_price' => 0.0, 'marketing_price' => 0.0, 'discount' => 0.0, 'currency' => ''],
            ResultPricing::forShopper(['marketing_price' => 1030.0], self::commission(0.1)),
        );
    }
}
