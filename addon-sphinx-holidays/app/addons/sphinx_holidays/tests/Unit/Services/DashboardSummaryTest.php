<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Services\DashboardSummary;
use Tygh\Addons\SphinxHolidays\Services\DestinationsPicker;

/**
 * The top of Sphinx's dashboard: the four At a glance tiles and Needs
 * attention, from the figures the controller gathers.
 */
final class DashboardSummaryTest extends TestCase
{
    private const array HOTELS = ['total' => 1536, 'linked' => 499, 'waiting' => 690, 'skipped' => 347];

    /**
     * WhitelistPageLoader::built() for Turkey sold and Greece not.
     *
     * @return array{configured: bool, countries: list<array<string, mixed>>, totals: array<string, int>}
     */
    private static function scope(int $hotels = 1536, int $live = 495): array
    {
        return [
            'configured' => true,
            'countries' => [
                ['code' => 'TR', 'label' => 'Turkey', 'mode' => 'selected'],
                ['code' => 'GR', 'label' => 'Greece', 'mode' => DestinationsPicker::MODE_OFF],
                ['code' => 'CY', 'label' => '', 'mode' => 'all'],
            ],
            'totals' => ['countries' => 2, 'cities' => 1, 'regions' => 1, 'hotels' => $hotels, 'live' => $live, 'new' => 0],
        ];
    }

    /**
     * Stages as CronPlan::stages() gives them.
     *
     * @param list<array<string, mixed>> $jobs
     *
     * @return list<array{stage: string, number: int, title_key: string, jobs: list<array<string, mixed>>}>
     */
    private static function stages(array $jobs): array
    {
        $rows = [];
        foreach ($jobs as $job) {
            $rows[] = $job + ['recommended' => false, 'at' => 0, 'error' => '', 'name_key' => 'sphinx_holidays.jobs_job_' . (string) $job['mode'], 'schedule_key' => 'travel_core.jobs_sched_daily', 'schedule_params' => ['[time]' => '03:00']];
        }

        return [['stage' => 'catalogue', 'number' => 1, 'title_key' => '', 'jobs' => $rows]];
    }

    public function testTheTilesFiguresComeFromTheHotelTableAndTheDestinationsSold(): void
    {
        $glance = DashboardSummary::glance(self::HOTELS, self::scope(), self::stages([['mode' => 'hotels', 'state' => 'ok']]));

        self::assertSame(['in_scope' => 1536, 'where' => 'Turkey, CY', 'cities' => 1, 'warn' => false], $glance['hotels'], 'a country not sold is left out; one with no name shows its code');
        self::assertSame(['linked' => 499, 'live' => 495], $glance['products']);
        self::assertSame(['count' => 1037, 'total' => 1536, 'waiting' => 690, 'skipped' => 347, 'ready' => 0, 'warn' => true], $glance['unlinked']);
        self::assertSame('ok', $glance['jobs']['tone']);
    }

    /** Waiting for an immediate offer is the gate working; only a skipped hotel needs someone. */
    public function testOnlySkippedHotelsMakeTheProductTileAProblem(): void
    {
        $unlinked = DashboardSummary::glance(['total' => 100, 'linked' => 40, 'waiting' => 50, 'skipped' => 0], self::scope(), [])['unlinked'];

        self::assertFalse($unlinked['warn']);
        self::assertSame(60, $unlinked['count']);
        self::assertSame(10, $unlinked['ready'], 'neither waiting nor skipped: Create products makes them');
    }

    public function testNoHotelInTheDestinationsSoldIsAProblem(): void
    {
        self::assertTrue(DashboardSummary::glance(self::HOTELS, self::scope(0, 0), [])['hotels']['warn']);
    }

    public function testFiguresNeverGoNegative(): void
    {
        $unlinked = DashboardSummary::glance(['total' => 10, 'linked' => 12, 'waiting' => 3, 'skipped' => 1], self::scope(), [])['unlinked'];

        self::assertSame(0, $unlinked['count']);
        self::assertSame(0, $unlinked['ready']);
    }

    public function testNothingToDoMeansNoAttention(): void
    {
        self::assertSame([], DashboardSummary::attention(self::stages([['mode' => 'hotels', 'state' => 'ok'], ['mode' => 'cleanup', 'state' => 'never']]), 0, 0));
    }

    /** A refused key stops every scheduled job, so it comes first; skipped hotels come last. */
    public function testAttentionListsTheRefusedKeyThenTheJobsThenSkippedHotels(): void
    {
        $items = DashboardSummary::attention(
            self::stages([
                ['mode' => 'destinations', 'state' => 'late', 'at' => 1_790_000_000, 'schedule_key' => 'travel_core.jobs_sched_weekly_sun', 'schedule_params' => ['[time]' => '02:00']],
                ['mode' => 'hotels', 'state' => 'failed', 'at' => 1_790_100_000, 'error' => 'API down'],
            ]),
            347,
            1_790_200_000,
        );

        self::assertSame(['refused', 'failed', 'late', 'skipped'], array_column($items, 'kind'));
        self::assertSame(1_790_200_000, $items[0]['at']);
        self::assertSame(['hotels', 'API down'], [$items[1]['mode'], $items[1]['error']]);
        self::assertSame('sphinx_holidays.jobs_job_hotels', $items[1]['name_key'], 'the line names the job by its label');
        self::assertSame('destinations', $items[2]['mode']);
        self::assertSame('travel_core.jobs_sched_weekly_sun', $items[2]['schedule_key'], '"is late" names the job\'s schedule');
        self::assertSame(['[time]' => '02:00'], $items[2]['schedule_params']);
        self::assertSame(347, $items[3]['n']);
    }

    public function testScopeCountriesAreTheSelectedOnesByName(): void
    {
        self::assertSame(
            [['code' => 'TR', 'name' => 'Turkey'], ['code' => 'CY', 'name' => 'CY'], ['code' => 'BG', 'name' => 'BG']],
            DashboardSummary::scopeCountries(['TR', 'CY', 'BG'], self::scope()['countries']),
        );
    }
}
