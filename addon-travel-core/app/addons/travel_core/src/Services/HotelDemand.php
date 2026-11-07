<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Services;

use Tygh\Addons\TravelCore\Helpers\RegistryCoerce;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Repository\AlternativeRequestRepository;

/**
 * How much customers want each hotel (docs/novoton-availability-gaps-plan.md
 * §7.2, the purchasing list): its product page's views, the "notify me"
 * requests waiting for it and the alternatives requests of the last 12
 * months. Kept on each availability record by the weekly review, so the
 * Hotel availability page sorts and exports by it without reading other
 * tables.
 *
 * Views are CS-Cart's own count (?:product_popularity.viewed, once per
 * visit, all time); a store without that table counts none. Requests are
 * read per supplier and matched here, not joined in SQL, so tables created
 * with different collations never meet in one query.
 */
final class HotelDemand
{
    /** Months of alternatives requests that count. */
    public const int REQUEST_MONTHS = 12;

    /** Ids per IN (...) list. */
    private const int CHUNK = 500;

    /**
     * One supplier's records: their demand now, written where it changed.
     *
     * @param list<array<string, mixed>> $rows the supplier's records (provider, hotel_id, product_id and the demand columns)
     *
     * @return int records whose demand changed
     */
    public function refresh(string $provider, array $rows, \DateTimeImmutable $now): int
    {
        if ($rows === []) {
            return 0;
        }
        $views = $this->views(array_values(array_unique(array_filter(
            array_map(static fn (array $row): int => TypeCoerce::toInt($row['product_id'] ?? 0), $rows),
            static fn (int $id): bool => $id > 0,
        ))));
        [$waiting, $requests] = $this->requests($provider, $now);

        $changed = 0;
        foreach ($rows as $row) {
            $hotelId = TypeCoerce::toString($row['hotel_id'] ?? '');
            $demand = [
                'demand_views' => $views[TypeCoerce::toInt($row['product_id'] ?? 0)] ?? 0,
                'demand_waiting' => min(65535, $waiting[$hotelId] ?? 0),
                'demand_requests' => min(65535, $requests[$hotelId] ?? 0),
            ];
            $stored = [];
            foreach (array_keys($demand) as $column) {
                $stored[$column] = TypeCoerce::toInt($row[$column] ?? 0);
            }
            if ($demand === $stored) {
                continue;
            }
            db_query(
                'UPDATE ?:travel_hotel_availability SET ?u WHERE provider = ?s AND hotel_id = ?s',
                $demand,
                $provider,
                $hotelId,
            );
            $changed++;
        }

        return $changed;
    }

    /**
     * Each product's page views, from CS-Cart's popularity table when the
     * store has it.
     *
     * @param list<int> $productIds
     *
     * @return array<int, int> product id => views
     */
    private function views(array $productIds): array
    {
        $exists = $productIds !== [] && TypeCoerce::toInt(db_get_field(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?s',
            RegistryCoerce::string('config.table_prefix') . 'product_popularity',
        )) > 0;
        if (!$exists) {
            return [];
        }
        $views = [];
        foreach (array_chunk($productIds, self::CHUNK) as $chunk) {
            $found = db_get_hash_single_array(
                'SELECT product_id, viewed FROM ?:product_popularity WHERE product_id IN (?n)',
                ['product_id', 'viewed'],
                $chunk,
            );
            // Product ids come back as int keys.
            foreach (is_array($found) ? $found : [] as $id => $viewed) {
                $views[TypeCoerce::toInt($id)] = max(0, TypeCoerce::toInt($viewed));
            }
        }

        return $views;
    }

    /**
     * The supplier's "notify me" requests still waiting, and its
     * alternatives requests of the last REQUEST_MONTHS, per hotel id.
     *
     * @return array{0: array<string, int>, 1: array<string, int>}
     */
    private function requests(string $provider, \DateTimeImmutable $now): array
    {
        AlternativeRequestRepository::ensureTable();
        $waiting = [];
        $requests = [];
        foreach (TypeCoerce::toRowList(db_get_array(
            "SELECT hotel_id,
                    SUM(request_type = 'notify' AND status = 'pending') AS waiting,
                    SUM(request_type <> 'notify' AND created_at >= ?s) AS requests
             FROM ?:travel_alternative_requests
             WHERE provider = ?s
             GROUP BY hotel_id",
            $now->modify('-' . self::REQUEST_MONTHS . ' months')->format('Y-m-d H:i:s'),
            $provider,
        )) as $row) {
            $hotelId = TypeCoerce::toString($row['hotel_id'] ?? '');
            $waiting[$hotelId] = TypeCoerce::toInt($row['waiting'] ?? 0);
            $requests[$hotelId] = TypeCoerce::toInt($row['requests'] ?? 0);
        }

        return [$waiting, $requests];
    }
}
