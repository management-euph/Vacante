<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\Eurosite\Services\CronPlanBuilder;

/**
 * Eurosite's answer (28 Oct 2026): the static catalogs once a month, the
 * hotel lists weekly. The nightly `full` runs each step only when due.
 */
final class CronPlanStepDueTest extends TestCase
{
    private static function at(string $when): int
    {
        return (int) strtotime($when);
    }

    public function testACatalogRunsOnceACalendarMonth(): void
    {
        $last = self::at('2026-10-01 01:05');

        self::assertFalse(CronPlanBuilder::stepDue('countries', $last, self::at('2026-10-31 01:00')));
        self::assertTrue(CronPlanBuilder::stepDue('countries', $last, self::at('2026-11-01 01:00')));
        self::assertTrue(CronPlanBuilder::stepDue('tags', self::at('2027-01-01 03:50'), self::at('2027-02-01 01:00')), 'a short month too');
    }

    public function testTheHotelListsRunWeekly(): void
    {
        $last = self::at('2026-10-25 01:20');

        self::assertFalse(CronPlanBuilder::stepDue('hotels', $last, self::at('2026-10-31 01:00')));
        self::assertTrue(CronPlanBuilder::stepDue('hotels', $last, self::at('2026-11-01 01:00')), 'a week later, although it ended at 01:20');
    }

    public function testAStepThatNeverFinishedWellIsDue(): void
    {
        self::assertTrue(CronPlanBuilder::stepDue('cities', null, self::at('2026-10-31 01:00')));
        self::assertTrue(CronPlanBuilder::stepDue('hotels', 0, self::at('2026-10-31 01:00')));
    }
}
