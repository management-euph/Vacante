<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\Eurosite\Services\DashboardSummary;

/**
 * The top of Eurosite's dashboard: each At a glance tile says whether its
 * figure is a problem, and Needs attention lists only what someone has to
 * act on, most serious first.
 */
#[CoversClass(DashboardSummary::class)]
final class DashboardSummaryTest extends TestCase
{
    private const int HOTELS_AT = 1_762_480_000;

    private const int CHECKED_AT = 1_762_570_000;

    /** @return array{countries: list<array<string, mixed>>, totals: array<string, int>} */
    private static function scope(): array
    {
        return [
            'countries' => [
                ['code' => 'GR', 'label' => 'Greece', 'mode' => 'all'],
                ['code' => 'BG', 'label' => '', 'mode' => 'own'],
                ['code' => 'TR', 'label' => 'Turkey', 'mode' => 'off'],
            ],
            'totals' => ['countries' => 2, 'cities' => 41, 'hotels' => 1_536, 'live' => 312],
        ];
    }

    /**
     * @param array<string, int> $availability
     *
     * @return array{listed: int, availability: array<string, int>, products: int, hidden: int, immediate_without: int}
     */
    private static function hotels(int $listed = 1_536, array $availability = ['IM' => 420, 'OR' => 300, 'ST' => 16, 'NONE' => 700, '' => 100]): array
    {
        return ['listed' => $listed, 'availability' => $availability, 'products' => 380, 'hidden' => 52, 'immediate_without' => 40];
    }

    /**
     * Stages as CronPlanBuilder::stages() gives them, from [mode, state, at, extra].
     *
     * @param list<array{0: string, 1: string, 2?: int, 3?: array<string, mixed>}> $jobs
     *
     * @return list<array{stage: string, number: int, title_key: string, jobs: list<array<string, mixed>>}>
     */
    private static function stages(array $jobs = []): array
    {
        $rows = [];
        foreach ($jobs ?: [['hotels', 'ok', self::HOTELS_AT], ['availability', 'ok', self::CHECKED_AT], ['cleanup', 'ok', self::CHECKED_AT]] as $job) {
            $rows[] = [
                'mode' => $job[0],
                'state' => $job[1],
                'at' => $job[2] ?? 0,
                'error' => '',
                'recommended' => false,
                'name_key' => 'eurosite.jobs_job_' . $job[0],
                'schedule_key' => 'travel_core.jobs_sched_daily',
                'schedule_params' => ['[time]' => '04:15'],
                ...($job[3] ?? []),
            ];
        }

        return [['stage' => 'catalogs', 'number' => 1, 'title_key' => 'eurosite.jobs_stage_catalogs', 'jobs' => $rows]];
    }

    public function testTheTilesSayWhatTheFiguresAre(): void
    {
        $g = DashboardSummary::glance(self::scope(), self::hotels(), self::stages());

        self::assertSame(['listed' => 1_536, 'where' => 'Greece, BG', 'cities' => 41, 'synced' => self::HOTELS_AT, 'warn' => false], $g['hotels']);
        self::assertSame(['immediate' => 420, 'on_request' => 300, 'stop_sale' => 16, 'no_offer' => 700, 'unchecked' => 100, 'checked' => self::CHECKED_AT, 'warn' => false], $g['offers']);
        self::assertSame(['linked' => 380, 'live' => 312, 'hidden' => 52, 'waiting' => 40], $g['products']);
        self::assertSame(['ok' => 3, 'late' => 0, 'failed' => 0, 'running' => 0, 'stalled' => 0, 'never' => 0], $g['jobs']['counts']);
        self::assertSame('ok', $g['jobs']['tone']);
    }

    /** No hotel listed: nothing can be sold until destinations are whitelisted and Hotels has run. */
    public function testNoHotelListedIsAProblem(): void
    {
        $g = DashboardSummary::glance(['countries' => [], 'totals' => []], self::hotels(0, []), self::stages([['hotels', 'never']]));

        self::assertSame(['listed' => 0, 'where' => '', 'cities' => 0, 'synced' => 0, 'warn' => true], $g['hotels']);
        self::assertFalse($g['offers']['warn'], 'no hotel to check is the hotels tile\'s problem, not the offers\'');
        self::assertSame(0, $g['offers']['checked']);
    }

    /**
     * Only a hotel with an Immediate offer becomes a product. None at all is
     * a problem once the check has looked at some; before it has, it is not.
     */
    public function testNoImmediateOfferIsAProblemOnceTheCheckHasLooked(): void
    {
        $checkedNone = self::hotels(200, ['IM' => 0, 'OR' => 50, 'ST' => 0, 'NONE' => 100, '' => 50]);
        self::assertTrue(DashboardSummary::glance(self::scope(), $checkedNone, self::stages())['offers']['warn']);
        self::assertContains('no_immediate', array_column(DashboardSummary::attention(self::stages(), 0, 5, $checkedNone), 'kind'));

        $notChecked = self::hotels(200, ['IM' => 0, '' => 200]);
        self::assertFalse(DashboardSummary::glance(self::scope(), $notChecked, self::stages())['offers']['warn']);
        self::assertSame([], DashboardSummary::attention(self::stages(), 0, 5, $notChecked));
    }

    public function testNothingWrongMeansNothingNeedsAttention(): void
    {
        self::assertSame([], DashboardSummary::attention(self::stages(), 0, 5, self::hotels()));
    }

    /**
     * Most serious first: a refused request (every scheduled run fails), no
     * destination whitelisted, the jobs (failed before late), then no
     * Immediate offer.
     */
    public function testAttentionComesMostSeriousFirst(): void
    {
        $stages = self::stages([
            ['hotels', 'late', self::HOTELS_AT],
            ['availability', 'failed', self::CHECKED_AT, ['error' => 'API down']],
            ['cleanup', 'ok', self::CHECKED_AT],
        ]);
        $items = DashboardSummary::attention($stages, 1_762_600_000, 0, self::hotels(10, ['IM' => 0, 'NONE' => 10]));

        self::assertSame(['refused', 'nothing_sold', 'failed', 'late', 'no_immediate'], array_column($items, 'kind'));
        self::assertSame(1_762_600_000, $items[0]['at']);
        self::assertSame(['availability', 'API down', 'eurosite.jobs_job_availability'], [$items[2]['mode'], $items[2]['error'], $items[2]['name_key']]);
        self::assertSame(['hotels', 'travel_core.jobs_sched_daily', ['[time]' => '04:15']], [$items[3]['mode'], $items[3]['schedule_key'], $items[3]['schedule_params']]);
        foreach ($items as $item) {
            self::assertSame(['kind', 'mode', 'at', 'error', 'n', 'name_key', 'schedule_key', 'schedule_params'], array_keys($item), 'one shape for the template');
        }
    }
}
