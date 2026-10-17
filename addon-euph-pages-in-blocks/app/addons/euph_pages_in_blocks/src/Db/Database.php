<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphPagesInBlocks\Db;

/**
 * The slice of CS-Cart's db_* API the add-on uses, behind an interface so
 * the services can be tested without a database. Placeholders are CS-Cart's
 * (?i, ?s, ?a, ?n, ?: for the table prefix).
 */
interface Database
{
    /**
     * The first column of every row.
     *
     * @return list<mixed>
     */
    public function column(string $query, mixed ...$params): array;

    /**
     * Runs a write; returns the number of affected rows, as CS-Cart's
     * db_query() does.
     */
    public function execute(string $query, mixed ...$params): int;

    public function tableExists(string $table): bool;
}
