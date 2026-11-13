<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Repository;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Repository\HotelListingRepository;
use Tygh\Addons\NovotonHolidays\Tests\Support\DbStub;

/**
 * Novoton → Hotels (the sync monitor): what a request may filter and sort
 * by, how the sync status is read from the timestamps, and that a re-sync
 * only queues the hotels (the cron asks Novoton, not the page).
 */
final class HotelListingRepositoryTest extends TestCase
{
    private const string NOW = '2026-11-13 12:00:00';

    protected function setUp(): void
    {
        DbStub::reset();
    }

    protected function tearDown(): void
    {
        DbStub::reset();
    }

    public function testARequestIsBroughtToKnownFiltersAndSorts(): void
    {
        $search = (new HotelListingRepository(50))->normalize([
            'page' => '0',
            'sort_by' => 'DROP TABLE',
            'sort_order' => 'DESC',
            'q' => '  Beach ',
            'stars' => '9',
            'sync_status' => 'bogus',
            'not_synced_days' => '12',
            'items_per_page' => '0',
        ]);

        self::assertSame(1, $search['page']);
        self::assertSame(50, $search['items_per_page']);
        self::assertSame('name', $search['sort_by'], 'an unknown sort falls back to the name');
        self::assertSame('desc', $search['sort_order']);
        self::assertSame('asc', $search['sort_order_rev']);
        self::assertSame('Beach', $search['q']);
        self::assertSame([], $search['stars'], 'a rating outside 1-5 is not a filter');
        self::assertSame('', $search['sync_status']);
        self::assertSame(0, $search['not_synced_days'], 'only the offered ages');
    }

    public function testTheOfferedFiltersAreKept(): void
    {
        $search = (new HotelListingRepository())->normalize(['stars_5' => 'Y', 'stars_4' => 'Y', 'stars_2' => 'N', 'sync_status' => 'error', 'not_synced_days' => '30', 'sort_by' => 'synced', 'product' => 'deleted', 'property_type_villa' => 'Y', 'property_type_castle' => 'Y']);

        self::assertSame([4, 5], $search['stars'], 'several stars at once, in order');
        self::assertSame('deleted', $search['product']);
        self::assertSame(['villa'], $search['property_types'], 'only the known types');
        self::assertSame('error', $search['sync_status']);
        self::assertSame(30, $search['not_synced_days']);
        self::assertSame('synced', $search['sort_by']);
    }

    public function testNoFilterAddsNoCondition(): void
    {
        self::assertSame('', HotelListingRepository::condition((new HotelListingRepository())->normalize([]), self::NOW));
    }

    public function testEachFilterAddsItsCondition(): void
    {
        $repo = new HotelListingRepository();

        $sql = HotelListingRepository::condition($repo->normalize(['q' => 'Beach', 'country' => 'Bulgaria', 'city' => 'Varna', 'stars' => 4]), self::NOW);
        self::assertStringContainsString('(h.star_rating IN (4))', $sql, 'an older stars=4 link still works');
        self::assertStringContainsString("(h.hotel_name LIKE '%Beach%' OR h.hotel_id LIKE '%Beach%')", $sql);
        self::assertStringContainsString("h.country = 'Bulgaria'", $sql);
        self::assertStringContainsString("(h.city = 'Varna' OR h.region = 'Varna')", $sql);

        $sql = HotelListingRepository::condition($repo->normalize(['sync_status' => 'unmapped']), self::NOW);
        self::assertStringContainsString("END = 'unmapped'", $sql);
    }

    public function testNotSyncedInTheLastDaysCountsFromNowAndKeepsNeverSynced(): void
    {
        $sql = HotelListingRepository::condition((new HotelListingRepository())->normalize(['not_synced_days' => 7]), self::NOW);

        self::assertStringContainsString("(h.hotelinfo_synced_at IS NULL OR h.hotelinfo_synced_at < '2026-11-06 12:00:00')", $sql);
    }

    public function testTheStatusIsReadFromTheTimestampsInOrder(): void
    {
        $sql = HotelListingRepository::STATUS_SQL;
        $error = strpos($sql, "'error'");
        $unmapped = strpos($sql, "'unmapped'");
        $pending = strpos($sql, "'pending'");

        self::assertNotFalse($error);
        self::assertNotFalse($unmapped);
        self::assertNotFalse($pending);
        self::assertLessThan($unmapped, $error, 'a failed request shows before a missing product');
        self::assertLessThan($pending, $unmapped);
        self::assertStringContainsString('h.hotelinfo_tried_at > h.hotelinfo_synced_at', $sql, 'tried after the last answer: no answer');
        self::assertStringContainsString('h.hotelinfo_due_at > h.hotelinfo_synced_at', $sql, 'due after the last answer: queued');
        self::assertStringContainsString('p.product_id IS NULL', $sql, 'a deleted product counts as no product');
    }

    public function testDescendingReversesEveryPartOfASort(): void
    {
        self::assertSame('h.country ASC, h.city ASC, h.hotel_name ASC', HotelListingRepository::orderBy('destination', 'asc'));
        self::assertSame('h.hotelinfo_synced_at DESC, h.hotel_name DESC', HotelListingRepository::orderBy('synced', 'desc'));
        self::assertSame('h.hotel_name ASC', HotelListingRepository::orderBy('nope', 'asc'));
    }

    public function testAReSyncOnlyMarksTheHotelsDue(): void
    {
        $queries = [];
        DbStub::$query = static function (string $q, ...$p) use (&$queries): int {
            $queries[] = [$q, $p];

            return 2;
        };

        $queued = (new HotelListingRepository())->queueResync(['110291', '', '2906', '110291'], self::NOW);

        self::assertSame(2, $queued);
        self::assertCount(1, $queries);
        self::assertStringContainsString('SET hotelinfo_due_at = ?s WHERE hotel_id IN (?a)', $queries[0][0]);
        self::assertSame([self::NOW, ['110291', '2906']], $queries[0][1], 'each hotel once, empty ids left out');
    }

    public function testNothingSelectedQueuesNothing(): void
    {
        DbStub::$query = static function (): int {
            self::fail('no query for an empty selection');
        };

        self::assertSame(0, (new HotelListingRepository())->queueResync([], self::NOW));
        self::assertSame([], (new HotelListingRepository())->productIds([]));
    }

    public function testEveryStatusHasACountEvenWhenNoHotelHasIt(): void
    {
        DbStub::$getArray = static fn (): array => [['sync_status' => 'active', 'n' => '12'], ['sync_status' => 'other', 'n' => '3']];

        self::assertSame(['active' => 12, 'pending' => 0, 'error' => 0, 'unmapped' => 0], (new HotelListingRepository())->statusCounts());
    }

    public function testStarsAndNoStarsCombine(): void
    {
        $sql = HotelListingRepository::condition((new HotelListingRepository())->normalize(['stars_4' => 'Y', 'stars_5' => 'Y', 'stars_none' => 'Y']), self::NOW);

        self::assertStringContainsString('(h.star_rating IN (4,5) OR (h.star_rating IS NULL OR h.star_rating = 0))', $sql);
    }

    public function testTheProductFilter(): void
    {
        $repo = new HotelListingRepository();

        self::assertStringContainsString('p.product_id IS NOT NULL', HotelListingRepository::condition($repo->normalize(['product' => 'with']), self::NOW));
        self::assertStringContainsString('(h.product_id IS NULL OR h.product_id = 0)', HotelListingRepository::condition($repo->normalize(['product' => 'without']), self::NOW));
        self::assertStringContainsString('h.product_id > 0 AND p.product_id IS NULL', HotelListingRepository::condition($repo->normalize(['product' => 'deleted']), self::NOW));
        self::assertSame('', HotelListingRepository::condition($repo->normalize(['product' => 'nope']), self::NOW));
    }

    public function testThePropertyTypeFilter(): void
    {
        $sql = HotelListingRepository::condition((new HotelListingRepository())->normalize(['property_type_apartment' => 'Y', 'property_type_villa' => 'Y']), self::NOW);

        self::assertStringContainsString("h.property_type IN ('apartment', 'villa')", $sql);
    }
}
