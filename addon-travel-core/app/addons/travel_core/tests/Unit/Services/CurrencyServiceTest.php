<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Services\CurrencyService;
use Tygh\Registry;

/**
 * CS-Cart's convention: a coefficient is what one unit is worth in the
 * primary currency. The dev store is USD primary with EUR at 1.28, and
 * Novoton prices come in EUR: a 795 EUR offer is $1,017.60. The inverse maths
 * showed it as $621.09 in the cart while the search card showed "795 $".
 */
#[CoversClass(CurrencyService::class)]
final class CurrencyServiceTest extends TestCase
{
    protected function setUp(): void
    {
        Registry::set('currencies', [
            'USD' => ['currency_code' => 'USD', 'coefficient' => '1.00000', 'is_primary' => 'Y'],
            'EUR' => ['currency_code' => 'EUR', 'coefficient' => '1.28000', 'is_primary' => 'N'],
            'GBP' => ['currency_code' => 'GBP', 'coefficient' => '1.34000', 'is_primary' => 'N'],
        ]);
    }

    protected function tearDown(): void
    {
        Registry::set('currencies', []);
    }

    public function testAnEurPriceBecomesUsdTheWayCsCartConvertsIt(): void
    {
        $service = new CurrencyService('EUR');

        self::assertSame(1017.6, $service->convertFromApiCurrency(795.0, 'USD'));
        self::assertSame(768.0, $service->convertFromApiCurrency(600.0, 'USD'));
    }

    public function testCrossCurrencyGoesThroughThePrimary(): void
    {
        // 795 EUR = 1017.60 USD = 1017.60 / 1.34 GBP.
        self::assertSame(759.4, (new CurrencyService('EUR'))->convertFromApiCurrency(795.0, 'GBP'));
    }

    public function testTheSameCurrencyIsUnchanged(): void
    {
        self::assertSame(795.0, (new CurrencyService('EUR'))->convertFromApiCurrency(795.0, 'EUR'));
    }

    public function testTheDisplayFactorIsTheSameConversion(): void
    {
        $service = new CurrencyService('EUR');

        self::assertSame(1.28, $service->displayFactor('USD'));
        self::assertSame(1.0, $service->displayFactor('EUR'));
        self::assertEqualsWithDelta(1.28 / 1.34, $service->displayFactor('GBP'), 1e-12);
        self::assertSame(
            $service->convertFromApiCurrency(795.0, 'USD'),
            round(795.0 * $service->displayFactor('USD'), 2),
            'search card and cart agree',
        );
    }

    public function testAnUnknownCurrencyLeavesTheAmountAlone(): void
    {
        $service = new CurrencyService('EUR');

        self::assertSame(795.0, $service->convertFromApiCurrency(795.0, 'CHF'));
        self::assertSame(1.0, $service->displayFactor('CHF'));
    }

    public function testNoCurrenciesLoadedLeavesTheAmountAlone(): void
    {
        Registry::set('currencies', []);

        self::assertSame(795.0, (new CurrencyService('EUR'))->convertFromApiCurrency(795.0, 'USD'));
    }
}
