<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphProfileTypes\Tests\Support;

use Tygh\Addons\EuphProfileTypes\Db\Database;

/**
 * Database for tests: reads answer by the first matching fragment of the
 * query, writes are recorded.
 */
final class FakeDatabase implements Database
{
    /** @var array<string, list<array<string, mixed>>|\Closure> query fragment => rows */
    public array $rows = [];

    /** @var array<string, mixed> query fragment => value */
    public array $values = [];

    /** @var array<string, list<mixed>> query fragment => column */
    public array $columns = [];

    /** @var list<int> ids the next INSERTs return */
    public array $insertIds = [];

    /** @var list<string> */
    public array $tables = [];

    /** @var array<string, list<string>> table => columns */
    public array $tableColumns = [];

    /** @var list<array{query: string, params: list<mixed>}> */
    public array $executed = [];

    /** @var list<string> */
    public array $read = [];

    public function rows(string $query, mixed ...$params): array
    {
        $this->read[] = $query;
        $rows = $this->match($this->rows, $query, $params);

        return is_array($rows) ? array_values($rows) : [];
    }

    public function row(string $query, mixed ...$params): ?array
    {
        return $this->rows($query, ...$params)[0] ?? null;
    }

    public function value(string $query, mixed ...$params): mixed
    {
        $this->read[] = $query;

        return $this->match($this->values, $query, $params);
    }

    public function column(string $query, mixed ...$params): array
    {
        $this->read[] = $query;
        $column = $this->match($this->columns, $query, $params);

        return is_array($column) ? array_values($column) : [];
    }

    public function execute(string $query, mixed ...$params): int
    {
        $this->executed[] = ['query' => $query, 'params' => array_values($params)];
        if (str_starts_with(ltrim($query), 'INSERT INTO') && $this->insertIds !== []) {
            return array_shift($this->insertIds);
        }

        return 1;
    }

    public function quote(string $pattern, mixed ...$params): string
    {
        return $pattern;
    }

    public function tableExists(string $table): bool
    {
        return in_array($table, $this->tables, true);
    }

    public function columnExists(string $table, string $column): bool
    {
        return in_array($column, $this->tableColumns[$table] ?? [], true);
    }

    /**
     * The writes whose query contains $needle.
     *
     * @return list<array{query: string, params: list<mixed>}>
     */
    public function writes(string $needle): array
    {
        return array_values(array_filter($this->executed, static fn (array $write): bool => str_contains($write['query'], $needle)));
    }

    /**
     * @param array<string, mixed> $map
     * @param array<array-key, mixed> $params
     */
    private function match(array $map, string $query, array $params): mixed
    {
        foreach ($map as $fragment => $answer) {
            if (str_contains($query, $fragment)) {
                return $answer instanceof \Closure ? $answer(...array_values($params)) : $answer;
            }
        }

        return null;
    }
}
