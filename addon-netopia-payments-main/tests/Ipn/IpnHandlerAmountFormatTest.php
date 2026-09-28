<?php

declare(strict_types=1);

namespace Netopia\CsCart\Tests\Ipn;

use Netopia\CsCart\Ipn\IpnHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(IpnHandler::class)]
final class IpnHandlerAmountFormatTest extends TestCase
{
    public function testFormatsAmountWithRomanianDecimalSeparatorAndCurrency(): void
    {
        self::assertSame('330,91 RON', IpnHandler::formatAmount(330.91, 'RON'));
    }

    public function testFormatsAmountWithThousandsSeparator(): void
    {
        self::assertSame('1.234,56 EUR', IpnHandler::formatAmount(1234.56, 'EUR'));
    }

    public function testFormatsAmountWithoutCurrencyWhenMissing(): void
    {
        self::assertSame('99,00', IpnHandler::formatAmount(99.0, ''));
    }

    public function testRoundsToTwoDecimalPlaces(): void
    {
        self::assertSame('1,00 RON', IpnHandler::formatAmount(0.999, 'RON'));
    }

    public function testParseFormattedAmountRoundTripsValueAndCurrency(): void
    {
        $parsed = IpnHandler::parseFormattedAmount('2.698,67 RON');

        self::assertSame(2698.67, $parsed['value']);
        self::assertSame('RON', $parsed['currency']);
    }

    public function testParseFormattedAmountReturnsEmptyCurrencyWhenSuffixMissing(): void
    {
        $parsed = IpnHandler::parseFormattedAmount('150,00');

        self::assertSame(150.0, $parsed['value']);
        self::assertSame('', $parsed['currency']);
    }

    public function testParseFormattedAmountIsRobustAgainstExtraWhitespace(): void
    {
        $parsed = IpnHandler::parseFormattedAmount('  1.234,56 EUR  ');

        self::assertSame(1234.56, $parsed['value']);
        self::assertSame('EUR', $parsed['currency']);
    }
}
