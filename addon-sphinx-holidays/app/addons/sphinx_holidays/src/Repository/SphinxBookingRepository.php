<?php

declare(strict_types=1);

/**
 * Sphinx Holidays - Booking Repository
 *
 * Centralized database access for sphinx_bookings data.
 * All writes to sphinx_bookings MUST go through this repository
 * to ensure dual-write consistency with travel_bookings.
 *
 * @package SphinxHolidays
 * @since   1.1.0
 */

namespace Tygh\Addons\SphinxHolidays\Repository;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Repository\RowNarrowingTrait;

class SphinxBookingRepository
{
    use RowNarrowingTrait;

    /**
     * Find booking by ID (raw DB row).
     * @return array<string, mixed>|null
     */
    public function findById(int $booking_id): ?array
    {
        $booking = self::asRow(db_get_row('SELECT * FROM ?:sphinx_bookings WHERE booking_id = ?i', $booking_id));
        return $booking === [] ? null : $booking;
    }

    /**
     * Cart-stage booking with ownership check (user OR session match) — the
     * guard behind the guest-facing edit_booking/update_booking modes.
     * order_id = 0 only: a booking already attached to an order must not be
     * editable through a stale cart link.
     *
     * user_id only counts for a real account (> 0) and session_id only when
     * non-empty: guest bookings are stored with user_id = 0, so matching an
     * anonymous visitor's user_id 0 would hand them every guest booking.
     *
     * @return array<string, mixed>|null
     */
    public function findByIdWithOwnership(int $booking_id, int $user_id, string $session_id): ?array
    {
        $hasUser = $user_id > 0;
        $hasSession = trim($session_id) !== '';
        if ($booking_id <= 0 || (!$hasUser && !$hasSession)) {
            return null;
        }

        $sql = 'SELECT * FROM ?:sphinx_bookings WHERE booking_id = ?i AND order_id = 0 AND ';
        if ($hasUser && $hasSession) {
            $row = self::asRow(db_get_row($sql . '(user_id = ?i OR session_id = ?s)', $booking_id, $user_id, $session_id));
        } elseif ($hasUser) {
            $row = self::asRow(db_get_row($sql . 'user_id = ?i', $booking_id, $user_id));
        } else {
            $row = self::asRow(db_get_row($sql . 'session_id = ?s', $booking_id, $session_id));
        }

        return $row === [] ? null : $row;
    }

    /**
     * Find bookings by order ID.
     * @return list<array<string, mixed>>
     */
    public function findByOrderId(int $order_id): array
    {
        return self::asRowList(db_get_array(
            'SELECT * FROM ?:sphinx_bookings WHERE order_id = ?i ORDER BY booking_id',
            $order_id,
        ));
    }

    /**
     * Check if a pending duplicate booking exists for the given offer.
     *
     * Used in circuit/experience/package add-to-cart to prevent double bookings
     * where the offer is already linked to an order and still pending.
     *
     * @param string $offerId The offer ID to check
     * @param string $pendingStatus The status considered "pending"
     * @return int|null Booking ID if duplicate exists, null otherwise
     */
    public function findPendingDuplicateByOffer(string $offerId, string $pendingStatus): ?int
    {
        $id = TypeCoerce::toInt(db_get_field(
            'SELECT booking_id FROM ?:sphinx_bookings WHERE offer_id = ?s AND order_id > 0 AND status = ?s LIMIT 1',
            $offerId,
            $pendingStatus,
        ));
        return $id > 0 ? $id : null;
    }

    /**
     * Find existing unassigned booking matching hotel + dates + holder (for dedup in add_to_cart).
     */
    public function findRecentUnassigned(string $hotel_id, string $check_in, string $check_out, string $holder_name): ?int
    {
        $id = TypeCoerce::toInt(db_get_field(
            'SELECT booking_id FROM ?:sphinx_bookings
             WHERE order_id = 0 AND hotel_id = ?s AND check_in = ?s AND check_out = ?s
               AND holder_name = ?s AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
             LIMIT 1',
            $hotel_id,
            $check_in,
            $check_out,
            $holder_name,
        ));
        return $id > 0 ? $id : null;
    }

    /**
     * Create a new sphinx booking with dual-write to travel_bookings.
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        $data = self::filterNullValues($data);

        return $this->withTransaction(function () use ($data): int {
            $booking_id = TypeCoerce::toInt(db_query('INSERT INTO ?:sphinx_bookings ?e', $data));

            if ($booking_id > 0) {
                $this->syncToTravelBookings($booking_id, $data);
            }

            return $booking_id;
        });
    }

    /**
     * Update an existing sphinx booking with dual-write to travel_bookings.
     * @param array<string, mixed> $data
     */
    public function update(int $booking_id, array $data): bool
    {
        $data = self::filterNullValues($data);

        return $this->withTransaction(function () use ($booking_id, $data): bool {
            // db_query() returns affected rows for UPDATE. A return of 0 means
            // "query succeeded but no rows changed" (data identical) — NOT
            // failure. Gating the mirror sync on it desynced travel_bookings:
            // reconcile re-writing an already-set sphinx order_id affected 0
            // rows, the sync was skipped, and the unified grid kept showing
            // Order ID "-" forever (novoton's update() documents the same
            // trap). Sync unconditionally; the mirror UPDATE is idempotent.
            db_query(
                'UPDATE ?:sphinx_bookings SET ?u WHERE booking_id = ?i',
                $data,
                $booking_id,
            );

            $this->syncUpdateToTravelBookings($booking_id, $data);

            return true;
        });
    }

    /**
     * Link booking to an order and update status.
     */
    public function linkToOrder(int $booking_id, int $order_id, string $status = ''): void
    {
        $data = ['order_id' => $order_id];
        if (!empty($status)) {
            $data['status'] = $status;
        }
        $this->update($booking_id, $data);
    }

    /**
     * Store API booking reference and response after successful API submission.
     */
    public function updateApiResponse(int $booking_id, string $ref, string $response): void
    {
        $this->update($booking_id, [
            'api_booking_ref' => $ref,
            'api_response' => $response,
        ]);
    }

    /**
     * Link unassigned bookings to a user by session ID.
     *
     * Used after login/registration to claim guest bookings.
     *
     * @return int Number of bookings linked
     */
    public function linkToUserBySession(int $user_id, string $session_id): int
    {
        // An empty session would claim every session-less guest booking.
        if ($user_id <= 0 || trim($session_id) === '') {
            return 0;
        }

        $affected = TypeCoerce::toInt(db_query(
            'UPDATE ?:sphinx_bookings SET user_id = ?i WHERE session_id = ?s AND user_id = 0 AND order_id = 0',
            $user_id,
            $session_id,
        ));

        if ($affected > 0) {
            $id_strings = self::asStringList(db_get_fields(
                'SELECT booking_id FROM ?:sphinx_bookings WHERE session_id = ?s AND user_id = ?i',
                $session_id,
                $user_id,
            ));
            if (!empty($id_strings)) {
                db_query(
                    "UPDATE ?:travel_bookings SET user_id = ?i WHERE provider = 'sphinx' AND provider_booking_id IN (?a)",
                    $user_id,
                    $id_strings,
                );
            }
        }

        return $affected;
    }

    /**
     * Delete booking from both tables.
     */
    public function delete(int $booking_id): bool
    {
        return $this->withTransaction(function () use ($booking_id): bool {
            db_query(
                "DELETE FROM ?:travel_bookings WHERE provider = 'sphinx' AND provider_booking_id = ?s",
                (string) $booking_id,
            );
            return (bool) db_query('DELETE FROM ?:sphinx_bookings WHERE booking_id = ?i', $booking_id);
        });
    }

    /**
     * Delete orphan bookings (not linked to orders, older than X hours).
     */
    public function deleteOrphans(int $hours = 24): int
    {
        db_query(
            "DELETE tb FROM ?:travel_bookings tb
             INNER JOIN ?:sphinx_bookings sb ON tb.provider_booking_id = CAST(sb.booking_id AS CHAR)
             WHERE tb.provider = 'sphinx' AND sb.order_id = 0
               AND sb.created_at < DATE_SUB(NOW(), INTERVAL ?i HOUR)",
            $hours,
        );

        return TypeCoerce::toInt(db_query(
            'DELETE FROM ?:sphinx_bookings
             WHERE order_id = 0
               AND created_at < DATE_SUB(NOW(), INTERVAL ?i HOUR)',
            $hours,
        ));
    }

    /**
     * Sync a sphinx booking to the shared travel_bookings table.
     *
     * Uses INSERT ... ON DUPLICATE KEY UPDATE for atomicity.
     * Relies on UNIQUE KEY uq_provider_booking(provider, provider_booking_id).
     * @param array<string, mixed> $data
     */
    private function syncToTravelBookings(int $booking_id, array $data): void
    {
        // Delegates to travel_core's TravelBookingMirror — the shared home
        // of the once-duplicated field map, upsert SQL and guests_json
        // string-coercion guard (which now also covers the update path).
        (new \Tygh\Addons\TravelCore\Repository\TravelBookingMirror('sphinx'))->upsert($booking_id, $data);
    }

    /**
     * Sync partial updates from sphinx_bookings to travel_bookings.
     *
     * Only syncs fields that travel_bookings actually stores.
     * @param array<string, mixed> $data
     */
    private function syncUpdateToTravelBookings(int $booking_id, array $data): void
    {
        (new \Tygh\Addons\TravelCore\Repository\TravelBookingMirror('sphinx'))->applyUpdate($booking_id, $data);
    }

    /**
     * Execute a callback inside a database transaction.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function withTransaction(callable $callback): mixed
    {
        db_query('START TRANSACTION');
        try {
            $result = $callback();
            db_query('COMMIT');
            return $result;
        } catch (\Throwable $e) {
            db_query('ROLLBACK');
            throw $e;
        }
    }

    /**
     * Direct update to sphinx_bookings only (bypasses travel_bookings sync).
     * Use for fields that only exist in sphinx_bookings (e.g., payment_terms_json).
     * @param array<string, mixed> $data
     */
    public function updateDirect(int $booking_id, array $data): bool
    {
        $data = self::filterNullValues($data);
        return (bool) db_query(
            'UPDATE ?:sphinx_bookings SET ?u WHERE booking_id = ?i',
            $data,
            $booking_id,
        );
    }

    /**
     * Find bookings eligible for status check (linked to order, non-terminal, recent).
     *
     * @param list<string> $terminalStatuses Statuses to exclude
     * @param int $daysBack How many days back to look
     * @return list<array<string, mixed>>
     */
    public function findForStatusCheck(array $terminalStatuses, int $daysBack = 90): array
    {
        return self::asRowList(db_get_array(
            'SELECT booking_id, order_id, hotel_name, room_type, status, api_booking_ref
             FROM ?:sphinx_bookings
             WHERE order_id > 0
               AND status NOT IN (?a)
               AND created_at > DATE_SUB(NOW(), INTERVAL ?i DAY)
             ORDER BY created_at DESC',
            $terminalStatuses,
            $daysBack,
        ));
    }

    /**
     * Filter null values to prevent PHP 8.1+ deprecation warnings.
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function filterNullValues(array $data): array
    {
        return array_filter($data, static fn ($v): bool => $v !== null);
    }
}
