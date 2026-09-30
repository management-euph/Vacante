<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Repository;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Repository\CircuitRepository;
use Tygh\Addons\SphinxHolidays\Tests\Support\DbStub;

/**
 * CircuitRepository read paths that feed the admin grid: the paginated listing
 * (CS-Cart pagination contract), the incremental-sync watermark, and the total
 * count. DB access is routed through DbStub closures.
 */
#[CoversClass(CircuitRepository::class)]
final class CircuitRepositoryTest extends TestCase
{
    private CircuitRepository $repo;

    protected function setUp(): void
    {
        DbStub::reset();
        $this->repo = new CircuitRepository();
    }

    protected function tearDown(): void
    {
        DbStub::reset();
    }

    public function testGetListingReturnsRowsAndPagerParams(): void
    {
        $rows = [['circuit_id' => 1, 'name' => 'Grand Tour']];
        DbStub::$getField = static fn (string $q, ...$p): string => '37';
        $captured = [];
        DbStub::$getArray = static function (string $q, ...$p) use (&$captured, $rows): array {
            $captured = [$q, $p];
            return $rows;
        };

        [$result, $params] = $this->repo->getListing(['page' => 2, 'items_per_page' => 20]);

        self::assertSame($rows, $result);
        // The pager contract: total_items + page + items_per_page.
        self::assertSame(37, $params['total_items']);
        self::assertSame(2, $params['page']);
        self::assertSame(20, $params['items_per_page']);
        // LIMIT offset = (page-1)*per_page = 20, then per_page.
        self::assertStringContainsString('FROM ?:sphinx_circuits c', $captured[0]);
        self::assertStringContainsString('LIMIT ?i, ?i', $captured[0]);
        self::assertSame(20, $captured[1][1]);
        self::assertSame(20, $captured[1][2]);
    }

    public function testGetListingDefaultsToNameAscAndFallsBackOnBogusSort(): void
    {
        DbStub::$getField = static fn (string $q, ...$p): string => '0';
        $captured = '';
        DbStub::$getArray = static function (string $q, ...$p) use (&$captured): array {
            $captured = $q;
            return [];
        };

        $this->repo->getListing(['sort_by' => 'not_a_column']);
        self::assertStringContainsString('ORDER BY c.name ASC', $captured);
    }

    public function testGetListingHonorsAllowedSortColumnAndDirection(): void
    {
        DbStub::$getField = static fn (string $q, ...$p): string => '0';
        $captured = '';
        DbStub::$getArray = static function (string $q, ...$p) use (&$captured): array {
            $captured = $q;
            return [];
        };

        $this->repo->getListing(['sort_by' => 'min_price', 'sort_order' => 'desc']);
        self::assertStringContainsString('ORDER BY c.min_price DESC', $captured);
    }

    public function testGetListingBuildsTransportTypeFilter(): void
    {
        $condition = null;
        DbStub::$getField = static function (string $q, ...$p) use (&$condition): string {
            $condition = $p[0] ?? '';
            return '0';
        };
        DbStub::$getArray = static fn (string $q, ...$p): array => [];

        $this->repo->getListing(['transport_type' => 'flight']);
        self::assertStringContainsString("c.transport_type = 'flight'", (string) $condition);
    }

    public function testGetLastSyncedAtReturnsValueThenNull(): void
    {
        $captured = '';
        DbStub::$getField = static function (string $q, ...$p) use (&$captured): string {
            $captured = $q;
            return '2026-07-01 09:00:00';
        };
        self::assertSame('2026-07-01 09:00:00', $this->repo->getLastSyncedAt());
        self::assertStringContainsString('MAX(last_synced_at) FROM ?:sphinx_circuits', $captured);

        DbStub::$getField = static fn (string $q, ...$p): string => '';
        self::assertNull($this->repo->getLastSyncedAt());
    }

    public function testCountAll(): void
    {
        DbStub::$getField = static fn (string $q, ...$p): string => '412';
        self::assertSame(412, $this->repo->countAll());
    }

    public function testFindUnlinkedSelectsActiveProductlessCircuits(): void
    {
        $captured = [];
        DbStub::$getArray = static function (string $q, ...$p) use (&$captured): array {
            $captured = [$q, $p];
            return [['circuit_id' => 5, 'name' => 'Grand Tour']];
        };

        $rows = $this->repo->findUnlinked(50);

        self::assertCount(1, $rows);
        self::assertStringContainsString('(product_id IS NULL OR product_id = 0)', $captured[0]);
        self::assertStringContainsString("sync_status = 'active'", $captured[0]);
        self::assertSame(50, $captured[1][0]);
    }

    public function testLinkToProductWritesTheCircuitLink(): void
    {
        $captured = [];
        DbStub::$query = static function (string $q, ...$p) use (&$captured): int {
            $captured = [$q, $p];
            return 1;
        };

        $this->repo->linkToProduct(5, 777);

        self::assertStringContainsString('UPDATE ?:sphinx_circuits SET product_id = ?i WHERE circuit_id = ?i', $captured[0]);
        self::assertSame([777, 5], $captured[1]);
    }
}
