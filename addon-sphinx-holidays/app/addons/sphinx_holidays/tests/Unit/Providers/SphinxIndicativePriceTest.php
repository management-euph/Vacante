<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Providers;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Providers\SphinxIndicativePrice;
use Tygh\Addons\SphinxHolidays\Services\CalendarPriceBuilder;

/**
 * Sphinx's "de la X / noapte" on the hotel page: the cheapest stored stay
 * (calendar_prices cron: per check-in date, N nights, two adults, raw API
 * currency) priced for the store, then divided by N.
 */
final class SphinxIndicativePriceTest extends TestCase
{
    public function testCheapestStayPricedForTheStoreThenSplitIntoNights(): void
    {
        $calendar = json_encode([
            date('Y-m-d', strtotime('-2 days')) => 300.0,   // past: ignored
            date('Y-m-d', strtotime('+5 days')) => 840.0,
            date('Y-m-d', strtotime('+12 days')) => 700.0,
        ]);
        $priced = [];
        $source = new SphinxIndicativePrice(
            static fn (string $hotelId): string => $hotelId === '3612' ? (string) $calendar : '',
            static function (float $stay) use (&$priced): float {
                $priced[] = $stay;

                return $stay * 1.1; // commission + conversion stand-in
            },
            static fn (): int => 7,
        );

        self::assertSame('sphinx', $source->provider());
        self::assertEqualsWithDelta(110.0, $source->nightlyFromPrice('3612'), 0.0001);
        self::assertSame([700.0], $priced, 'only the cheapest stay is priced');
    }

    public function testTheCalendarShowsEachStayAsAPricePerNight(): void
    {
        $soon = date('Y-m-d', strtotime('+5 days'));
        $later = date('Y-m-d', strtotime('+12 days'));
        $source = new SphinxIndicativePrice(
            static fn (string $hotelId): string => (string) json_encode([$soon => 840.0, $later => 700.0, '2000-01-01' => 10.0]),
            static fn (float $stay): float => $stay * 1.1,
            static fn (): int => 7,
        );

        $calendar = $source->nightlyCalendar('3612');

        self::assertSame([$soon, $later], array_keys($calendar));
        self::assertEqualsWithDelta(132.0, $calendar[$soon], 0.0001);
        self::assertEqualsWithDelta(110.0, $calendar[$later], 0.0001);
    }

    public function testNoPriceWithoutAStoredCalendar(): void
    {
        $source = new SphinxIndicativePrice(
            static fn (string $hotelId): string => $hotelId === 'broken' ? '{not json' : '',
            static fn (float $stay): float => $stay,
            static fn (): int => 7,
        );

        self::assertNull($source->nightlyFromPrice('1'));
        self::assertNull($source->nightlyFromPrice('broken'));
        self::assertNull($source->nightlyFromPrice(''));
        self::assertSame([], $source->nightlyCalendar('broken'));
    }

    public function testNightsDefaultToTheCronDefaultUntilARunRecordsThem(): void
    {
        // No CS-Cart storage in unit tests: the cron's default applies.
        self::assertSame(CalendarPriceBuilder::DEFAULT_NIGHTS, SphinxIndicativePrice::storedNights());

        $cron = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Cron/Commands/CalendarPricesCommand.php');
        self::assertStringContainsString(
            'fn_set_storage_data(SphinxIndicativePrice::NIGHTS_STORAGE_KEY, (string) $nights);',
            $cron,
            'each calendar run records the nights its stays cover',
        );
    }
}
