<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphPagesInBlocks\Db;

use Tygh\Addons\EuphPagesInBlocks\Cast;

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
    public function column(string $query, mixed ...$params): array
    {
        $values = db_get_fields($query, ...$params);

        return is_array($values) ? array_values($values) : [];
    }

    #[\Override]
    public function execute(string $query, mixed ...$params): int
    {
        return Cast::int(db_query($query, ...$params));
    }

    #[\Override]
    public function tableExists(string $table): bool
    {
        $name = $this->tablePrefix . (str_starts_with($table, '?:') ? substr($table, 2) : $table);
        if (!isset($this->tables[$name])) {
            $this->tables[$name] = Cast::string(db_get_field('SHOW TABLES LIKE ?s', addcslashes($name, '_%\\'))) !== '';
        }

        return $this->tables[$name];
    }
}
