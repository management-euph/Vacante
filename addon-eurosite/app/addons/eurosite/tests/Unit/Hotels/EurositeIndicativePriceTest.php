<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Tests\Unit\Hotels;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\Eurosite\Providers\EurositeIndicativePrice;

/**
 * Eurosite's "de la X / noapte" on the hotel page: the hotel's min_price
 * from the availability check (one double room, two adults,
 * availability_nights nights) in the store's currency, split into nights —
 * only while the check found something bookable.
 */
final class EurositeIndicativePriceTest extends TestCase
{
    /** @param array<string, array<string, mixed>> $rows */
    private static function source(array $rows): EurositeIndicativePrice
    {
        return new EurositeIndicativePrice(
            static fn (string $code): ?array => $rows[$code] ?? null,
            static fn (string $currency): float => ['EUR' => 1.0, 'RON' => 0.2][$currency] ?? 0.0,
            static fn (): int => 7,
        );
    }

    public function testMinPriceInTheStoreCurrencySplitIntoNights(): void
    {
        $source = self::source([
            'H1' => ['availability' => 'IM', 'min_price' => 644.0, 'price_currency' => 'EUR'],
            'H2' => ['availability' => 'OR', 'min_price' => 3500.0, 'price_currency' => 'RON'],
        ]);

        self::assertSame('eurosite', $source->provider());
        self::assertEqualsWithDelta(92.0, $source->nightlyFromPrice('H1'), 0.0001);
        self::assertEqualsWithDelta(100.0, $source->nightlyFromPrice('H2'), 0.0001, 'RON at 0.2 per primary unit');
    }

    public function testNoPriceUnlessTheLastCheckFoundSomethingBookable(): void
    {
        $source = self::source([
            'STOP' => ['availability' => 'ST', 'min_price' => 500.0, 'price_currency' => 'EUR'],
            'NONE' => ['availability' => 'NONE', 'min_price' => 0, 'price_currency' => 'EUR'],
            'FREE' => ['availability' => 'IM', 'min_price' => 0, 'price_currency' => 'EUR'],
        ]);

        self::assertNull($source->nightlyFromPrice('STOP'));
        self::assertNull($source->nightlyFromPrice('NONE'));
        self::assertNull($source->nightlyFromPrice('FREE'));
        self::assertNull($source->nightlyFromPrice('MISSING'));
        self::assertNull($source->nightlyFromPrice(''));
    }

    public function testNoCalendarPrices(): void
    {
        $source = self::source(['H1' => ['availability' => 'IM', 'min_price' => 644.0, 'price_currency' => 'EUR']]);

        self::assertSame([], $source->nightlyCalendar('H1'), 'one price per hotel, not per date');
    }
}
