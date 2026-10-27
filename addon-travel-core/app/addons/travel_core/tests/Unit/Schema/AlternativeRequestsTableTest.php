<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Repository\AlternativeRequestRepository;
use Tygh\Addons\TravelCore\Tests\Support\DbStub;

/**
 * travel_alternative_requests reaches every store the same way: fresh
 * installs and upgrades through addon.xml, stores updated by pulling code
 * through the runtime heal (audit data-perf-2: it had none). The three must
 * create the same table, and the heal adds the "notify me" columns to a
 * table created before them (docs/novoton-availability-gaps-plan.md §8).
 */
final class AlternativeRequestsTableTest extends TestCase
{
    protected function tearDown(): void
    {
        DbStub::reset();
        AlternativeRequestRepository::resetEnsured();
    }

    private static function squash(string $sql): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $sql));
    }

    public function testAddonXmlCreatesTheSameTableForInstallAndUpgrade(): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 3) . '/addon.xml');
        self::assertNotFalse($xml);
        $items = [];
        foreach ($xml->xpath('//queries/item') ?: [] as $item) {
            $sql = (string) $item;
            if (str_contains($sql, 'CREATE TABLE IF NOT EXISTS `?:travel_alternative_requests`')) {
                $items[] = ['for' => (string) ($item['for'] ?? ''), 'sql' => self::squash($sql)];
            }
        }

        $expected = self::squash(AlternativeRequestRepository::CREATE_SQL);
        self::assertSame([['for' => '', 'sql' => $expected], ['for' => 'upgrade', 'sql' => $expected]], $items);
    }

    public function testTheRuntimeHealCreatesTheTable(): void
    {
        $func = (string) file_get_contents(dirname(__DIR__, 3) . '/func.php');
        $heal = substr($func, (int) strpos($func, 'function fn_travel_core_ensure_schema('));

        self::assertStringContainsString('AlternativeRequestRepository::ensureTable();', $heal);
    }

    /** A column added later is defined as in the CREATE statement, which places it after the column it follows. */
    public function testALaterColumnIsAddedAsTheCreateStatementDefinesIt(): void
    {
        $create = self::squash(AlternativeRequestRepository::CREATE_SQL);
        preg_match_all('/(?:\( |, )`(\w+)` /', $create, $names);
        foreach (AlternativeRequestRepository::ADDED_COLUMNS as $column => $definition) {
            self::assertSame(1, preg_match('/^(.*) AFTER `(\w+)`$/', $definition, $m), $column);
            self::assertStringContainsString('`' . $column . '` ' . self::squash($m[1]) . ',', $create, "{$column}: same definition");
            $at = array_search($column, $names[1], true);
            self::assertIsInt($at);
            self::assertSame($m[2], $names[1][$at - 1], "{$column} comes right after {$m[2]}");
        }
    }

    public function testTheHealAddsOnlyTheColumnsAnOlderTableLacks(): void
    {
        $sql = [];
        DbStub::$query = static function (string $query) use (&$sql): int {
            $sql[] = $query;

            return 1;
        };
        // A table from before "notify me": it has request_type from a half-run heal, nothing else.
        DbStub::$getField = static fn (string $query, mixed ...$params): string => $params[0] === 'request_type' ? 'request_type' : '';

        AlternativeRequestRepository::resetEnsured();
        AlternativeRequestRepository::ensureTable();
        AlternativeRequestRepository::ensureTable();

        self::assertCount(5, $sql, 'the CREATE, then four ALTERs, once per request');
        self::assertStringStartsWith('CREATE TABLE IF NOT EXISTS `?:travel_alternative_requests`', $sql[0]);
        self::assertSame(
            'ALTER TABLE ?:travel_alternative_requests ADD COLUMN `watch_for` ' . AlternativeRequestRepository::ADDED_COLUMNS['watch_for'],
            $sql[1],
        );
        self::assertStringNotContainsString('ADD COLUMN `request_type`', implode("\n", $sql));
    }

    public function testThe30DayExpiryLeavesNotifyMeRequestsAlone(): void
    {
        $sql = [];
        DbStub::$query = static function (string $query, mixed ...$params) use (&$sql): int {
            $sql[] = db_quote($query, ...$params);

            return 2;
        };
        DbStub::$getField = static fn (): string => 'present';

        self::assertSame(2, (new AlternativeRequestRepository())->expireOlderThan(30, 'sphinx'));

        $update = (string) end($sql);
        self::assertStringContainsString("WHERE status IN ('pending', 'pending_manual') AND request_type <> 'notify'", $update);
        self::assertStringContainsString("AND provider = 'sphinx'", $update);
    }

    public function testTheGridFiltersByType(): void
    {
        $queries = [];
        DbStub::$getField = static function (string $query, mixed ...$params) use (&$queries): string {
            $queries[] = db_quote($query, ...$params);

            return '0';
        };
        DbStub::$getArray = static fn (): array => [];

        [, $search] = (new AlternativeRequestRepository())->getListing(['request_type' => 'notify']);

        self::assertSame('notify', $search['request_type']);
        self::assertStringContainsString("AND r.request_type = 'notify'", (string) end($queries));
    }
}
