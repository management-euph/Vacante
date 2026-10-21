<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Repository;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Repository\HotelRepository;
use Tygh\Addons\SphinxHolidays\Tests\Support\DbStub;
use Tygh\Addons\TravelCore\Contracts\HotelRepositoryInterface;

/**
 * Coverage for HotelRepository's travel_core contract surface
 * (findById / exists / delete / linkToProduct / unlinkProduct / getCountries),
 * added when the repository adopted the provider-neutral interface.
 *
 * DB access is routed through DbStub closures; each test asserts both the
 * returned value and the SQL/params the repository issued.
 */
#[CoversClass(HotelRepository::class)]
class HotelRepositoryTest extends TestCase
{
    private HotelRepository $repo;

    protected function setUp(): void
    {
        DbStub::reset();
        $this->repo = new HotelRepository();
    }

    protected function tearDown(): void
    {
        DbStub::reset();
    }

    public function testImplementsTravelCoreContract(): void
    {
        $this->assertInstanceOf(HotelRepositoryInterface::class, $this->repo);
    }

    public function testFindByIdReturnsRow(): void
    {
        $row = ['hotel_id' => '3371', 'name' => 'Test Hotel', 'product_id' => 42];
        $captured = [];
        DbStub::$getRow = static function (string $query, ...$params) use ($row, &$captured): array {
            $captured = [$query, $params];
            return $row;
        };

        $result = $this->repo->findById('3371');

        $this->assertSame($row, $result);
        $this->assertStringContainsString('FROM ?:sphinx_hotels WHERE hotel_id = ?s', $captured[0]);
        $this->assertSame(['3371'], $captured[1]);
    }

    public function testFindByIdReturnsNullWhenMissing(): void
    {
        DbStub::$getRow = static fn (string $query, ...$params): array => [];

        $this->assertNull($this->repo->findById('nope'));
    }

    public function testExistsTrueWhenRowPresent(): void
    {
        $captured = [];
        DbStub::$getField = static function (string $query, ...$params) use (&$captured): string {
            $captured = [$query, $params];
            return '1';
        };

        $this->assertTrue($this->repo->exists('3371'));
        $this->assertStringContainsString('SELECT 1 FROM ?:sphinx_hotels WHERE hotel_id = ?s', $captured[0]);
        $this->assertSame(['3371'], $captured[1]);
    }

    public function testExistsFalseWhenMissing(): void
    {
        DbStub::$getField = static fn (string $query, ...$params) => null;

        $this->assertFalse($this->repo->exists('nope'));
    }

    public function testDeleteReturnsTrueWhenRowDeleted(): void
    {
        $captured = [];
        DbStub::$query = static function (string $query, ...$params) use (&$captured): int {
            $captured = [$query, $params];
            return 1;
        };

        $this->assertTrue($this->repo->delete('3371'));
        $this->assertStringContainsString('DELETE FROM ?:sphinx_hotels WHERE hotel_id IN (?a)', $captured[0]);
        $this->assertSame([['3371']], $captured[1]);
    }

    public function testDeleteReturnsFalseWhenNothingDeleted(): void
    {
        DbStub::$query = static fn (string $query, ...$params): int => 0;

        $this->assertFalse($this->repo->delete('nope'));
    }

    public function testLinkToProductReturnsTrueWhenUpdated(): void
    {
        $captured = [];
        DbStub::$query = static function (string $query, ...$params) use (&$captured): int {
            $captured = [$query, $params];
            return 1;
        };

        $this->assertTrue($this->repo->linkToProduct('3371', 42));
        // A hotel moving to another product leaves the old one's gate flags
        // behind; the IF()s come before product_id is assigned.
        $this->assertStringContainsString("SET gate_hidden = IF(product_id <=> ?i, gate_hidden, 'N'),", $captured[0]);
        $this->assertStringContainsString("gate_released = IF(product_id <=> ?i, gate_released, 'N'),", $captured[0]);
        $this->assertStringContainsString("product_id = ?i\n             WHERE hotel_id = ?s", $captured[0]);
        $this->assertSame([42, 42, 42, '3371'], $captured[1]);
    }

    public function testLinkToProductReturnsFalseWhenNoRowChanged(): void
    {
        DbStub::$query = static fn (string $query, ...$params): int => 0;

        $this->assertFalse($this->repo->linkToProduct('nope', 42));
    }

    public function testUnlinkProductClearsLinkByProductId(): void
    {
        $captured = [];
        DbStub::$query = static function (string $query, ...$params) use (&$captured): int {
            $captured = [$query, $params];
            return 1;
        };

        $this->assertTrue($this->repo->unlinkProduct(42));
        $this->assertStringContainsString("SET product_id = NULL, gate_hidden = 'N', gate_released = 'N' WHERE product_id = ?i", $captured[0]);
        $this->assertSame([42], $captured[1]);
    }

    public function testUnlinkProductReturnsFalseWhenNoneLinked(): void
    {
        DbStub::$query = static fn (string $query, ...$params): int => 0;

        $this->assertFalse($this->repo->unlinkProduct(99));
    }

    public function testGetCountriesDelegatesToDistinctCountries(): void
    {
        $captured = [];
        DbStub::$getFields = static function (string $query, ...$params) use (&$captured): array {
            $captured = [$query, $params];
            return ['BG', 'GR', 'TR'];
        };

        $this->assertSame(['BG', 'GR', 'TR'], $this->repo->getCountries());
        $this->assertStringContainsString('SELECT DISTINCT country_code FROM ?:sphinx_hotels', $captured[0]);
    }
}
