<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Tests\Support;

/**
 * Programmable stand-in for CS-Cart's db_* procedural API.
 *
 * tests/bootstrap.php routes its db_* stubs through this class, so a test can
 * both feed rows in and read back the statements the code under test issued —
 * without a database. Behaviour with no programming is identical to the
 * original inert stubs (empty result sets), so tests that never touch it are
 * unaffected.
 *
 * Call reset() in setUp()/tearDown(): the state is static and leaks otherwise.
 */
final class DbStub
{
    /** @var list<array{query: string, params: list<mixed>}> */
    public static array $calls = [];

    /** @var list<array<string, mixed>> */
    public static array $rows = [];

    public static mixed $field = null;

    /** @var array<string, mixed>|false */
    public static array|false $row = false;

    /**
     * What the next db_query() calls return, first in first out; 0 once it
     * runs dry. CS-Cart's db_query() answers a write with the new
     * AUTO_INCREMENT id or the affected-row count, which is what the
     * repository's claims read.
     *
     * @var list<int>
     */
    public static array $queryResults = [];

    public static function reset(): void
    {
        self::$calls = [];
        self::$rows = [];
        self::$field = null;
        self::$row = false;
        self::$queryResults = [];
    }

    public static function nextQueryResult(): int
    {
        return self::$queryResults === [] ? 0 : (int) array_shift(self::$queryResults);
    }

    /**
     * @param list<mixed> $params
     */
    public static function record(string $query, array $params): void
    {
        self::$calls[] = ['query' => $query, 'params' => $params];
    }

    /**
     * Statements issued so far, optionally narrowed to those containing
     * $needle (match on the raw SQL, whitespace as written).
     *
     * @return list<array{query: string, params: list<mixed>}>
     */
    public static function calls(?string $needle = null): array
    {
        if ($needle === null) {
            return self::$calls;
        }

        return array_values(array_filter(
            self::$calls,
            static fn (array $call): bool => str_contains($call['query'], $needle),
        ));
    }
}
