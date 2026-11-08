<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Repository\CircuitRepository;
use Tygh\Addons\SphinxHolidays\Services\BookableCircuits;
use Tygh\Addons\SphinxHolidays\Services\CircuitSyncService;
use Tygh\Addons\SphinxHolidays\SphinxApi;
use Tygh\Addons\SphinxHolidays\Tests\Support\DbStub;

/**
 * The admin's circuit list held circuits whose departures were all past
 * ("Maroc - Once in a Lifetime 2024 (charter avion)"): Sphinx's static list
 * keeps them. The circuits sync now saves only the circuits Sphinx prices
 * (circuits/rates) on a departure from today on, and the stored ones it no
 * longer prices leave the list.
 */
final class BookableCircuitsTest extends TestCase
{
    protected function setUp(): void
    {
        DbStub::reset();
    }

    protected function tearDown(): void
    {
        DbStub::reset();
    }

    /**
     * @param list<array<string, mixed>|null> $pages the rates answers, page by page
     * @param list<array<string, mixed>> $asked filled with each request
     */
    private function api(array $pages, array &$asked): SphinxApi
    {
        $api = $this->createMock(SphinxApi::class);
        $api->method('getCircuitRates')->willReturnCallback(static function (array $params) use (&$pages, &$asked): ?array {
            $asked[] = $params;

            return array_shift($pages);
        });

        return $api;
    }

    /** @return array<string, mixed> */
    private static function page(array $rates, int $lastPage): array
    {
        return ['results' => $rates, 'meta' => ['last_page' => $lastPage]];
    }

    public function testOnlyCircuitsPricedFromTodayOnAreBookable(): void
    {
        $asked = [];
        $api = $this->api([
            self::page([
                ['circuit_id' => 281, 'departure_date' => '2026-11-01'],
                ['circuit_id' => 1662, 'departure_date' => '2027-04-10'],
            ], 2),
            self::page([
                ['circuit_id' => 1662, 'departure_date' => '2027-05-10'],
                ['circuit_id' => 1700, 'departure_date' => '2026-11-08'],
            ], 2),
        ], $asked);

        self::assertSame([1662, 1700], (new BookableCircuits($api))->ids('2026-11-08'), 'a departure before today does not count; today does');
        self::assertCount(2, $asked, 'every page, up to the last');
        self::assertSame(['page' => 2, 'per_page' => 100], $asked[1]['pagination']);
        self::assertSame(BookableCircuits::months('2026-11-08'), $asked[0]['months']);
    }

    /** An unknown answer drops nothing: the sync keeps every circuit then. */
    public function testAnUnreadOrEmptyAnswerIsUnknown(): void
    {
        $asked = [];
        self::assertNull((new BookableCircuits($this->api([self::page([['circuit_id' => 1, 'departure_date' => '2027-01-01']], 3), null], $asked)))->ids('2026-11-08'), 'a page failed');
        self::assertNull((new BookableCircuits($this->api([self::page([], 1)], $asked)))->ids('2026-11-08'), 'no circuit priced at all');
        self::assertNull((new BookableCircuits($this->api([self::page([['circuit_id' => 1, 'departure_date' => '2020-01-01']], 1)], $asked)))->ids('2026-11-08'), 'only past dates');
    }

    public function testEighteenMonthsFromThisOne(): void
    {
        $months = BookableCircuits::months('2026-11-08');

        self::assertCount(18, $months);
        self::assertSame(['2026-11', '2026-12', '2027-01'], array_slice($months, 0, 3));
        self::assertSame('2028-04', $months[17]);
        self::assertSame('2027-02', BookableCircuits::months('2027-01-31')[1], 'the end of a month does not skip February');
    }

    public function testTheSyncSavesOnlyTheBookableOnes(): void
    {
        [$kept, $left] = CircuitSyncService::bookableOnly(
            [['circuit_id' => 281, 'name' => '2024'], ['circuit_id' => 1662, 'name' => '2025'], ['circuit_id' => 1700, 'name' => '2027']],
            [1700, 1662],
        );

        self::assertSame([1662, 1700], array_column($kept, 'circuit_id'));
        self::assertSame(1, $left);
    }

    /** Stored ones no longer priced: removed, or inactive when they have a product; priced again: active. */
    public function testStoredCircuitsNoLongerPricedLeaveTheList(): void
    {
        $queries = [];
        DbStub::$query = static function (string $query, mixed ...$params) use (&$queries): int {
            $queries[] = [$query, $params];

            return str_starts_with($query, 'DELETE') ? 5 : 2;
        };

        self::assertSame(['removed' => 5, 'deactivated' => 2], (new CircuitRepository())->pruneUnbookable([1662, 1700]));
        self::assertStringContainsString('DELETE FROM ?:sphinx_circuits WHERE circuit_id NOT IN (?n) AND (product_id IS NULL OR product_id = 0)', $queries[0][0]);
        self::assertStringContainsString("SET sync_status = 'inactive' WHERE circuit_id NOT IN (?n) AND product_id > 0", $queries[1][0]);
        self::assertStringContainsString("SET sync_status = 'active' WHERE circuit_id IN (?n)", $queries[2][0]);
        self::assertSame([[1662, 1700]], $queries[0][1]);

        $queries = [];
        self::assertSame(['removed' => 0, 'deactivated' => 0], (new CircuitRepository())->pruneUnbookable([]));
        self::assertSame([], $queries, 'never with an empty list');
    }

    /** The sync asks before it saves, and prunes only on a clean run with a known answer. */
    public function testTheSyncWiring(): void
    {
        $sync = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Services/CircuitSyncService.php');

        $ask = strpos($sync, '$bookable = (new BookableCircuits($this->api))->ids(date(\'Y-m-d\'));');
        self::assertNotFalse($ask);
        self::assertLessThan(strpos($sync, '$this->upsertBatch($batch)'), $ask, 'before the circuits are saved');
        self::assertStringContainsString('[$allCircuits, $past] = self::bookableOnly($allCircuits, $bookable);', $sync);
        self::assertStringContainsString("if (\$bookable !== null && TypeCoerce::toString(\$stats['error']) === '') {", $sync);
    }
}
