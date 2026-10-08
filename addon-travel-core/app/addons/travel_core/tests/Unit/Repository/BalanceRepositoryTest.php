<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Repository;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Repository\BalanceRepository;
use Tygh\Addons\TravelCore\Tests\Support\DbStub;

/**
 * REGRESSION (audit H1): settling a balance was an unconditional
 * UPDATE … WHERE balance_id, so a cancelled balance could become paid and a
 * paid one could be re-pointed at another order. Linking and settling now
 * only move a row that is still open, and say whether they did.
 */
#[CoversClass(BalanceRepository::class)]
final class BalanceRepositoryTest extends TestCase
{
    /** @var list<array{0: string, 1: list<mixed>}> */
    private array $queries = [];

    protected function setUp(): void
    {
        DbStub::reset();
        $this->queries = [];
    }

    protected function tearDown(): void
    {
        DbStub::reset();
    }

    /** db_query() answers an UPDATE with its affected-row count. */
    private function affecting(int $rows): void
    {
        DbStub::$query = function (string $q, mixed ...$p) use ($rows): int {
            $this->queries[] = [$q, array_values($p)];

            return $rows;
        };
    }

    public function testMarkPaidOnlyMovesAnOpenBalance(): void
    {
        $this->affecting(1);

        self::assertTrue((new BalanceRepository())->markPaidIfOpen(57, 1100, '2026-10-08 10:00:00'));

        self::assertCount(1, $this->queries);
        [$sql, $params] = $this->queries[0];
        self::assertStringStartsWith('UPDATE ?:travel_balances SET', $sql, 'core counts affected rows by the leading verb');
        self::assertStringEndsWith('WHERE balance_id = ?i AND status = ?s', $sql);
        self::assertSame(['paid', 1100, '2026-10-08 10:00:00', 57, 'open'], $params);
    }

    public function testMarkPaidReportsABalanceThatWasNotOpen(): void
    {
        $this->affecting(0);

        self::assertFalse((new BalanceRepository())->markPaidIfOpen(57, 1100, '2026-10-08 10:00:00'));
    }

    public function testLinkOnlyTouchesAnOpenBalance(): void
    {
        $this->affecting(1);
        self::assertTrue((new BalanceRepository())->linkOpen(57, 1100));
        [$sql, $params] = $this->queries[0];
        self::assertSame('UPDATE ?:travel_balances SET balance_order_id = ?i WHERE balance_id = ?i AND status = ?s', $sql);
        self::assertSame([1100, 57, 'open'], $params);

        $this->affecting(0);
        self::assertFalse((new BalanceRepository())->linkOpen(57, 1100));
    }
}
