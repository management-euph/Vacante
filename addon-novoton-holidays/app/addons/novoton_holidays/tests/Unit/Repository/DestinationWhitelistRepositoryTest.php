<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Repository;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Repository\DestinationWhitelistRepository;
use Tygh\Addons\NovotonHolidays\Tests\Support\DbStub;

final class DestinationWhitelistRepositoryTest extends TestCase
{
    /** @var list<array{string, list<mixed>}> */
    private array $queries = [];

    protected function setUp(): void
    {
        DbStub::reset();
        $this->queries = [];
        DbStub::$query = function (string $sql, mixed ...$params): int {
            $this->queries[] = [trim((string) preg_replace('/\s+/', ' ', $sql)), $params];
            if (str_starts_with(trim($sql), 'INSERT') && ($params[1] ?? '') === 'BOOM') {
                throw new \RuntimeException('duplicate');
            }

            return 1;
        };
    }

    protected function tearDown(): void
    {
        DbStub::reset();
    }

    /** @return list<string> */
    private function sql(): array
    {
        return array_map(static fn (array $q): string => $q[0], $this->queries);
    }

    public function testSaveReplacesEveryRowInOneTransaction(): void
    {
        (new DestinationWhitelistRepository())->replaceAll([
            ['country' => 'BULGARIA', 'resort' => '', 'selection_type' => 'specific'],
            ['country' => 'BULGARIA', 'resort' => 'SUNNY BEACH', 'selection_type' => 'specific'],
        ], '2026-09-27 10:00:00');

        $sql = $this->sql();
        self::assertSame('START TRANSACTION', $sql[0]);
        self::assertSame('DELETE FROM ?:novoton_destination_whitelist', $sql[1]);
        self::assertStringStartsWith('INSERT INTO ?:novoton_destination_whitelist', $sql[2]);
        self::assertSame(['BULGARIA', 'SUNNY BEACH', 'specific', '2026-09-27 10:00:00'], $this->queries[3][1]);
        self::assertSame('COMMIT', end($sql));
    }

    public function testAFailedSaveRollsBackAndKeepsTheOldWhitelist(): void
    {
        try {
            (new DestinationWhitelistRepository())->replaceAll([
                ['country' => 'BULGARIA', 'resort' => '', 'selection_type' => 'specific'],
                ['country' => 'BULGARIA', 'resort' => 'BOOM', 'selection_type' => 'specific'],
            ], '2026-09-27 10:00:00');
            self::fail('the failure must reach the controller');
        } catch (\RuntimeException) {
        }

        self::assertSame('ROLLBACK', end($this->queries)[0]);
        self::assertNotContains('COMMIT', $this->sql());
    }

    public function testDisablingOnlyTouchesLiveProductsAndIgnoresBadIds(): void
    {
        $repo = new DestinationWhitelistRepository();

        self::assertSame(0, $repo->disableProducts([0, -3]));
        self::assertSame([], $this->queries, 'nothing to disable: no query');

        $repo->disableProducts([12, 0, 13]);
        self::assertSame("UPDATE ?:products SET status = 'D' WHERE product_id IN (?n) AND status = 'A'", $this->queries[0][0]);
        self::assertSame([[12, 13]], $this->queries[0][1]);
    }
}
