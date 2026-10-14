<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphProfileTypes\Db;

use Tygh\Addons\EuphProfileTypes\Cast;

/**
 * Database on top of CS-Cart's db_* functions.
 */
final class CsCartDatabase implements Database
{
    /** @var array<string, bool> */
    private array $tables = [];

    /**
     * @param string $tablePrefix config.table_prefix, needed to look tables up by name
     */
    public function __construct(private readonly string $tablePrefix)
    {
    }

    #[\Override]
    public function rows(string $query, mixed ...$params): array
    {
        $rows = [];
        foreach (Cast::array(db_get_array($query, ...$params)) as $row) {
            if (is_array($row)) {
                $rows[] = Cast::stringMap($row);
            }
        }

        return $rows;
    }

    #[\Override]
    public function row(string $query, mixed ...$params): ?array
    {
        $row = db_get_row($query, ...$params);

        return is_array($row) && $row !== [] ? Cast::stringMap($row) : null;
    }

    #[\Override]
    public function value(string $query, mixed ...$params): mixed
    {
        return db_get_field($query, ...$params);
    }

    #[\Override]
    public function column(string $query, mixed ...$params): array
    {
        return array_values(Cast::array(db_get_fields($query, ...$params)));
    }

    #[\Override]
    public function execute(string $query, mixed ...$params): int
    {
        return Cast::int(db_query($query, ...$params));
    }

    #[\Override]
    public function quote(string $pattern, mixed ...$params): string
    {
        return db_quote($pattern, ...$params);
    }

    #[\Override]
    public function tableExists(string $table): bool
    {
        $name = $this->tableName($table);
        if (!isset($this->tables[$name])) {
            $this->tables[$name] = Cast::string(db_get_field('SHOW TABLES LIKE ?s', addcslashes($name, '_%\\'))) !== '';
        }

        return $this->tables[$name];
    }

    #[\Override]
    public function columnExists(string $table, string $column): bool
    {
        if (!$this->tableExists($table)) {
            return false;
        }
        $found = db_get_array('SHOW COLUMNS FROM ?p LIKE ?s', $this->tableName($table), addcslashes($column, '_%\\'));

        return is_array($found) && $found !== [];
    }

    /** '?:users' or 'users' to the real table name. */
    private function tableName(string $table): string
    {
        return $this->tablePrefix . (str_starts_with($table, '?:') ? substr($table, 2) : $table);
    }
}
