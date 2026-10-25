<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Providers;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Providers\NovotonIndicativePrice;

/**
 * Novoton's "de la X / noapte" on the hotel page: the lowest of the hotel's
 * calendar prices (one night, two adults, commission and currency already
 * applied by PriceInfoService), asked in the store's primary currency.
 */
final class NovotonIndicativePriceTest extends TestCase
{
    public function testLowestCalendarPriceInThePrimaryCurrency(): void
    {
        $asked = [];
        $source = new NovotonIndicativePrice(
            static function (string $hotelId, string $currency) use (&$asked): array {
                $asked[] = [$hotelId, $currency];

                return ['prices' => [
                    date('Y-m-d', strtotime('+3 days')) => 104.5,
                    date('Y-m-d', strtotime('+10 days')) => 92.0,
                    date('Y-m-d', strtotime('+40 days')) => 140.0,
                ], 'currency' => $currency];
            },
            'EUR',
        );

        self::assertSame('novoton', $source->provider());
        self::assertSame(92.0, $source->nightlyFromPrice('1234'));
        self::assertSame([['1234', 'EUR']], $asked, 'asked once, in the primary currency, for that hotel');
    }

    public function testNoPriceWithoutCalendarOrHotel(): void
    {
        $empty = new NovotonIndicativePrice(static fn (string $hotelId, string $currency): array => ['prices' => [], 'currency' => $currency], 'EUR');
        self::assertNull($empty->nightlyFromPrice('1234'));

        $called = false;
        $guarded = new NovotonIndicativePrice(static function (string $hotelId, string $currency) use (&$called): array {
            $called = true;

            return ['prices' => ['2099-01-01' => 10.0]];
        }, 'EUR');
        self::assertNull($guarded->nightlyFromPrice(''));
        self::assertFalse($called, 'no hotel id, no lookup');

        $noCurrency = new NovotonIndicativePrice(static fn (string $hotelId, string $currency): array => ['prices' => ['2099-01-01' => 10.0]], '');
        self::assertNull($noCurrency->nightlyFromPrice('1234'), 'no primary currency known');
    }
}
