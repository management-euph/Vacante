<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\ViewModels;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\ViewModels\HotelListView;

/**
 * Novoton → Hotels: a listing row as the page prints it.
 */
final class HotelListViewTest extends TestCase
{
    private const string NOW = '2026-11-13 12:00:00';

    public function testARowIsReadForThePage(): void
    {
        $rows = HotelListView::rows([[
            'hotel_id' => 'A 12',
            'hotel_name' => 'Example Beach',
            'city' => 'Sunny Beach',
            'region' => 'Burgas',
            'country' => 'Bulgaria',
            'hotel_type' => '4*',
            'star_rating' => '4',
            'product_id' => '1500',
            'live_product_id' => '1500',
            'product_status' => 'D',
            'hotelinfo_synced_at' => '2026-11-02 04:31:00',
            'hotelinfo_tried_at' => '2026-11-02 04:30:00',
            'hotelinfo_due_at' => null,
            'sync_status' => 'active',
        ]], self::NOW);

        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertSame('A 12', $row['id']);
        self::assertSame('A%2012', $row['id_url']);
        self::assertSame('Burgas, Bulgaria', $row['area']);
        self::assertSame(4, $row['stars']);
        self::assertSame('★★★★', $row['stars_text']);
        self::assertSame('02.11.2026 04:31', $row['synced_at']);
        self::assertSame(11, $row['synced_days']);
        self::assertSame('', $row['tried_at'], 'shown only for a request that got no answer');
        self::assertFalse($row['queued']);
        self::assertSame(1500, $row['product_id']);
        self::assertSame('D', $row['product_status']);
        self::assertFalse($row['product_deleted']);
    }

    public function testAFailedRequestShowsWhenItWasTried(): void
    {
        $row = HotelListView::rows([[
            'hotel_id' => '476',
            'hotelinfo_synced_at' => '2026-11-09 04:20:00',
            'hotelinfo_tried_at' => '2026-11-13 04:26:00',
            'sync_status' => 'error',
        ]], self::NOW)[0];

        self::assertSame('error', $row['status']);
        self::assertSame('13.11.2026 04:26', $row['tried_at']);
    }

    public function testAHotelNeverSyncedOrQueuedOrWithoutItsProduct(): void
    {
        $never = HotelListView::rows([['hotel_id' => '1', 'sync_status' => 'pending']], self::NOW)[0];
        self::assertSame('', $never['synced_at']);
        self::assertNull($never['synced_days']);
        self::assertFalse($never['queued'], 'never synced is not the same as queued');

        $queued = HotelListView::rows([['hotel_id' => '2', 'hotelinfo_synced_at' => '2026-11-10 04:00:00', 'hotelinfo_due_at' => '2026-11-13 11:00:00', 'sync_status' => 'pending']], self::NOW)[0];
        self::assertTrue($queued['queued']);

        $deleted = HotelListView::rows([['hotel_id' => '3', 'product_id' => '77', 'live_product_id' => null, 'sync_status' => 'unmapped']], self::NOW)[0];
        self::assertSame(0, $deleted['product_id']);
        self::assertTrue($deleted['product_deleted']);

        $unknown = HotelListView::rows([['hotel_id' => '4', 'sync_status' => 'weird', 'star_rating' => 9]], self::NOW)[0];
        self::assertSame('pending', $unknown['status']);
        self::assertSame(0, $unknown['stars']);
        self::assertSame('', $unknown['stars_text']);
    }

    public function testTheAreaDoesNotRepeatTheCityOrAnEmptyPart(): void
    {
        self::assertSame('Bulgaria', HotelListView::area('Bansko', 'Bulgaria', 'Bansko'));
        self::assertSame('Varna', HotelListView::area('Varna', '', 'Golden Sands'));
        self::assertSame('', HotelListView::area('', '', 'Albena'));
        self::assertSame('Bulgaria', HotelListView::area('Bulgaria', 'Bulgaria', 'Sofia'));
    }

    public function testDaysAgoCountsWholeDays(): void
    {
        self::assertSame(0, HotelListView::daysAgo('2026-11-13 04:00:00', self::NOW));
        self::assertSame(1, HotelListView::daysAgo('2026-11-12 11:59:00', self::NOW));
        self::assertSame(0, HotelListView::daysAgo('2026-11-14 00:00:00', self::NOW), 'a clock a little ahead is not negative');
        self::assertNull(HotelListView::daysAgo('', self::NOW));
        self::assertSame('', HotelListView::when(''));
        self::assertSame('', HotelListView::when('0000-00-00 00:00:00'));
    }

    public function testAListUrlCarriesOnlyTheFiltersInForce(): void
    {
        $search = ['q' => 'Beach', 'country' => '', 'stars' => 0, 'sync_status' => 'error', 'sort_by' => 'synced', 'sort_order' => 'asc', 'page' => 3];

        self::assertSame('q=Beach&sync_status=error', HotelListView::query($search));
        self::assertSame('q=Beach&sync_status=error&sort_by=synced&sort_order=asc&page=3', HotelListView::query($search, true));
        self::assertSame('', HotelListView::query([]));
    }
}
