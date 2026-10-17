<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphPagesInBlocks\Tests\Support;

use Tygh\Addons\EuphPagesInBlocks\Db\Database;

/**
 * An in-memory Database: answers SELECT ... FROM <table> WHERE <column> = / IN
 * from its rows, applies DELETE ... WHERE <column> IN, and records every
 * query with its parameters.
 */
final class FakeDatabase implements Database
{
    /** @var list<array{string, list<mixed>}> */
    public array $queries = [];

    /**
     * @param array<string, list<array<string, mixed>>> $tables table (without the ?: prefix) => rows
     */
    public function __construct(public array $tables = [])
    {
    }

    #[\Override]
    public function column(string $query, mixed ...$params): array
    {
        $this->queries[] = [$query, array_values($params)];
        if (preg_match('/^SELECT (\w+) FROM \?:(\w+) WHERE (\w+) (?:= \?s|IN \(\?n\))$/', $query, $m) !== 1) {
            throw new \LogicException('unexpected query: ' . $query);
        }
        $values = [];
        foreach ($this->matching($m[2], $m[3], $params[0] ?? null) as $row) {
            $values[] = $row[$m[1]] ?? null;
        }

        return $values;
    }

    #[\Override]
    public function execute(string $query, mixed ...$params): int
    {
        $this->queries[] = [$query, array_values($params)];
        if (preg_match('/^DELETE FROM \?:(\w+) WHERE (\w+) IN \(\?n\)$/', $query, $m) !== 1) {
            throw new \LogicException('unexpected query: ' . $query);
        }
        $kept = [];
        $deleted = 0;
        $match = $this->matching($m[1], $m[2], $params[0] ?? null);
        foreach ($this->tables[$m[1]] ?? [] as $row) {
            if (in_array($row, $match, true)) {
                ++$deleted;
            } else {
                $kept[] = $row;
            }
        }
        $this->tables[$m[1]] = $kept;

        return $deleted;
    }

    #[\Override]
    public function tableExists(string $table): bool
    {
        return array_key_exists(str_starts_with($table, '?:') ? substr($table, 2) : $table, $this->tables);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function matching(string $table, string $column, mixed $value): array
    {
        $wanted = is_array($value) ? $value : [$value];

        return array_values(array_filter(
            $this->tables[$table] ?? [],
            static fn (array $row): bool => in_array($row[$column] ?? null, $wanted, false),
        ));
    }
}
