<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Repository;

use Tygh\Addons\TravelCore\Enums\HotelAvailabilityState;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\AvailabilityGate;

/**
 * Reads ?:travel_hotel_availability for the Hotel availability page and its
 * CSV (docs/novoton-availability-gaps-plan.md §6): the filtered, paged list,
 * the counts per supplier and state, and the export rows. The writes stay in
 * HotelAvailabilityRepository.
 */
final class HotelAvailabilityListing
{
    /** Months-without-an-offer choices on the page. */
    public const array OFFER_MONTHS = [3, 6, 12, 18, 24];

    /**
     * The product filter: what the availability gate did or would do, and
     * the admin's decisions.
     */
    public const array GATE_FILTERS = ['would_hide', 'hidden', 'released', 'override'];

    /** Sort orders: name => ORDER BY. */
    private const array SORTS = [
        'hotel' => 'a.provider, a.hotel_name, a.hotel_id',
        // Longest without an offer first; never offered before everything.
        'offer' => 'a.offer_seen_at IS NOT NULL, a.offer_seen_at, a.hotel_name',
        'changed' => 'a.state_since IS NULL, a.state_since DESC, a.hotel_name',
    ];

    /** Most rows one CSV holds. */
    public const int EXPORT_LIMIT = 20000;

    /** The CSV columns: header => record column. */
    public const array CSV_COLUMNS = [
        'provider' => 'provider',
        'hotel_id' => 'hotel_id',
        'hotel_name' => 'hotel_name',
        'product_id' => 'product_id',
        'destination' => 'destination_key',
        'state' => 'state',
        'state_since' => 'state_since',
        'reason' => 'state_reason',
        'next_season_published' => 'season_published',
        'last_offer_seen' => 'offer_seen_at',
        'last_offer_stay' => 'offer_stay_date',
        'last_check' => 'offer_checked_at',
        'prices_from' => 'contract_from',
        'prices_to' => 'contract_to',
        'last_priced_date' => 'contract_last_date',
        'last_booked_stay' => 'last_booking_check_in',
        'last_in_hotel_list' => 'listed_last_seen_at',
        'lists_missed' => 'listed_missing_runs',
        'product_status' => 'product_status',
        'would_hide' => 'would_hide',
        'hidden_by_the_rules' => 'gate_hidden',
        'left_as_set_by_hand' => 'gate_released',
        'decision' => 'override',
    ];

    /** The records with their product's status, as every read here wants them. */
    private const string FROM = '?:travel_hotel_availability a LEFT JOIN ?:products p ON p.product_id = a.product_id';

    /** A record, its product's status ('' without one) and whether the gate would hide it now. */
    private const string COLUMNS = "a.*, COALESCE(p.status, '') AS product_status, ?p AS would_hide";

    /**
     * The page's filters, cleaned: unknown states and month counts are dropped.
     *
     * @param array<string, mixed> $params the request
     *
     * @return array{page: int, items_per_page: int, provider: string, state: string, no_offer_months: int, gate: string, q: string, sort_by: string}
     */
    public static function normalize(array $params): array
    {
        $state = TypeCoerce::toString($params['state'] ?? '');
        $months = TypeCoerce::toInt($params['no_offer_months'] ?? 0);
        $gate = TypeCoerce::toString($params['gate'] ?? '');
        $sort = TypeCoerce::toString($params['sort_by'] ?? '');

        return [
            'page' => max(1, TypeCoerce::toInt($params['page'] ?? 1)),
            'items_per_page' => min(500, max(1, TypeCoerce::toInt($params['items_per_page'] ?? 50))),
            'provider' => (string) preg_replace('/[^a-z0-9_]/', '', TypeCoerce::toString($params['provider'] ?? '')),
            'state' => HotelAvailabilityState::tryFrom($state) !== null ? $state : '',
            'no_offer_months' => in_array($months, self::OFFER_MONTHS, true) ? $months : 0,
            'gate' => in_array($gate, self::GATE_FILTERS, true) ? $gate : '',
            'q' => trim(TypeCoerce::toString($params['q'] ?? '')),
            'sort_by' => isset(self::SORTS[$sort]) ? $sort : 'hotel',
        ];
    }

    /**
     * The WHERE fragment (after "WHERE 1") for the filters.
     *
     * @param array{provider: string, state: string, no_offer_months: int, gate?: string, q: string} $filters
     */
    public static function condition(array $filters, \DateTimeImmutable $now): string
    {
        $condition = '';
        if ($filters['provider'] !== '') {
            $condition .= db_quote(' AND a.provider = ?s', $filters['provider']);
        }
        if ($filters['state'] !== '') {
            $condition .= db_quote(' AND a.state = ?s', $filters['state']);
        }
        if ($filters['no_offer_months'] > 0) {
            $cutoff = $now->modify('-' . $filters['no_offer_months'] . ' months')->format('Y-m-d H:i:s');
            $condition .= db_quote(' AND (a.offer_seen_at IS NULL OR a.offer_seen_at < ?s)', $cutoff);
        }
        $condition .= match ($filters['gate'] ?? '') {
            'would_hide' => ' AND ' . self::wouldHide(),
            'hidden' => " AND a.gate_hidden = 'Y'",
            'released' => " AND a.gate_released = 'Y'",
            'override' => " AND a.override <> ''",
            default => '',
        };
        if ($filters['q'] !== '') {
            $like = '%' . $filters['q'] . '%';
            $condition .= db_quote(
                ' AND (a.hotel_name LIKE ?l OR a.destination_key LIKE ?l OR a.hotel_id = ?s)',
                $like,
                $like,
                $filters['q'],
            );
        }

        return $condition;
    }

    /**
     * One page of records and the filters with total_items, which
     * common/pagination.tpl reads from $search.
     *
     * @param array<string, mixed> $params the request
     *
     * @return array{0: list<array<string, mixed>>, 1: array{page: int, items_per_page: int, provider: string, state: string, no_offer_months: int, gate: string, q: string, sort_by: string, total_items: int}}
     */
    public function getListing(array $params, \DateTimeImmutable $now): array
    {
        HotelAvailabilityRepository::ensureTables();
        $search = self::normalize($params);
        $condition = self::condition($search, $now);

        $search['total_items'] = TypeCoerce::toInt(db_get_field(
            'SELECT COUNT(*) FROM ' . self::FROM . ' WHERE 1 ?p',
            $condition,
        ));
        $rows = TypeCoerce::toRowList(db_get_array(
            'SELECT ' . self::COLUMNS . ' FROM ' . self::FROM . ' WHERE 1 ?p ORDER BY ?p LIMIT ?i, ?i',
            self::wouldHide(),
            $condition,
            self::SORTS[$search['sort_by']],
            ($search['page'] - 1) * $search['items_per_page'],
            $search['items_per_page'],
        ));

        return [$rows, $search];
    }

    /**
     * Per supplier, what the gate did and would do, and the admin's
     * decisions, for the page's tiles.
     *
     * @return array<string, array{would_hide: int, hidden: int, released: int, override: int}>
     */
    public function gateCounts(): array
    {
        HotelAvailabilityRepository::ensureTables();
        $counts = [];
        foreach (TypeCoerce::toRowList(db_get_array(
            "SELECT a.provider,
                    SUM(?p) AS would_hide,
                    SUM(a.gate_hidden = 'Y') AS hidden,
                    SUM(a.gate_released = 'Y') AS released,
                    SUM(a.override <> '') AS override
             FROM " . self::FROM . ' GROUP BY a.provider',
            self::wouldHide(),
        )) as $row) {
            $counts[TypeCoerce::toString($row['provider'] ?? '')] = [
                'would_hide' => TypeCoerce::toInt($row['would_hide'] ?? 0),
                'hidden' => TypeCoerce::toInt($row['hidden'] ?? 0),
                'released' => TypeCoerce::toInt($row['released'] ?? 0),
                'override' => TypeCoerce::toInt($row['override'] ?? 0),
            ];
        }

        return $counts;
    }

    /**
     * SQL: this record's product is Active and the gate would hide it
     * (AvailabilityGate::hides()), not already hidden or let go by it. Per
     * record: a product with several records hides only when all of them do.
     */
    public static function wouldHide(): string
    {
        return "(a.product_id > 0 AND p.status = 'A' AND a.gate_hidden = 'N' AND a.gate_released = 'N' AND " . AvailabilityGate::hides() . ')';
    }

    /**
     * Hotels per supplier and state, every state present (0 when none).
     *
     * @return array<string, array<string, int>> provider => state => hotels
     */
    public function counts(): array
    {
        HotelAvailabilityRepository::ensureTables();
        $empty = array_fill_keys(array_map(static fn (HotelAvailabilityState $s): string => $s->value, HotelAvailabilityState::cases()), 0);

        $counts = [];
        foreach (TypeCoerce::toRowList(db_get_array(
            'SELECT provider, state, COUNT(*) AS hotels FROM ?:travel_hotel_availability GROUP BY provider, state',
        )) as $row) {
            $provider = TypeCoerce::toString($row['provider'] ?? '');
            $state = TypeCoerce::toString($row['state'] ?? '');
            $counts[$provider] ??= $empty;
            if (isset($empty[$state])) {
                $counts[$provider][$state] += TypeCoerce::toInt($row['hotels'] ?? 0);
            }
        }

        return $counts;
    }

    /**
     * Every record the filters match, for the CSV, up to EXPORT_LIMIT.
     *
     * @param array<string, mixed> $params the request
     *
     * @return list<array<string, mixed>>
     */
    public function exportRows(array $params, \DateTimeImmutable $now): array
    {
        HotelAvailabilityRepository::ensureTables();
        $filters = self::normalize($params);

        return TypeCoerce::toRowList(db_get_array(
            'SELECT ' . self::COLUMNS . ' FROM ' . self::FROM . ' WHERE 1 ?p ORDER BY ?p LIMIT ?i',
            self::wouldHide(),
            self::condition($filters, $now),
            self::SORTS[$filters['sort_by']],
            self::EXPORT_LIMIT,
        ));
    }

    /**
     * The CSV text: semicolon-separated, which Excel opens in columns under a
     * Romanian locale, with a UTF-8 BOM so it shows diacritics. States and
     * reasons stay as codes, the same in every language; the page lists
     * what each means.
     *
     * @param list<array<string, mixed>> $rows
     */
    public static function csv(array $rows): string
    {
        $out = fopen('php://temp', 'r+');
        if ($out === false) {
            return '';
        }
        fputcsv($out, array_keys(self::CSV_COLUMNS), ';', '"', '');
        foreach ($rows as $row) {
            $line = [];
            foreach (self::CSV_COLUMNS as $column) {
                $value = TypeCoerce::toString($row[$column] ?? '');
                // A supplier's name starting with = + @ must not run as a formula.
                $line[] = preg_match('/^[=+@\t\r]/', $value) === 1 ? "'" . $value : $value;
            }
            fputcsv($out, $line, ';', '"', '');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return "\u{FEFF}" . $csv;
    }
}
