<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Helpers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Helpers\SeasonDates;

/**
 * The season note on the "no availability" box read only the nested
 * seasons.season shape, so for the flat list the API sends it showed
 * nothing; and it showed the first package's first-to-last season, past
 * ones included.
 */
#[CoversClass(SeasonDates::class)]
final class SeasonDatesTest extends TestCase
{
    public function testReadsTheFlatListTheApiSends(): void
    {
        $priceinfo = ['seasons' => [
            ['Season' => '1', 'FromDate' => '2027-05-15', 'ToDate' => '2027-06-14'],
            ['Season' => '2', 'FromDate' => '2027-06-15', 'ToDate' => '2027-09-30'],
        ]];

        self::assertSame([
            ['from' => '2027-05-15', 'to' => '2027-06-14'],
            ['from' => '2027-06-15', 'to' => '2027-09-30'],
        ], SeasonDates::seasons($priceinfo));
    }

    public function testReadsTheNestedShapeAndASingleSeasonObject(): void
    {
        self::assertSame(
            [['from' => '2027-06-01', 'to' => '2027-08-31']],
            SeasonDates::seasons(['seasons' => ['season' => [['IdSeason' => '7', 'DateFrom' => '2027-06-01', 'DateTo' => '2027-08-31']]]]),
        );
        self::assertSame(
            [['from' => '2027-06-01', 'to' => '2027-08-31']],
            SeasonDates::seasons(['seasons' => ['season' => ['IdSeason' => '7', 'DateFrom' => '2027-06-01', 'DateTo' => '2027-08-31']]]),
        );
        self::assertSame(
            [['from' => '2027-06-01', 'to' => '2027-08-31']],
            SeasonDates::seasons(['seasons' => ['SeasonNr' => '1', 'FromDate' => '2027-06-01 00:00:00', 'ToDate' => '2027-08-31']]),
        );
    }

    public function testSkipsSeasonsWithoutAValidRange(): void
    {
        self::assertSame([], SeasonDates::seasons([]));
        self::assertSame([], SeasonDates::seasons(['seasons' => 'none']));
        self::assertSame([], SeasonDates::seasons(['seasons' => [
            ['Season' => '1', 'FromDate' => '', 'ToDate' => '2027-08-31'],
            ['Season' => '2', 'FromDate' => '31.08.2027', 'ToDate' => '2027-09-30'],
            ['Season' => '3', 'FromDate' => '2027-09-30', 'ToDate' => '2027-06-01'],
        ]]));
    }

    public function testTheWindowSpansEveryPackageAndIgnoresSeasonsThatAreOver(): void
    {
        $packages = [
            ['seasons' => [
                ['Season' => '1', 'FromDate' => '2026-05-15', 'ToDate' => '2026-09-30'],
                ['Season' => '2', 'FromDate' => '2027-06-01', 'ToDate' => '2027-09-15'],
            ]],
            ['seasons' => ['season' => [['IdSeason' => '1', 'DateFrom' => '2027-05-20', 'DateTo' => '2027-10-05']]]],
        ];

        self::assertSame(
            ['from' => '2027-05-20', 'to' => '2027-10-05'],
            SeasonDates::window($packages, '2026-10-16'),
            'October 2026: summer 2026 is over, the 2027 seasons of both packages count',
        );
        self::assertSame(
            ['from' => '2026-05-15', 'to' => '2027-10-05'],
            SeasonDates::window($packages, '2026-09-01'),
            'a season still running counts from its start',
        );
    }

    public function testNoWindowOnceEverySeasonIsOver(): void
    {
        $packages = [['seasons' => [['Season' => '1', 'FromDate' => '2026-05-15', 'ToDate' => '2026-09-30']]]];

        self::assertNull(SeasonDates::window($packages, '2026-10-16'), 'never offer last season as if it were bookable');
        self::assertNull(SeasonDates::window([], '2026-10-16'));
    }
}
