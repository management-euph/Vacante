<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Repository;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Repository\HotelSkipRepository;
use Tygh\Addons\SphinxHolidays\Tests\Support\DbStub;

/**
 * Characterization coverage for HotelSkipRepository — the product_skip_reason
 * lifecycle extracted from HotelRepository. Each test pins the SQL and the
 * parameters the repository issues so the extraction provably preserves
 * behaviour. DB access is routed through DbStub closures.
 */
#[CoversClass(HotelSkipRepository::class)]
class HotelSkipRepositoryTest extends TestCase
{
    private HotelSkipRepository $repo;

    protected function setUp(): void
    {
        DbStub::reset();
        $this->repo = new HotelSkipRepository();
    }

    protected function tearDown(): void
    {
        DbStub::reset();
    }

    public function testSkipReasonConstant(): void
    {
        $this->assertSame('no_availability', HotelSkipRepository::SKIP_REASON_NO_AVAILABILITY);
    }

    public function testMarkSkippedIssuesUpdate(): void
    {
        $captured = [];
        DbStub::$query = static function (string $query, ...$params) use (&$captured): int {
            $captured = [$query, $params];
            return 1;
        };

        $this->repo->markSkipped('3371', 'no_description');

        $this->assertStringContainsString(
            'UPDATE ?:sphinx_hotels SET product_skip_reason = ?s WHERE hotel_id = ?s',
            $captured[0],
        );
        $this->assertSame(['no_description', '3371'], $captured[1]);
    }

    public function testMarkSkippedBatchUpdatesOnlyNullReasons(): void
    {
        $captured = [];
        DbStub::$query = static function (string $query, ...$params) use (&$captured): int {
            $captured = [$query, $params];
            return 4;
        };

        $result = $this->repo->markSkippedBatch(['a', 'b'], HotelSkipRepository::SKIP_REASON_NO_AVAILABILITY);

        $this->assertSame(4, $result);
        $this->assertStringContainsString('SET product_skip_reason = ?s', $captured[0]);
        $this->assertStringContainsString('WHERE hotel_id IN (?a) AND product_skip_reason IS NULL', $captured[0]);
        $this->assertSame(['no_availability', ['a', 'b']], $captured[1]);
    }

    public function testMarkSkippedBatchShortCircuitsOnEmpty(): void
    {
        $called = false;
        DbStub::$query = static function () use (&$called): int {
            $called = true;
            return 0;
        };

        $this->assertSame(0, $this->repo->markSkippedBatch([], 'whatever'));
        $this->assertFalse($called, 'db_query must not run for an empty hotel id list');
    }

    public function testClearSkipReasonBatchScopesToReason(): void
    {
        $captured = [];
        DbStub::$query = static function (string $query, ...$params) use (&$captured): int {
            $captured = [$query, $params];
            return 2;
        };

        $result = $this->repo->clearSkipReasonBatch(['x', 'y'], HotelSkipRepository::SKIP_REASON_NO_AVAILABILITY);

        $this->assertSame(2, $result);
        $this->assertStringContainsString('SET product_skip_reason = NULL', $captured[0]);
        $this->assertStringContainsString('WHERE hotel_id IN (?a) AND product_skip_reason = ?s', $captured[0]);
        $this->assertSame([['x', 'y'], 'no_availability'], $captured[1]);
    }

    public function testClearSkipReasonBatchShortCircuitsOnEmpty(): void
    {
        $called = false;
        DbStub::$query = static function () use (&$called): int {
            $called = true;
            return 0;
        };

        $this->assertSame(0, $this->repo->clearSkipReasonBatch([], 'no_availability'));
        $this->assertFalse($called);
    }

    public function testResetSkippedWithoutReasonKeepsTheGatesWaitingHotels(): void
    {
        $captured = [];
        DbStub::$query = static function (string $query, ...$params) use (&$captured): int {
            $captured = [$query, $params];
            return 7;
        };

        $result = $this->repo->resetSkipped();

        $this->assertSame(7, $result);
        $this->assertStringContainsString('SET product_skip_reason = NULL', $captured[0]);
        $this->assertStringContainsString('WHERE product_skip_reason IS NOT NULL ?p', $captured[0]);
        // "Retry skipped" leaves hotels waiting for an immediate offer to the
        // gate: reset, they would become products only to be hidden again.
        $this->assertSame([" AND product_skip_reason <> 'no_availability'"], $captured[1]);
    }

    public function testResetSkippedAppendsCountryAndReasonConditions(): void
    {
        $captured = [];
        DbStub::$query = static function (string $query, ...$params) use (&$captured): int {
            $captured = [$query, $params];
            return 1;
        };

        $this->repo->resetSkipped('GR', 'duplicate');

        $condition = $captured[1][0];
        $this->assertIsString($condition);
        $this->assertStringContainsString("AND country_code = 'GR'", $condition);
        $this->assertStringContainsString("AND product_skip_reason = 'duplicate'", $condition);
    }

    public function testCountSkippedWithoutReasonLeavesOutWaitingHotels(): void
    {
        $captured = [];
        DbStub::$getField = static function (string $query, ...$params) use (&$captured): string {
            $captured = [$query, $params];
            return '12';
        };

        $this->assertSame(12, $this->repo->countSkipped());
        $this->assertStringContainsString(
            'SELECT COUNT(*) FROM ?:sphinx_hotels WHERE product_skip_reason IS NOT NULL ?p',
            $captured[0],
        );
        $this->assertSame([" AND product_skip_reason <> 'no_availability'"], $captured[1]);

        $this->repo->countSkipped(HotelSkipRepository::SKIP_REASON_NO_AVAILABILITY);
        $this->assertSame([" AND product_skip_reason = 'no_availability'"], $captured[1], 'asked by name, they count');
    }

    public function testCountSkippedScopedToReason(): void
    {
        $captured = [];
        DbStub::$getField = static function (string $query, ...$params) use (&$captured): string {
            $captured = [$query, $params];
            return '3';
        };

        $this->assertSame(3, $this->repo->countSkipped('unrated'));
        $condition = $captured[1][0];
        $this->assertIsString($condition);
        $this->assertStringContainsString("AND product_skip_reason = 'unrated'", $condition);
    }

    public function testFindAvailabilityGateCandidatesShortCircuitsOnEmpty(): void
    {
        $called = false;
        DbStub::$getArray = static function () use (&$called): array {
            $called = true;
            return [];
        };

        $this->assertSame([], $this->repo->findAvailabilityGateCandidates([]));
        $this->assertFalse($called, 'db_get_array must not run for an empty destination list');
    }

    public function testFindAvailabilityGateCandidatesQueriesEligibleRows(): void
    {
        $rows = [
            ['hotel_id' => '10', 'destination_id' => 5, 'product_id' => 0, 'product_skip_reason' => null],
            ['hotel_id' => '11', 'destination_id' => 5, 'product_id' => 42, 'product_skip_reason' => 'no_availability'],
        ];
        $captured = [];
        DbStub::$getArray = static function (string $query, ...$params) use ($rows, &$captured): array {
            $captured = [$query, $params];
            return $rows;
        };

        $result = $this->repo->findAvailabilityGateCandidates([5, 9]);

        $this->assertSame($rows, $result);
        $this->assertStringContainsString('WHERE destination_id IN (?n)', $captured[0]);
        // LINKED hotels are candidates too ("Hotels with immediate
        // confirmation" reaches the product catalog), so the query must
        // select product_id and must NOT carry the old unlinked-only guard.
        $this->assertStringContainsString('SELECT hotel_id, destination_id, product_id, product_skip_reason', $captured[0]);
        $this->assertStringNotContainsString('product_id IS NULL', $captured[0]);
        $this->assertStringContainsString('(product_skip_reason IS NULL OR product_skip_reason = ?s)', $captured[0]);
        $this->assertSame([[5, 9], 'active', 'no_availability'], $captured[1]);
    }

    public function testHideProductsBatchFlagsFirstAndOnlyTouchesActiveProducts(): void
    {
        // The linked branch: the gate hides an unavailable hotel's product
        // instead of deleting it. Guarded on status='A' so a product the
        // admin already Hid or Disabled by hand keeps the admin's choice.
        // Flag first: a run that stops in between leaves a flagged Active
        // product (released next run), never an unflagged Hidden one.
        $queries = [];
        DbStub::$query = static function (string $query, ...$params) use (&$queries): int {
            $queries[] = [$query, $params];
            return 1;
        };

        $this->assertSame(0, $this->repo->hideProductsBatch([]));
        $this->assertSame([], $queries, 'empty input must not touch the DB');

        $this->assertSame(1, $this->repo->hideProductsBatch([42, 43]));
        $this->assertCount(2, $queries);
        $this->assertSame(
            "UPDATE ?:sphinx_hotels SET gate_hidden = 'Y' WHERE product_id IN (?n)",
            $queries[0][0],
        );
        $this->assertSame([[42, 43]], $queries[0][1]);
        $this->assertStringContainsString(
            "UPDATE ?:products SET status = 'H' WHERE product_id IN (?n) AND status = 'A'",
            $queries[1][0],
        );
        $this->assertSame([[42, 43]], $queries[1][1]);
    }

    public function testShowProductsBatchReactivatesHiddenProductsThenDropsTheFlag(): void
    {
        // The way back — guarded on status='H': a product the admin Disabled
        // while the hotel was unavailable stays exactly as the admin left it.
        $queries = [];
        DbStub::$query = static function (string $query, ...$params) use (&$queries): int {
            $queries[] = [$query, $params];
            return 1;
        };

        $this->assertSame(0, $this->repo->showProductsBatch([]));
        $this->assertSame([], $queries, 'empty input must not touch the DB');

        $this->assertSame(1, $this->repo->showProductsBatch([42]));
        $this->assertCount(2, $queries);
        $this->assertStringContainsString(
            "UPDATE ?:products SET status = 'A' WHERE product_id IN (?n) AND status = 'H'",
            $queries[0][0],
        );
        $this->assertSame([[42]], $queries[0][1]);
        $this->assertSame("UPDATE ?:sphinx_hotels SET gate_hidden = 'N' WHERE product_id IN (?n)", $queries[1][0]);
        $this->assertSame([[42]], $queries[1][1]);
    }

    public function testFindGateStatesReadsStatusAndFlagsPerProduct(): void
    {
        $this->assertSame([], $this->repo->findGateStates([]));

        $captured = [];
        DbStub::$getArray = static function (string $query, ...$params) use (&$captured): array {
            $captured = [$query, $params];

            return [
                ['product_id' => '42', 'status' => 'H', 'hidden' => '1', 'released' => '0'],
                ['product_id' => '43', 'status' => 'A', 'hidden' => '0', 'released' => '1'],
            ];
        };

        $this->assertSame([
            42 => ['status' => 'H', 'hidden' => true, 'released' => false],
            43 => ['status' => 'A', 'hidden' => false, 'released' => true],
        ], $this->repo->findGateStates([42, 43]));
        // Sibling hotel rows share their product's flags; deleted products drop out.
        $this->assertStringContainsString("MAX(h.gate_hidden = 'Y') AS hidden, MAX(h.gate_released = 'Y') AS released", $captured[0]);
        $this->assertStringContainsString('JOIN ?:products p ON p.product_id = h.product_id', $captured[0]);
        $this->assertStringContainsString('GROUP BY h.product_id, p.status', $captured[0]);
        $this->assertSame([[42, 43]], $captured[1]);
    }

    public function testReleaseAndEndReleaseWriteByProduct(): void
    {
        $queries = [];
        DbStub::$query = static function (string $query, ...$params) use (&$queries): int {
            $queries[] = [$query, $params];
            return 3; // rows: sibling hotel ids share a product
        };

        $this->assertSame(0, $this->repo->releaseProductsBatch([]));
        $this->repo->clearReleasedBatch([]);
        $this->assertSame([], $queries, 'empty input must not touch the DB');

        $this->assertSame(1, $this->repo->releaseProductsBatch([42]), 'counted in products, not hotel rows');
        $this->repo->clearReleasedBatch([42]);
        $this->assertSame([
            ["UPDATE ?:sphinx_hotels SET gate_hidden = 'N', gate_released = 'Y' WHERE product_id IN (?n)", [[42]]],
            ["UPDATE ?:sphinx_hotels SET gate_released = 'N' WHERE product_id IN (?n)", [[42]]],
        ], $queries);
    }
}
