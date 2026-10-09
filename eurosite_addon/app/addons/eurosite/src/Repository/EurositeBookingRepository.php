<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Repository;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Repository\RowNarrowingTrait;
use Tygh\Addons\TravelCore\Repository\TravelBookingMirror;

/**
 * ?:eurosite_bookings + the shared ?:travel_bookings mirror, dual-written
 * through travel_core's TravelBookingMirror('eurosite') on every mutation
 * (sphinx/novoton pattern — the admin grid reads ONLY the mirror).
 */
class EurositeBookingRepository
{
    use RowNarrowingTrait;

    private TravelBookingMirror $mirror;

    public function __construct(?TravelBookingMirror $mirror = null)
    {
        $this->mirror = $mirror ?? new TravelBookingMirror('eurosite');
    }

    /**
     * @param array<string, mixed> $data eurosite_bookings columns
     */
    public function create(array $data): int
    {
        $data['created_at'] ??= date('Y-m-d H:i:s');
        db_query('INSERT INTO ?:eurosite_bookings ?e', $data);
        $bookingId = TypeCoerce::toInt(db_get_field('SELECT LAST_INSERT_ID()'));
        $this->mirror->upsert($bookingId, $this->toMirrorData($data));

        return $bookingId;
    }

    /**
     * @param array<string, mixed> $data Changed columns
     */
    public function update(int $bookingId, array $data): void
    {
        $data['updated_at'] = date('Y-m-d H:i:s');
        db_query('UPDATE ?:eurosite_bookings SET ?u WHERE booking_id = ?i', $data, $bookingId);
        $this->mirror->applyUpdate($bookingId, $this->toMirrorData($data));
    }

    public function linkToOrder(int $bookingId, int $orderId, int $userId = 0): void
    {
        $update = ['order_id' => $orderId];
        if ($userId > 0) {
            $update['user_id'] = $userId;
        }
        $this->update($bookingId, $update);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $bookingId): ?array
    {
        $row = self::asRow(db_get_row('SELECT * FROM ?:eurosite_bookings WHERE booking_id = ?i', $bookingId));

        return $row === [] ? null : $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findByOrderId(int $orderId): array
    {
        return self::asRowList(db_get_array('SELECT * FROM ?:eurosite_bookings WHERE order_id = ?i', $orderId));
    }

    /**
     * The meal plan of several bookings in one read, keyed by booking_id (the
     * order page's per-line decoration; the rest of the row stays unread).
     *
     * @param list<int> $bookingIds
     * @return array<int, array<string, mixed>>
     */
    public function mealsByIds(array $bookingIds): array
    {
        $ids = array_values(array_unique(array_filter($bookingIds, static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }
        $rows = [];
        foreach (self::asRowList(db_get_array('SELECT booking_id, meal_name, board_id FROM ?:eurosite_bookings WHERE booking_id IN (?n)', $ids)) as $row) {
            $rows[TypeCoerce::toInt($row['booking_id'] ?? 0)] = $row;
        }

        return $rows;
    }

    /**
     * The unified ?:travel_bookings ids (the admin "View Booking #N" link)
     * of these eurosite bookings, keyed by eurosite booking_id. Bookings
     * without a mirror row are absent.
     *
     * @param list<int> $bookingIds
     * @return array<int, int>
     */
    public function surrogateIds(array $bookingIds): array
    {
        $ids = array_values(array_unique(array_filter($bookingIds, static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }
        $map = [];
        foreach (self::asRowList(db_get_array(
            "SELECT booking_id, provider_booking_id FROM ?:travel_bookings WHERE provider = 'eurosite' AND provider_booking_id IN (?a)",
            array_map('strval', $ids),
        )) as $row) {
            $map[TypeCoerce::toInt($row['provider_booking_id'] ?? 0)] = TypeCoerce::toInt($row['booking_id'] ?? 0);
        }

        return $map;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getRecent(int $limit = 10): array
    {
        return self::asRowList(db_get_array(
            'SELECT * FROM ?:eurosite_bookings ORDER BY created_at DESC, booking_id DESC LIMIT ?i',
            $limit,
        ));
    }

    /**
     * Bookings worth re-checking at the API: submitted (api_ref set) and not
     * in a terminal local state.
     *
     * @return list<array<string, mixed>>
     */
    public function findForStatusCheck(int $limit = 50): array
    {
        return self::asRowList(db_get_array(
            "SELECT * FROM ?:eurosite_bookings
             WHERE api_ref IS NOT NULL AND api_ref <> ''
               AND status NOT IN ('cancelled', 'failed')
             ORDER BY updated_at IS NULL DESC, updated_at ASC
             LIMIT ?i",
            $limit,
        ));
    }

    public function linkToUserBySession(string $sessionId, int $userId): int
    {
        $ids = db_get_fields(
            'SELECT booking_id FROM ?:eurosite_bookings WHERE session_id = ?s AND user_id = 0',
            $sessionId,
        );
        $ids = is_array($ids) ? $ids : [];
        foreach ($ids as $id) {
            $this->update(TypeCoerce::toInt($id), ['user_id' => $userId]);
        }

        return count($ids);
    }

    public function count(): int
    {
        return TypeCoerce::toInt(db_get_field('SELECT COUNT(*) FROM ?:eurosite_bookings'));
    }

    /**
     * Map eurosite_bookings column names onto the provider-column vocabulary
     * TravelBookingMirror expects (its field map speaks novoton/sphinx
     * dialect: hotel_id, guests_data).
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function toMirrorData(array $data): array
    {
        $mapped = $data;
        if (array_key_exists('product_code', $data)) {
            $mapped['hotel_id'] = $data['product_code'];
        }
        if (array_key_exists('guests_json', $data)) {
            $mapped['guests_data'] = $data['guests_json'];
        }

        return $mapped;
    }
}
