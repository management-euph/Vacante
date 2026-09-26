<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Services\MoneyFormatter;

/**
 * The booking page shows the shopper's selected store currency, formatted
 * with that currency's CS-Cart settings — the same as cart and checkout.
 */
final class MoneyFormatterTest extends TestCase
{
    public function testSymbolAfterWithEuropeanSeparators(): void
    {
        $f = new MoneyFormatter(['symbol' => '€', 'after' => 'Y', 'decimals' => 2, 'decimals_separator' => ',', 'thousands_separator' => '.']);

        self::assertSame('1.798,00 €', $f->format(1798.0));
    }

    public function testSymbolBeforeAndConversionToTheSelectedCurrency(): void
    {
        $f = new MoneyFormatter(
            ['symbol' => '$', 'after' => 'N', 'decimals' => 2, 'decimals_separator' => '.', 'thousands_separator' => ','],
            static fn (float $eur): float => $eur * 1.1,
        );

        self::assertSame('$1,977.80', $f->format(1798.0));
        self::assertEqualsWithDelta(1977.8, $f->toDisplay(1798.0), 0.001);
        self::assertSame('$49.83', $f->formatDisplay(49.833));
    }

    public function testRoundPricesShowsWholeUnits(): void
    {
        $f = new MoneyFormatter(['symbol' => 'lei', 'after' => 'Y', 'decimals' => 2, 'decimals_separator' => ',', 'thousands_separator' => '.'], null, true);

        self::assertSame('1.799 lei', $f->format(1798.6));
    }

    public function testFallsBackToTheCurrencyCodeWithoutASymbol(): void
    {
        $f = new MoneyFormatter(['currency_code' => 'RON', 'after' => 'Y', 'decimals' => 0]);

        self::assertSame('673 RON', $f->format(673.0));
    }
}
