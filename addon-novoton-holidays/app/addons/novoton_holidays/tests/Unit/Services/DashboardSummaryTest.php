<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Services\DashboardSummary;

/**
 * The dashboard's top: the job-health tile, the "Needs attention" strip and
 * the excluded-resorts list.
 */
final class DashboardSummaryTest extends TestCase
{
    /** @param array<string, mixed> $extra */
    private static function job(string $mode, string $state, array $extra = []): array
    {
        return $extra + ['mode' => $mode, 'state' => $state, 'at' => 100, 'error' => '', 'recommended' => false];
    }

    /** The situation on the store today: hotel info and price info never ran, offers update is late. */
    private static function today(): array
    {
        return [
            ['stage' => 'hotels', 'jobs' => [
                self::job('hotel_list', 'ok'),
                self::job('hotel_info_batched', 'never', ['recommended' => true, 'at' => 0]),
                self::job('geocode_addresses', 'never', ['at' => 0]),
            ]],
            ['stage' => 'prices', 'jobs' => [
                self::job('sync_priceinfo_batched', 'never', ['recommended' => true, 'at' => 0]),
                self::job('room_price', 'ok'),
            ]],
            ['stage' => 'products', 'jobs' => [
                self::job('offers_update', 'late', ['at' => 555]),
            ]],
        ];
    }

    public function testTheStripListsTheRealProblemsMostSeriousFirst(): void
    {
        $items = DashboardSummary::attention(self::today(), ['total' => 1147, 'with_packages' => 0]);

        self::assertSame(['never', 'never', 'late'], array_column($items, 'kind'));
        self::assertSame(['hotel_info_batched', 'sync_priceinfo_batched', 'offers_update'], array_column($items, 'mode'));
        self::assertSame(555, $items[2]['at']);
        // The price-info item carries the season-price figures its text uses.
        self::assertSame(1147, $items[1]['hotels_total']);
        self::assertSame(0, $items[1]['hotels_with_season']);
    }

    /** Only the recommended jobs are reported as never run: a new store has sixteen of those. */
    public function testANonRecommendedJobThatNeverRanIsNotAProblem(): void
    {
        $modes = array_column(DashboardSummary::attention(self::today(), ['total' => 1147, 'with_packages' => 0]), 'mode');

        self::assertNotContains('geocode_addresses', $modes);
    }

    public function testFailuresAndStallsComeFirstWithTheirError(): void
    {
        $stages = self::today();
        $stages[2]['jobs'][] = self::job('cleanup', 'stalled');
        $stages[1]['jobs'][1] = self::job('room_price', 'failed', ['error' => 'API down']);

        $items = DashboardSummary::attention($stages, ['total' => 10, 'with_packages' => 5]);

        self::assertSame(['failed', 'stalled', 'never', 'never', 'late'], array_column($items, 'kind'));
        self::assertSame('API down', $items[0]['error']);
    }

    /** No season prices while the price job runs fine is its own problem; while that job never ran, its item says it. */
    public function testMissingSeasonPricesAreReportedOnce(): void
    {
        $stages = self::today();
        $stages[1]['jobs'][0] = self::job('sync_priceinfo_batched', 'ok', ['recommended' => true]);

        $kinds = array_column(DashboardSummary::attention($stages, ['total' => 1147, 'with_packages' => 0]), 'kind');
        self::assertSame(['never', 'late', 'no_season_prices'], $kinds);

        $kinds = array_column(DashboardSummary::attention(self::today(), ['total' => 1147, 'with_packages' => 0]), 'kind');
        self::assertNotContains('no_season_prices', $kinds);

        $kinds = array_column(DashboardSummary::attention($stages, ['total' => 1147, 'with_packages' => 3]), 'kind');
        self::assertNotContains('no_season_prices', $kinds);
        $kinds = array_column(DashboardSummary::attention($stages, ['total' => 0, 'with_packages' => 0]), 'kind');
        self::assertNotContains('no_season_prices', $kinds);
    }

    public function testAHealthyStoreNeedsNothing(): void
    {
        $stages = [['stage' => 'x', 'jobs' => [self::job('hotel_list', 'ok'), self::job('room_price', 'running')]]];

        self::assertSame([], DashboardSummary::attention($stages, ['total' => 5, 'with_packages' => 5]));
        self::assertSame('ok', DashboardSummary::jobHealth($stages)['tone']);
    }

    public function testTheJobTileCountsStatesAndPicksATone(): void
    {
        $health = DashboardSummary::jobHealth(self::today());

        self::assertSame(6, $health['jobs']);
        self::assertSame(2, $health['counts']['ok']);
        self::assertSame(3, $health['counts']['never']);
        self::assertSame(1, $health['counts']['late']);
        self::assertSame('warn', $health['tone']);

        $stages = self::today();
        $stages[0]['jobs'][0] = self::job('hotel_list', 'failed');
        self::assertSame('bad', DashboardSummary::jobHealth($stages)['tone']);
    }

    public function testResortsCarryTheirCountsAndExclusion(): void
    {
        $counts = [
            ['country' => 'ALBANIA', 'city' => 'DURRES', 'hotels' => 8, 'products' => 3],
            ['country' => 'BULGARIA', 'city' => 'ALBENA', 'hotels' => 38, 'products' => 0],
            ['country' => 'BULGARIA', 'city' => 'GIFT VOUCHER', 'hotels' => 1, 'products' => 0],
            ['country' => 'BULGARIA', 'city' => 'VARNA', 'hotels' => 12, 'products' => 3],
        ];

        $r = DashboardSummary::resorts($counts, ['GIFT VOUCHER'], ['varna']);

        self::assertSame(3, $r['resorts'], 'the hidden resort is left out');
        self::assertSame(1, $r['excluded'], 'exclusion matches regardless of case');
        self::assertSame(['BULGARIA', 'ALBANIA'], array_column($r['countries'], 'country'), 'the larger country first');

        $bg = $r['countries'][0];
        self::assertSame('Bulgaria', $bg['label']);
        self::assertSame(2, $bg['total']);
        self::assertSame(50, $bg['hotels']);
        self::assertSame(1, $bg['excluded']);
        self::assertSame(
            [['name' => 'ALBENA', 'label' => 'Albena', 'hotels' => 38, 'products' => 0, 'excluded' => false],
             ['name' => 'VARNA', 'label' => 'Varna', 'hotels' => 12, 'products' => 3, 'excluded' => true]],
            $bg['resorts'],
        );
    }

    public function testResortNamesReadAsNames(): void
    {
        self::assertSame('St. Constantine & Elena', DashboardSummary::displayName('ST.CONSTANTINE & ELENA'));
        self::assertSame('St. Vlas', DashboardSummary::displayName('ST. VLAS'));
        self::assertSame('Veliko Tarnovo', DashboardSummary::displayName('VELIKO TARNOVO'));
        self::assertSame('Sapareva Banya', DashboardSummary::displayName('SAPAREVA BANYA'));
        self::assertSame('Golden Sands', DashboardSummary::displayName(' golden sands '));
    }
}
