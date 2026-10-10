<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Repository;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Repository\RowNarrowingTrait;

/**
 * Shared base for the two flat code=>name catalogs (room types, offer tags).
 * Subclasses pin the table + code column; everything else is identical.
 */
abstract class CodeNameCatalogRepository
{
    use RowNarrowingTrait;

    /** Table name WITHOUT the ?: prefix, e.g. 'eurosite_room_types'. */
    abstract protected function table(): string;

    /** The code column name, e.g. 'code' or 'tag_code'. */
    abstract protected function codeColumn(): string;

    /**
     * @param list<array{code: string, name: string}> $rows
     */
    public function upsertBatch(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }
        $now = date('Y-m-d H:i:s');
        $tuples = [];
        $params = [];
        foreach ($rows as $r) {
            $code = trim(TypeCoerce::toString($r['code']));
            if ($code === '') {
                continue;
            }
            $tuples[] = '(?s, ?s, ?s)';
            array_push($params, $code, TypeCoerce::toString($r['name']), $now);
        }
        if ($tuples === []) {
            return 0;
        }
        db_query(
            'INSERT INTO ?:' . $this->table() . ' (' . $this->codeColumn() . ', name, last_synced_at)
             VALUES ' . implode(', ', $tuples) . '
             ON DUPLICATE KEY UPDATE name = VALUES(name), last_synced_at = VALUES(last_synced_at)',
            ...$params,
        );

        return count($tuples);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findAll(): array
    {
        return self::asRowList(db_get_array('SELECT * FROM ?:' . $this->table() . ' ORDER BY name'));
    }

    public function count(): int
    {
        return TypeCoerce::toInt(db_get_field('SELECT COUNT(*) FROM ?:' . $this->table()));
    }

    public function getLastSyncedAt(): string
    {
        return TypeCoerce::toString(db_get_field('SELECT MAX(last_synced_at) FROM ?:' . $this->table()));
    }
}
