<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Services\FullPassSchedule;

/**
 * Novoton's answers of 28 Oct 2026: hotel details every 3 days in February–May
 * and every 7 days otherwise, season prices once a month, from 05:00 Romanian
 * time, never in their busiest hour (03:00–04:00).
 */
final class FullPassScheduleTest extends TestCase
{
    private string $zone;

    protected function setUp(): void
    {
        $this->zone = date_default_timezone_get();
        date_default_timezone_set('Europe/Bucharest');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->zone);
    }

    private static function at(string $local): int
    {
        return (int) strtotime($local);
    }

    public function testEveryThreeDaysWhileNovotonLoadsTheNewSeason(): void
    {
        foreach (['2027-01-31', '2027-06-01', '2026-10-28', '2026-12-15'] as $day) {
            self::assertSame(7, FullPassSchedule::hotelInfoDays(self::at($day . ' 10:00'), 3, 7), $day);
        }
        foreach (['2027-02-01', '2027-03-15', '2027-05-31'] as $day) {
            self::assertSame(3, FullPassSchedule::hotelInfoDays(self::at($day . ' 10:00'), 3, 7), $day);
        }
    }

    public function testAPassIsDueFromFiveOnTheDayItsIntervalEnds(): void
    {
        $finished = self::at('2027-03-01 05:20');

        self::assertFalse(FullPassSchedule::due($finished, 3, self::at('2027-03-04 04:59')));
        self::assertTrue(FullPassSchedule::due($finished, 3, self::at('2027-03-04 05:00')));
        self::assertTrue(FullPassSchedule::due($finished, 3, self::at('2027-03-04 17:00')), 'still due later that day');
    }

    /** A first pass, or a manual one, in the afternoon: the next goes back to the morning. */
    public function testAnAfternoonPassMovesTheNextToTheMorning(): void
    {
        self::assertSame('2027-03-04 05:00', date('Y-m-d H:i', FullPassSchedule::nextAt(self::at('2027-03-01 17:10'), 3)));
    }

    public function testNothingStartsInNovotonsBusiestHour(): void
    {
        self::assertFalse(FullPassSchedule::due(null, 7, self::at('2026-11-10 03:30')), 'not even the first pass');
        self::assertFalse(FullPassSchedule::due(self::at('2026-10-01 05:20'), 7, self::at('2026-11-10 03:59')), 'not even one long overdue');
        self::assertTrue(FullPassSchedule::due(null, 7, self::at('2026-11-10 04:00')));
    }

    /** Romania leaves summer time on the last Sunday of October: 05:00 stays 05:00. */
    public function testFiveInTheMorningAcrossTheClockChange(): void
    {
        self::assertSame('2026-10-30 05:00', date('Y-m-d H:i', FullPassSchedule::nextAt(self::at('2026-10-23 05:20'), 7)));
    }

    public function testSeasonPricesOnceAMonth(): void
    {
        $finished = self::at('2026-10-10 05:40');

        self::assertFalse(FullPassSchedule::due($finished, 30, self::at('2026-11-02 10:00')), 'a week later: no more weekly passes');
        self::assertTrue(FullPassSchedule::due($finished, 30, self::at('2026-11-09 05:05')));
    }
}
