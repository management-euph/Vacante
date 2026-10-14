<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphProfileTypes\Db;

/**
 * The slice of CS-Cart's db_* API the add-on uses, behind an interface so
 * the services can be tested without a database. Placeholders are CS-Cart's
 * (?i, ?s, ?a, ?n, ?e, ?u, ?m, ?p, ?: for the table prefix).
 */
interface Database
{
    /**
     * @return list<array<string, mixed>>
     */
    public function rows(string $query, mixed ...$params): array;

    /**
     * @return array<string, mixed>|null
     */
    public function row(string $query, mixed ...$params): ?array;

    public function value(string $query, mixed ...$params): mixed;

    /**
     * The first column of every row.
     *
     * @return list<mixed>
     */
    public function column(string $query, mixed ...$params): array;

    /**
     * Runs a write. Returns the new AUTO_INCREMENT id for an insert, the
     * number of affected rows otherwise, as CS-Cart's db_query() does.
     */
    public function execute(string $query, mixed ...$params): int;

    public function quote(string $pattern, mixed ...$params): string;

    public function tableExists(string $table): bool;

    public function columnExists(string $table, string $column): bool;
}
