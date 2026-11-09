<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Repository;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Repository\RowNarrowingTrait;

/**
 * Eurosite → Package routes: filtering, sorting and paging over
 * ?:eurosite_package_routes, as the Hotels page does over the hotels.
 *
 * Returns [$rows, $search] the CS-Cart way: $search carries page,
 * items_per_page and total_items for common/pagination.tpl, and sort_by /
 * sort_order / sort_order_rev for the column links. The SQL pieces are
 * public so the rules are testable without a database.
 */
final class PackageRouteListing
{
    use RowNarrowingTrait;

    /** sort_by => ORDER BY for ascending; descending reverses every part. */
    public const array SORTS = [
        'arrival' => ['r.country_name', 'r.city_name', 'r.dep_city_name', 'r.transport'],
        'departure' => ['r.dep_city_name', 'r.country_name', 'r.city_name', 'r.transport'],
        'transport' => ['r.transport', 'r.country_name', 'r.city_name', 'r.dep_city_name'],
        'dates' => ['r.first_date', 'r.country_name', 'r.city_name'],
        'last_synced_at' => ['r.last_synced_at', 'r.country_name', 'r.city_name'],
    ];

    public const array TRANSPORTS = ['plane', 'bus'];

    public function __construct(private readonly int $defaultPerPage = 50)
    {
    }

    /**
     * @param array<array-key, mixed> $params $_REQUEST
     *
     * @return array<string, mixed> the normalized search
     */
    public function normalize(array $params): array
    {
        $sortBy = TypeCoerce::toString($params['sort_by'] ?? '');
        $sortBy = isset(self::SORTS[$sortBy]) ? $sortBy : 'arrival';
        $order = strtolower(TypeCoerce::toString($params['sort_order'] ?? '')) === 'desc' ? 'desc' : 'asc';
        $transport = TypeCoerce::toString($params['transport'] ?? '');

        return [
            'page' => max(1, TypeCoerce::toInt($params['page'] ?? 1)),
            'items_per_page' => max(1, TypeCoerce::toInt($params['items_per_page'] ?? $this->defaultPerPage) ?: $this->defaultPerPage),
            'sort_by' => $sortBy,
            'sort_order' => $order,
            'sort_order_rev' => $order === 'asc' ? 'desc' : 'asc',
            'transport' => in_array($transport, self::TRANSPORTS, true) ? $transport : '',
            'departure' => trim(TypeCoerce::toString($params['departure'] ?? '')),
            'arrival' => trim(TypeCoerce::toString($params['arrival'] ?? '')),
        ];
    }

    /** ORDER BY for a normalized search. */
    public static function orderBy(string $sortBy, string $order): string
    {
        $parts = self::SORTS[$sortBy] ?? self::SORTS['arrival'];
        $dir = $order === 'desc' ? 'DESC' : 'ASC';

        return implode(', ', array_map(static fn (string $p): string => $p . ' ' . $dir, $parts));
    }

    /**
     * The WHERE conditions a filter adds. The departure matches the city's
     * name or code; the arrival also its zone and country.
     *
     * @param array<string, mixed> $search
     */
    public static function condition(array $search): string
    {
        $sql = '';
        $transport = TypeCoerce::toString($search['transport'] ?? '');
        if ($transport !== '') {
            $sql .= db_quote(' AND r.transport = ?s', $transport);
        }
        $departure = TypeCoerce::toString($search['departure'] ?? '');
        if ($departure !== '') {
            $like = '%' . $departure . '%';
            $sql .= db_quote(' AND (r.dep_city_name LIKE ?l OR r.dep_city_code LIKE ?l)', $like, $like);
        }
        $arrival = TypeCoerce::toString($search['arrival'] ?? '');
        if ($arrival !== '') {
            $like = '%' . $arrival . '%';
            $sql .= db_quote(
                ' AND (r.city_name LIKE ?l OR r.city_code LIKE ?l OR r.zone_name LIKE ?l OR r.country_name LIKE ?l)',
                $like,
                $like,
                $like,
                $like,
            );
        }

        return $sql;
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return array{0: list<array<string, mixed>>, 1: array<string, mixed>}
     */
    public function getListing(array $params): array
    {
        $search = $this->normalize($params);
        $condition = self::condition($search);

        $search['total_items'] = TypeCoerce::toInt(db_get_field(
            'SELECT COUNT(*) FROM ?:eurosite_package_routes r WHERE 1 ?p',
            $condition,
        ));
        $perPage = TypeCoerce::toInt($search['items_per_page']);
        $pages = max(1, (int) ceil($search['total_items'] / $perPage));
        $search['page'] = min(TypeCoerce::toInt($search['page']), $pages);

        $rows = db_get_array(
            'SELECT r.* FROM ?:eurosite_package_routes r
             WHERE 1 ?p
             ORDER BY ' . self::orderBy(TypeCoerce::toString($search['sort_by']), TypeCoerce::toString($search['sort_order'])) . '
             LIMIT ?i, ?i',
            $condition,
            (TypeCoerce::toInt($search['page']) - 1) * $perPage,
            $perPage,
        );

        return [self::asRowList($rows), $search];
    }
}
