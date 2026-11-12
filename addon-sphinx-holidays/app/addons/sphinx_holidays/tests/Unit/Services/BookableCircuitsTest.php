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
            // This month, every page: 281 only before today, 1700 today; 9 is not a candidate.
            self::page([
                ['circuit_id' => 281, 'departure_date' => '2026-11-01'],
                ['circuit_id' => 1700, 'departure_date' => '2026-11-08'],
            ], 2),
            self::page([['circuit_id' => 9, 'departure_date' => '2026-11-20']], 2),
            self::page([['circuit_id' => 1662, 'departure_date' => '2026-12-10']], 1),
            // 281 has no departure in the 16 months left.
            ...array_fill(0, 16, self::page([], 1)),
        ], $asked);

        $ids = (new BookableCircuits($api))->ids('2026-11-08', [281, 1662, 1700, 1700, 0]);
        sort($ids);

        self::assertSame([1662, 1700], $ids, 'a departure before today does not count; today does; only candidates');
        self::assertSame(['months' => ['2026-11'], 'pagination' => ['page' => 1, 'per_page' => 100]], $asked[0], 'no circuit_ids: the endpoint ignores them');
        self::assertSame(['page' => 2, 'per_page' => 100], $asked[1]['pagination'], 'every page of a month');
        self::assertSame(['2026-12'], $asked[2]['months']);
        self::assertCount(2 + 17, $asked, 'one request a page, a month at a time; 281 keeps it going to the last month');
    }

    /** Once every candidate is found, no further month is asked. */
    public function testTheSearchStopsOnceEveryCircuitIsFound(): void
    {
        $asked = [];
        $api = $this->api([
            self::page([['circuit_id' => 5, 'departure_date' => '2026-11-20']], 1),
            self::page([['circuit_id' => 5, 'departure_date' => '2026-12-20']], 1),
            self::page([['circuit_id' => 6, 'departure_date' => '2027-01-03']], 1),
        ], $asked);

        $ids = (new BookableCircuits($api))->ids('2026-11-08', [5, 6]);
        sort($ids);

        self::assertSame([5, 6], $ids);
        self::assertSame(['2026-11', '2026-12', '2027-01'], array_merge(...array_column($asked, 'months')), 'then nothing is left to ask');
    }

    /** An unknown answer drops nothing: the sync keeps every circuit then. */
    public function testAnUnreadOrEmptyAnswerIsUnknown(): void
    {
        $asked = [];
        self::assertNull((new BookableCircuits($this->api([self::page([['circuit_id' => 1, 'departure_date' => '2026-11-20']], 3), null], $asked)))->ids('2026-11-08', [1, 2]), 'a page failed');
        self::assertNull((new BookableCircuits($this->api(array_fill(0, 18, self::page([], 1)), $asked)))->ids('2026-11-08', [1]), 'no circuit priced at all');
        self::assertNull((new BookableCircuits($this->api([], $asked)))->ids('2026-11-08', []), 'no circuit to ask about');
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

    /**
     * Stored ones no longer priced: removed, or inactive when they have a
     * product, which is hidden (only one on sale; marked so). Priced again:
     * active, and the product on sale again only when the sync hid it.
     */
    public function testStoredCircuitsNoLongerPricedLeaveTheList(): void
    {
        $queries = [];
        DbStub::$query = static function (string $query, mixed ...$params) use (&$queries): int {
            $queries[] = [$query, $params];

            return match (count($queries)) {
                1 => 5,  // removed
                2 => 6,  // 3 products hidden: 3 products + 3 circuits changed
                3 => 3,  // set inactive
                4 => 1,  // 1 product on sale again
                default => 1,
            };
        };

        self::assertSame(
            ['removed' => 5, 'deactivated' => 3, 'hidden' => 3, 'shown' => 1],
            (new CircuitRepository())->pruneUnbookable([1662, 1700]),
        );
        [$delete, $hide, $deactivate, $show, $reactivate] = array_column($queries, 0);
        self::assertStringContainsString('DELETE FROM ?:sphinx_circuits WHERE circuit_id NOT IN (?n) AND (product_id IS NULL OR product_id = 0)', $delete);
        self::assertStringContainsString("SET p.status = 'H', c.product_hidden = 'Y'", $hide);
        self::assertStringContainsString("WHERE c.circuit_id NOT IN (?n) AND c.sync_status = 'active' AND p.status = 'A'", $hide, 'only a product on sale; one an admin hid stays unmarked');
        self::assertStringContainsString("SET sync_status = 'inactive' WHERE circuit_id NOT IN (?n) AND product_id > 0", $deactivate);
        self::assertStringContainsString("WHERE c.circuit_id IN (?n) AND c.product_hidden = 'Y' AND p.status = 'H'", $show, 'only what the sync hid, still hidden');
        self::assertStringContainsString("SET sync_status = 'active', product_hidden = 'N'", $reactivate);
        self::assertSame([[1662, 1700]], $queries[0][1]);

        $queries = [];
        self::assertSame(['removed' => 0, 'deactivated' => 0, 'hidden' => 0, 'shown' => 0], (new CircuitRepository())->pruneUnbookable([]));
        self::assertSame([], $queries, 'never with an empty list');
    }

    /** product_hidden reaches fresh installs, upgrades and the installs SchemaMigrator converges. */
    public function testTheHiddenProductMarkReachesEveryInstall(): void
    {
        $root = dirname(__DIR__, 3);
        $xml = (string) file_get_contents($root . '/addon.xml');
        $definition = "ENUM('Y','N') NOT NULL DEFAULT 'N' COMMENT 'Product hidden by the circuits sync (no rate ahead); shown again with the circuit'";

        self::assertStringContainsString("`product_hidden`    {$definition},", $xml, 'the CREATE TABLE');
        self::assertStringContainsString("ALTER TABLE `?:sphinx_circuits`\n                ADD COLUMN `product_hidden` ENUM('Y','N') NOT NULL DEFAULT 'N'", $xml, 'the upgrade item');
        self::assertStringContainsString("'product_hidden' => \"ADD COLUMN `product_hidden` {$definition} AFTER `sync_status`\"", (string) file_get_contents($root . '/src/Install/SchemaMigrator.php'));
    }

    /** The sync asks before it saves, and prunes only on a clean run with a known answer. */
    public function testTheSyncWiring(): void
    {
        $sync = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Services/CircuitSyncService.php');

        $ask = strpos($sync, '$bookable = $rates->ids(date(\'Y-m-d\'), $candidates, true);');
        self::assertNotFalse($ask);
        self::assertLessThan(strpos($sync, '$this->upsertBatch($batch)'), $ask, 'before the circuits are saved');
        self::assertStringContainsString('[$allCircuits, $past] = self::bookableOnly($allCircuits, $bookable);', $sync);
        self::assertStringContainsString("if (\$bookable !== null && TypeCoerce::toString(\$stats['error']) === '') {", $sync);
    }
}
