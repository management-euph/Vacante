<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Repository;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Repository\RowNarrowingTrait;

/**
 * Eurosite → Hotels: filtering, sorting and paging over the LISTED hotels
 * (sync_status 'active' — whitelisted destinations only; the ones outside
 * the whitelist are kept but not listed).
 *
 * Returns [$rows, $search] the CS-Cart way: $search carries page,
 * items_per_page and total_items, which is what common/pagination.tpl
 * reads, plus sort_by / sort_order / sort_order_rev for the column links.
 * The SQL pieces are public constants/methods so the filter and sort rules
 * are testable without a database.
 */
final class HotelListingRepository
{
    use RowNarrowingTrait;

    /** Pictures in the details cache, plus one for a cover image. */
    public const IMAGE_SCORE = "(COALESCE(JSON_LENGTH(c.pictures_json), 0) + IF(h.first_image <> '', 1, 0))";

    /**
     * sort_by => ORDER BY for ascending; descending reverses every part.
     * Ascending availability is best first; ascending images puts the
     * hotels without any first, so they are easy to work through.
     */
    public const SORTS = [
        'name' => ['h.name'],
        'destination' => ['city_name', 'h.name'],
        'stars' => ['h.category', 'h.name'],
        'availability' => ["FIELD(h.availability, 'IM', 'OR', 'ST', 'NONE', '')", 'h.min_price', 'h.name'],
        'images' => [self::IMAGE_SCORE, 'h.name'],
        'price' => ['(h.min_price = 0)', 'h.min_price', 'h.name'],
    ];

    public const AVAILABILITY_FILTERS = ['IM', 'OR', 'ST', 'NONE', 'unchecked'];

    public const IMAGE_FILTERS = ['with', 'without', 'not_fetched'];

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
        $sortBy = isset(self::SORTS[$sortBy]) ? $sortBy : 'availability';
        $order = strtolower(TypeCoerce::toString($params['sort_order'] ?? '')) === 'desc' ? 'desc' : 'asc';
        $availability = TypeCoerce::toString($params['availability'] ?? '');
        $images = TypeCoerce::toString($params['images'] ?? '');
        $product = TypeCoerce::toString($params['product'] ?? '');

        return [
            'page' => max(1, TypeCoerce::toInt($params['page'] ?? 1)),
            'items_per_page' => max(1, TypeCoerce::toInt($params['items_per_page'] ?? $this->defaultPerPage) ?: $this->defaultPerPage),
            'sort_by' => $sortBy,
            'sort_order' => $order,
            'sort_order_rev' => $order === 'asc' ? 'desc' : 'asc',
            'city' => strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', TypeCoerce::toString($params['city'] ?? ''))),
            'availability' => in_array($availability, self::AVAILABILITY_FILTERS, true) ? $availability : '',
            'images' => in_array($images, self::IMAGE_FILTERS, true) ? $images : '',
            'product' => in_array($product, ['yes', 'no'], true) ? $product : '',
            'q' => trim(TypeCoerce::toString($params['q'] ?? '')),
        ];
    }

    /** ORDER BY for a normalized search. */
    public static function orderBy(string $sortBy, string $order): string
    {
        $parts = self::SORTS[$sortBy] ?? self::SORTS['availability'];
        $dir = $order === 'desc' ? 'DESC' : 'ASC';

        return implode(', ', array_map(static fn (string $p): string => $p . ' ' . $dir, $parts));
    }

    /**
     * The WHERE conditions a filter adds, after the listed-hotels condition.
     * Values go through db_quote; the rest is fixed SQL.
     *
     * @param array<string, mixed> $search
     */
    public static function condition(array $search): string
    {
        $sql = '';
        $city = TypeCoerce::toString($search['city'] ?? '');
        if ($city !== '') {
            $sql .= db_quote(' AND h.city_code = ?s', $city);
        }
        $sql .= match (TypeCoerce::toString($search['availability'] ?? '')) {
            'IM' => " AND h.availability = 'IM'",
            'OR' => " AND h.availability = 'OR'",
            'ST' => " AND h.availability = 'ST'",
            'NONE' => " AND h.availability = 'NONE'",
            'unchecked' => " AND h.availability = ''",
            default => '',
        };
        $sql .= match (TypeCoerce::toString($search['images'] ?? '')) {
            'with' => ' AND ' . self::IMAGE_SCORE . ' > 0',
            'without' => ' AND c.fetched_at IS NOT NULL AND ' . self::IMAGE_SCORE . ' = 0',
            'not_fetched' => " AND c.fetched_at IS NULL AND h.first_image = ''",
            default => '',
        };
        $sql .= match (TypeCoerce::toString($search['product'] ?? '')) {
            'yes' => ' AND h.product_id > 0',
            'no' => ' AND (h.product_id IS NULL OR h.product_id = 0)',
            default => '',
        };
        $q = TypeCoerce::toString($search['q'] ?? '');
        if ($q !== '') {
            $sql .= db_quote(' AND (h.name LIKE ?l OR h.product_code LIKE ?l)', '%' . $q . '%', '%' . $q . '%');
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
            'SELECT COUNT(*) FROM ?:eurosite_hotels h ' . self::JOINS . " WHERE h.sync_status = 'active' ?p",
            $condition,
        ));
        $perPage = TypeCoerce::toInt($search['items_per_page']);
        $pages = max(1, (int) ceil($search['total_items'] / $perPage));
        $search['page'] = min(TypeCoerce::toInt($search['page']), $pages);

        $rows = db_get_array(
            'SELECT h.*, c.pictures_json, c.fetched_at AS info_fetched_at, p.status AS product_status,
                    COALESCE(ci.name, \'\') AS city_name, COALESCE(co.name, \'\') AS country_name
             FROM ?:eurosite_hotels h ' . self::JOINS . "
             WHERE h.sync_status = 'active' ?p
             ORDER BY " . self::orderBy(TypeCoerce::toString($search['sort_by']), TypeCoerce::toString($search['sort_order'])) . '
             LIMIT ?i, ?i',
            $condition,
            (TypeCoerce::toInt($search['page']) - 1) * $perPage,
            $perPage,
        );

        return [self::asRowList($rows), $search];
    }

    /**
     * Listed hotels per image state, for the Images filter chips.
     *
     * @return array{with: int, without: int, not_fetched: int}
     */
    public function imageCounts(): array
    {
        $row = self::asRow(db_get_row(
            'SELECT
                SUM(' . self::IMAGE_SCORE . ' > 0) AS with_images,
                SUM(c.fetched_at IS NOT NULL AND ' . self::IMAGE_SCORE . " = 0) AS without_images,
                SUM(c.fetched_at IS NULL AND h.first_image = '') AS not_fetched
             FROM ?:eurosite_hotels h " . self::JOINS . "
             WHERE h.sync_status = 'active'",
        ));

        return [
            'with' => TypeCoerce::toInt($row['with_images'] ?? 0),
            'without' => TypeCoerce::toInt($row['without_images'] ?? 0),
            'not_fetched' => TypeCoerce::toInt($row['not_fetched'] ?? 0),
        ];
    }

    private const JOINS = 'LEFT JOIN ?:eurosite_product_info_cache c ON c.tourop_code = h.tourop_code AND c.product_code = h.product_code
             LEFT JOIN ?:eurosite_cities ci ON ci.city_code = h.city_code
             LEFT JOIN ?:eurosite_countries co ON co.country_code = h.country_code
             LEFT JOIN ?:products p ON p.product_id = h.product_id';
}
