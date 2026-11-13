<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Repository;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Repository\RowNarrowingTrait;

/**
 * Novoton → Hotels (the sync monitor): filtering, sorting and paging over
 * novoton_hotels, with each hotel's sync status and linked product.
 *
 * The status is not stored: it is read from the sync timestamps, first
 * match wins —
 *   error    the last hotelinfo request got no answer (tried after synced)
 *   unmapped no CS-Cart product (none linked, or the product was deleted)
 *   pending  never fetched, or queued for a re-sync (due after synced)
 *   active   none of the above
 *
 * Returns [$rows, $search] the CS-Cart way: $search carries page,
 * items_per_page and total_items for common/pagination.tpl, plus sort_by /
 * sort_order / sort_order_rev for the column links. The SQL pieces are
 * public so the rules are testable without a database.
 */
final class HotelListingRepository
{
    use RowNarrowingTrait;

    public const array STATUSES = ['active', 'pending', 'error', 'unmapped'];

    /** The status of a row of novoton_hotels h LEFT JOINed to products p. */
    public const string STATUS_SQL = "CASE
            WHEN h.hotelinfo_tried_at IS NOT NULL AND (h.hotelinfo_synced_at IS NULL OR h.hotelinfo_tried_at > h.hotelinfo_synced_at) THEN 'error'
            WHEN p.product_id IS NULL THEN 'unmapped'
            WHEN h.hotelinfo_synced_at IS NULL OR h.hotelinfo_due_at > h.hotelinfo_synced_at THEN 'pending'
            ELSE 'active' END";

    /**
     * sort_by => ORDER BY for ascending; descending reverses every part.
     * Ascending "synced" puts the never / longest-ago synced first; ascending
     * "status" puts the ones needing attention first.
     */
    public const array SORTS = [
        'name' => ['h.hotel_name'],
        'destination' => ['h.country', 'h.city', 'h.hotel_name'],
        'stars' => ['h.star_rating', 'h.hotel_name'],
        'synced' => ['h.hotelinfo_synced_at', 'h.hotel_name'],
        'status' => ["FIELD(" . self::STATUS_SQL . ", 'error', 'unmapped', 'pending', 'active')", 'h.hotel_name'],
        'product' => ['(p.product_id IS NULL)', 'h.product_id', 'h.hotel_name'],
    ];

    /** "Not synced in the last N days" choices. */
    public const array SYNC_AGES = [7, 30, 90];

    /** The property types the hotel sync detects (novoton_hotels.property_type). */
    public const array PROPERTY_TYPES = ['hotel', 'apartment', 'villa', 'resort', 'guest-house', 'boarding-house', 'chalet', 'hostel', 'motel', 'cabin'];

    /** The product filter: has a live product, has none, or its product was deleted. */
    public const array PRODUCT_FILTERS = ['with', 'without', 'deleted'];

    private const string JOINS = 'LEFT JOIN ?:products p ON p.product_id = h.product_id AND h.product_id > 0';

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
        $sortBy = isset(self::SORTS[$sortBy]) ? $sortBy : 'name';
        $order = strtolower(TypeCoerce::toString($params['sort_order'] ?? '')) === 'desc' ? 'desc' : 'asc';
        $status = TypeCoerce::toString($params['sync_status'] ?? '');
        $age = TypeCoerce::toInt($params['not_synced_days'] ?? 0);
        $product = TypeCoerce::toString($params['product'] ?? '');

        // Stars: several at once (stars_4=Y&stars_5=Y, the filter's checkboxes),
        // or one (stars=4, an older link); "none" is a hotel without a rating.
        $stars = [];
        for ($n = 1; $n <= 5; $n++) {
            if (TypeCoerce::toString($params['stars_' . $n] ?? '') === 'Y') {
                $stars[] = $n;
            }
        }
        $one = TypeCoerce::toInt($params['stars'] ?? 0);
        if ($one >= 1 && $one <= 5 && !in_array($one, $stars, true)) {
            $stars[] = $one;
        }
        sort($stars);
        $types = [];
        foreach (self::PROPERTY_TYPES as $type) {
            if (TypeCoerce::toString($params['property_type_' . $type] ?? '') === 'Y') {
                $types[] = $type;
            }
        }

        return [
            'page' => max(1, TypeCoerce::toInt($params['page'] ?? 1)),
            'items_per_page' => max(1, TypeCoerce::toInt($params['items_per_page'] ?? $this->defaultPerPage) ?: $this->defaultPerPage),
            'sort_by' => $sortBy,
            'sort_order' => $order,
            'sort_order_rev' => $order === 'asc' ? 'desc' : 'asc',
            'q' => trim(TypeCoerce::toString($params['q'] ?? '')),
            'country' => trim(TypeCoerce::toString($params['country'] ?? '')),
            'city' => trim(TypeCoerce::toString($params['city'] ?? '')),
            'stars' => $stars,
            'stars_none' => TypeCoerce::toString($params['stars_none'] ?? '') === 'Y',
            'property_types' => $types,
            'product' => in_array($product, self::PRODUCT_FILTERS, true) ? $product : '',
            'sync_status' => in_array($status, self::STATUSES, true) ? $status : '',
            'not_synced_days' => in_array($age, self::SYNC_AGES, true) ? $age : 0,
        ];
    }

    /** ORDER BY for a normalized search. */
    public static function orderBy(string $sortBy, string $order): string
    {
        $parts = self::SORTS[$sortBy] ?? self::SORTS['name'];
        $dir = $order === 'desc' ? 'DESC' : 'ASC';

        return implode(', ', array_map(static fn (string $p): string => $p . ' ' . $dir, $parts));
    }

    /**
     * The WHERE conditions a filter adds. Values go through db_quote; the
     * rest is fixed SQL.
     *
     * @param array<string, mixed> $search
     * @param string $now Y-m-d H:i:s, for "not synced in the last N days"
     */
    public static function condition(array $search, string $now): string
    {
        $sql = '';
        $q = TypeCoerce::toString($search['q'] ?? '');
        if ($q !== '') {
            $sql .= db_quote(' AND (h.hotel_name LIKE ?l OR h.hotel_id LIKE ?l)', '%' . $q . '%', '%' . $q . '%');
        }
        $country = TypeCoerce::toString($search['country'] ?? '');
        if ($country !== '') {
            $sql .= db_quote(' AND h.country = ?s', $country);
        }
        $city = TypeCoerce::toString($search['city'] ?? '');
        if ($city !== '') {
            $sql .= db_quote(' AND (h.city LIKE ?l OR h.region LIKE ?l)', '%' . $city . '%', '%' . $city . '%');
        }
        $stars = TypeCoerce::toIntList($search['stars'] ?? []);
        $noStars = ($search['stars_none'] ?? false) === true;
        if ($stars !== [] || $noStars) {
            $parts = [];
            if ($stars !== []) {
                $parts[] = db_quote('h.star_rating IN (?n)', $stars);
            }
            if ($noStars) {
                $parts[] = '(h.star_rating IS NULL OR h.star_rating = 0)';
            }
            $sql .= ' AND (' . implode(' OR ', $parts) . ')';
        }
        $types = array_values(array_intersect(TypeCoerce::toStringList($search['property_types'] ?? []), self::PROPERTY_TYPES));
        if ($types !== []) {
            $sql .= db_quote(' AND h.property_type IN (?a)', $types);
        }
        $sql .= match (TypeCoerce::toString($search['product'] ?? '')) {
            'with' => ' AND p.product_id IS NOT NULL',
            'without' => ' AND (h.product_id IS NULL OR h.product_id = 0)',
            'deleted' => ' AND h.product_id > 0 AND p.product_id IS NULL',
            default => '',
        };
        $status = TypeCoerce::toString($search['sync_status'] ?? '');
        if (in_array($status, self::STATUSES, true)) {
            $sql .= db_quote(' AND ' . self::STATUS_SQL . ' = ?s', $status);
        }
        $age = TypeCoerce::toInt($search['not_synced_days'] ?? 0);
        if ($age > 0) {
            $before = date('Y-m-d H:i:s', (int) strtotime($now) - $age * 86400);
            $sql .= db_quote(' AND (h.hotelinfo_synced_at IS NULL OR h.hotelinfo_synced_at < ?s)', $before);
        }

        return $sql;
    }

    /**
     * @param array<array-key, mixed> $params
     * @param string $now Y-m-d H:i:s
     *
     * @return array{0: list<array<string, mixed>>, 1: array<string, mixed>}
     */
    public function getListing(array $params, string $now): array
    {
        $search = $this->normalize($params);
        $condition = self::condition($search, $now);

        $search['total_items'] = TypeCoerce::toInt(db_get_field(
            'SELECT COUNT(*) FROM ?:novoton_hotels h ' . self::JOINS . ' WHERE 1 ?p',
            $condition,
        ));
        $perPage = TypeCoerce::toInt($search['items_per_page']);
        $pages = max(1, (int) ceil($search['total_items'] / $perPage));
        $search['page'] = min(TypeCoerce::toInt($search['page']), $pages);

        // Not h.*: hotel_data and calendar_prices_raw are large JSON no row shows.
        $rows = db_get_array(
            'SELECT h.hotel_id, h.hotel_name, h.city, h.region, h.country, h.hotel_type, h.star_rating, h.property_type,
                    h.product_id, h.hotelinfo_synced_at, h.hotelinfo_tried_at, h.hotelinfo_due_at,
                    h.hotel_list_synced_at, h.last_price_check, h.images_checked_at,
                    p.product_id AS live_product_id, p.status AS product_status, '
            . self::STATUS_SQL . ' AS sync_status
             FROM ?:novoton_hotels h ' . self::JOINS . '
             WHERE 1 ?p
             ORDER BY ' . self::orderBy(TypeCoerce::toString($search['sort_by']), TypeCoerce::toString($search['sort_order'])) . '
             LIMIT ?i, ?i',
            $condition,
            (TypeCoerce::toInt($search['page']) - 1) * $perPage,
            $perPage,
        );

        return [self::asRowList($rows), $search];
    }

    /**
     * How many hotels each status has (every hotel, no filter): the
     * sidebar's summary and the status filter's counts.
     *
     * @return array<string, int> status => count, every status present
     */
    public function statusCounts(): array
    {
        $counts = array_fill_keys(self::STATUSES, 0);
        foreach (self::asRowList(db_get_array(
            'SELECT ' . self::STATUS_SQL . ' AS sync_status, COUNT(*) AS n
             FROM ?:novoton_hotels h ' . self::JOINS . ' GROUP BY sync_status',
        )) as $row) {
            $status = TypeCoerce::toString($row['sync_status'] ?? '');
            if (isset($counts[$status])) {
                $counts[$status] = TypeCoerce::toInt($row['n'] ?? 0);
            }
        }

        return $counts;
    }

    /**
     * @return list<string> the countries that have hotels, by name
     */
    public function countries(): array
    {
        return array_values(array_filter(TypeCoerce::toStringList(db_get_fields(
            "SELECT DISTINCT country FROM ?:novoton_hotels WHERE country IS NOT NULL AND country <> '' ORDER BY country",
        ))));
    }

    /**
     * The newest sync times over every hotel: when the list and the details
     * were last read (a stalled cron shows here).
     *
     * @return array{hotel_list: string, hotelinfo: string} Y-m-d H:i:s, '' when never
     */
    public function lastSyncs(): array
    {
        $row = self::asRow(db_get_row(
            'SELECT MAX(hotel_list_synced_at) AS hotel_list, MAX(hotelinfo_synced_at) AS hotelinfo FROM ?:novoton_hotels',
        ));

        return [
            'hotel_list' => TypeCoerce::toString($row['hotel_list'] ?? ''),
            'hotelinfo' => TypeCoerce::toString($row['hotelinfo'] ?? ''),
        ];
    }

    /**
     * Queues hotels for a re-sync: the next hotelinfo run fetches every
     * hotel whose due time is after its last answer (ChangedHotelDetector).
     * Nothing is asked of Novoton here.
     *
     * @param list<string> $hotelIds
     * @param string $now Y-m-d H:i:s
     *
     * @return int how many hotels were queued
     */
    public function queueResync(array $hotelIds, string $now): int
    {
        $hotelIds = array_values(array_unique(array_filter($hotelIds, static fn (string $id): bool => $id !== '')));
        if ($hotelIds === []) {
            return 0;
        }

        return TypeCoerce::toInt(db_query(
            'UPDATE ?:novoton_hotels SET hotelinfo_due_at = ?s WHERE hotel_id IN (?a)',
            $now,
            $hotelIds,
        ));
    }

    /**
     * The last answer Novoton sent for each of these hotels (hotel_data, the
     * hotelinfo JSON), for the API error panel: a failed request leaves no
     * answer, so this is the one before it.
     *
     * @param list<string> $hotelIds
     *
     * @return array<string, string> hotel_id => JSON ('' when none)
     */
    public function lastAnswers(array $hotelIds): array
    {
        if ($hotelIds === []) {
            return [];
        }
        $answers = [];
        foreach (self::asRowList(db_get_array(
            'SELECT hotel_id, hotel_data FROM ?:novoton_hotels WHERE hotel_id IN (?a)',
            $hotelIds,
        )) as $row) {
            $answers[TypeCoerce::toString($row['hotel_id'] ?? '')] = TypeCoerce::toString($row['hotel_data'] ?? '');
        }

        return $answers;
    }

    /**
     * The existing CS-Cart products of these hotels.
     *
     * @param list<string> $hotelIds
     *
     * @return list<int>
     */
    public function productIds(array $hotelIds): array
    {
        if ($hotelIds === []) {
            return [];
        }

        return array_values(array_filter(TypeCoerce::toIntList(db_get_fields(
            'SELECT p.product_id FROM ?:novoton_hotels h JOIN ?:products p ON p.product_id = h.product_id
             WHERE h.hotel_id IN (?a)',
            $hotelIds,
        ))));
    }
}
