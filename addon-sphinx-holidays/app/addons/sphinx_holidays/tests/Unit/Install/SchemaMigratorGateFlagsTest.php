<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Install;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Install\SchemaMigrator;
use Tygh\Addons\SphinxHolidays\Tests\Support\DbStub;

/**
 * The availability gate's product flags moved from product_skip_reason to
 * their own columns. On an existing install, SchemaMigrator adds the columns
 * and in the same request moves the old marks over, once: when the columns
 * are already there, nothing is moved again.
 */
final class SchemaMigratorGateFlagsTest extends TestCase
{
    /** @var list<string> */
    private array $queries = [];

    protected function setUp(): void
    {
        DbStub::reset();
        $this->queries = [];
        DbStub::$query = function (string $query): int {
            $this->queries[] = $query;

            return 1;
        };
        (new \ReflectionProperty(SchemaMigrator::class, 'done'))->setValue(null, false);
    }

    protected function tearDown(): void
    {
        DbStub::reset();
        (new \ReflectionProperty(SchemaMigrator::class, 'done'))->setValue(null, false);
    }

    /** @param list<string> $hotelColumns the sphinx_hotels columns the catalog reports */
    private function catalog(array $hotelColumns): void
    {
        DbStub::$getArray = static function (string $query) use ($hotelColumns): array {
            if (!str_contains($query, 'DATA_TYPE')) {
                return []; // indexes and coordinate scales: not what this test is about
            }

            return array_map(
                static fn (string $column): array => ['TABLE_NAME' => 'cscart_sphinx_hotels', 'COLUMN_NAME' => $column, 'DATA_TYPE' => 'varchar'],
                $hotelColumns,
            );
        };
    }

    private function indexOf(string $needle): int
    {
        foreach ($this->queries as $i => $query) {
            if (str_contains($query, $needle)) {
                return $i;
            }
        }

        return -1;
    }

    public function testAddingTheColumnsMovesTheOldMarksOnce(): void
    {
        $this->catalog(['hotel_id', 'product_id', 'product_skip_reason']);

        SchemaMigrator::ensure('cscart_');

        $hidden = $this->indexOf('ADD COLUMN `gate_hidden`');
        $released = $this->indexOf('ADD COLUMN `gate_released`');
        $move = $this->indexOf('SET h.gate_hidden = ');
        self::assertGreaterThanOrEqual(0, $hidden);
        self::assertGreaterThan($hidden, $released);
        self::assertGreaterThan($released, $move, 'the marks move after both columns exist');

        $sql = $this->queries[$move];
        // A Hidden product stays the gate's; an Active one was re-activated by an admin.
        self::assertStringContainsString("h.gate_hidden = IF(p.status = 'H', 'Y', 'N')", $sql);
        self::assertStringContainsString("h.gate_released = IF(p.status = 'A', 'Y', 'N')", $sql);
        self::assertStringContainsString('h.product_skip_reason = NULL', $sql);
        self::assertStringContainsString("WHERE h.product_skip_reason = 'no_availability' AND h.product_id > 0", $sql);
    }

    public function testNothingMovesWhenTheColumnsAreAlreadyThere(): void
    {
        $this->catalog(['hotel_id', 'product_id', 'product_skip_reason', 'gate_hidden', 'gate_released']);

        SchemaMigrator::ensure('cscart_');

        self::assertSame(-1, $this->indexOf('ADD COLUMN `gate_'));
        self::assertSame(-1, $this->indexOf('SET h.gate_hidden = '));
    }
}
