<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Repository;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Repository\RowNarrowingTrait;

/**
 * ?:eurosite_countries — the getCountryRequest catalog (86 rows live).
 */
class CountryRepository
{
    use RowNarrowingTrait;

    /**
     * @param list<array{code: string, name: string}> $countries
     */
    public function upsertBatch(array $countries): int
    {
        if ($countries === []) {
            return 0;
        }
        $now = date('Y-m-d H:i:s');
        $tuples = [];
        $params = [];
        foreach ($countries as $c) {
            $code = trim(TypeCoerce::toString($c['code']));
            if ($code === '') {
                continue;
            }
            $tuples[] = '(?s, ?s, ?s)';
            array_push($params, $code, TypeCoerce::toString($c['name']), $now);
        }
        if ($tuples === []) {
            return 0;
        }
        db_query(
            'INSERT INTO ?:eurosite_countries (country_code, name, last_synced_at)
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
        return self::asRowList(db_get_array('SELECT * FROM ?:eurosite_countries ORDER BY name'));
    }

    public function count(): int
    {
        return TypeCoerce::toInt(db_get_field('SELECT COUNT(*) FROM ?:eurosite_countries'));
    }

    /**
     * Name/code lookup for the whitelist search box.
     *
     * @return list<array<string, mixed>>
     */
    public function search(string $query, int $limit = 10): array
    {
        return self::asRowList(db_get_array(
            'SELECT country_code, name FROM ?:eurosite_countries
             WHERE name LIKE ?l OR country_code LIKE ?l
             ORDER BY name LIMIT ?i',
            "%{$query}%",
            "%{$query}%",
            $limit,
        ));
    }

    public function getLastSyncedAt(): string
    {
        return TypeCoerce::toString(db_get_field('SELECT MAX(last_synced_at) FROM ?:eurosite_countries'));
    }
}
