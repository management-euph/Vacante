<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\Eurosite\Services\BookingStatusSchedule as Schedule;

/**
 * Eurosite's answer (28 Oct 2026) on how often to ask about a booking still
 * waiting for confirmation: 30 minutes after it was booked, then every 30
 * minutes for two hours; if still not final, 8 hours later, every 30 minutes
 * for two hours again.
 */
final class BookingStatusScheduleTest extends TestCase
{
    private const int BOOKED = 1_800_000_000;

    private static function at(int $minutes): int
    {
        return self::BOOKED + $minutes * 60;
    }

    public function testTheFirstRoundStartsHalfAnHourAfterTheBooking(): void
    {
        self::assertSame(Schedule::WAIT, Schedule::state(self::BOOKED, 0, self::at(10)));
        self::assertSame(Schedule::DUE, Schedule::state(self::BOOKED, 0, self::at(30)));
        self::assertSame(Schedule::WAIT, Schedule::state(self::BOOKED, self::at(30), self::at(45)), 'half an hour between checks');
        self::assertSame(Schedule::DUE, Schedule::state(self::BOOKED, self::at(30), self::at(60)));
        self::assertSame(Schedule::DUE, Schedule::state(self::BOOKED, self::at(120), self::at(150)), 'the round lasts two hours');
    }

    public function testEightHoursLaterASecondRoundThenOnlyStaffAsk(): void
    {
        self::assertSame(Schedule::WAIT, Schedule::state(self::BOOKED, self::at(150), self::at(300)), 'the 8-hour pause');
        self::assertSame(Schedule::DUE, Schedule::state(self::BOOKED, self::at(150), self::at(630)));
        self::assertSame(Schedule::DUE, Schedule::state(self::BOOKED, self::at(720), self::at(750)));
        self::assertSame(Schedule::DONE, Schedule::state(self::BOOKED, self::at(750), self::at(800)));
    }

    /** A run every 15 minutes may come a few minutes early or late: no check is skipped. */
    public function testCronTimingNeverSkipsACheck(): void
    {
        self::assertSame(Schedule::DUE, Schedule::state(self::BOOKED, 0, self::at(27)));
        self::assertSame(Schedule::DUE, Schedule::state(self::BOOKED, self::at(27), self::at(54)));
        self::assertSame(Schedule::DUE, Schedule::state(self::BOOKED, self::at(126), self::at(153)), 'the last check of a round, a little late');
    }
}
