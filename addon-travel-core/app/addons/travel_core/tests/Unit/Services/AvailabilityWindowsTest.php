<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Services\AvailabilityWindows;

/**
 * Which stays an availability check asks about (moved here from Eurosite's
 * AvailabilityPlanTest with the code).
 */
final class AvailabilityWindowsTest extends TestCase
{
    private static function today(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-09-24');
    }

    public function testNearDaysAreParsedSortedAndBounded(): void
    {
        self::assertSame([14, 30, 60], AvailabilityWindows::parseDays('60, 14,30'));
        self::assertSame([7, 30], AvailabilityWindows::parseDays('30;7 7 x -3 0 400'));
        self::assertSame([], AvailabilityWindows::parseDays(''));
    }

    /** "07-15" means the next 15 July; a full date only while it is ahead. */
    public function testSeasonDatesResolveToTheirNextOccurrence(): void
    {
        self::assertSame(
            ['2027-07-15', '2027-08-15'],
            AvailabilityWindows::parseSeasonDates('07-15, 08-15', self::today()),
            'both have passed this year, so next year',
        );
        self::assertSame(['2026-12-20', '2027-07-15'], AvailabilityWindows::parseSeasonDates('12-20 2027-07-15', self::today()));
        self::assertSame([], AvailabilityWindows::parseSeasonDates('2026-07-15, 2026-09-24', self::today()), 'past and today are dropped');
        self::assertSame([], AvailabilityWindows::parseSeasonDates('13-01, 02-30, July, 2027-02-30', self::today()), 'not dates');
        self::assertSame(['2028-02-29'], AvailabilityWindows::parseSeasonDates('2028-02-29', self::today()));
    }

    public function testWindowsAreNearFirstThenSeasonEachWithItsStayLength(): void
    {
        $w = AvailabilityWindows::windows(self::today(), [14, 30], '07-15', 7);

        self::assertSame([
            ['kind' => 'near', 'check_in' => '2026-10-08', 'check_out' => '2026-10-15'],
            ['kind' => 'near', 'check_in' => '2026-10-24', 'check_out' => '2026-10-31'],
            ['kind' => 'season', 'check_in' => '2027-07-15', 'check_out' => '2027-07-22'],
        ], $w);
    }

    public function testASeasonDateOnANearDateIsCheckedOnce(): void
    {
        $w = AvailabilityWindows::windows(self::today(), [14], '10-08', 3);

        self::assertCount(1, $w);
        self::assertSame('near', $w[0]['kind']);
        self::assertSame('2026-10-11', $w[0]['check_out']);
    }
}
