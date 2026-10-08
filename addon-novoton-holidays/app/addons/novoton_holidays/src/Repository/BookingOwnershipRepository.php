<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Repository;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Repository\RowNarrowingTrait;
use Tygh\Addons\TravelCore\TravelConstants;

/**
 * Ownership-scoped read access to novoton_bookings.
 *
 * The security-sensitive booking lookups, lifted out of BookingRepository so
 * the ownership boundary is a small, auditable surface. Every query is scoped
 * to the requesting user_id and/or session_id; with no ownership context the
 * methods return nothing rather than leak another customer's bookings.
 *
 * user_id only counts when it is a real account (> 0) and session_id only when
 * it is non-empty: guest bookings are stored with user_id = 0, so matching on
 * an anonymous visitor's user_id would hand them every guest booking.
 */
class BookingOwnershipRepository implements BookingOwnershipRepositoryInterface
{
    use RowNarrowingTrait;

    /**
     * Find bookings by multiple product IDs (batch query for cart).
     *
     * @param list<int> $product_ids Product IDs
     * @param list<string> $statuses Optional status filter (default: pending + confirmed)
     * @return list<array<string, mixed>> Booking rows
     */
    public function findByProductIds(array $product_ids, array $statuses = [TravelConstants::STATUS_PENDING, TravelConstants::STATUS_CONFIRMED], string $session_id = '', int $user_id = 0): array
    {
        if (empty($product_ids)) {
            return [];
        }

        // Safety: if no ownership context is provided, return nothing rather than
        // leaking all users' bookings. Callers must provide session_id and/or user_id.
        if ($user_id <= 0 && empty($session_id)) {
            return [];
        }

        $select = 'SELECT booking_id, product_id, hotel_id, hotel_name, room_id, room_type,
                    board_id, check_in, check_out, nights, adults, children, children_ages,
                    num_rooms, rooms_data, total_price, currency, status, guests_data,
                    package_name, session_id, holder_name, guest_name
             FROM ?:novoton_bookings
             WHERE product_id IN (?n) AND status IN (?a)';

        // Scope to current user/session to prevent cross-user booking leakage
        if ($user_id > 0 && !empty($session_id)) {
            return self::asRowList(db_get_array(
                $select . ' AND (session_id = ?s OR user_id = ?i) ORDER BY booking_id DESC',
                $product_ids,
                $statuses,
                $session_id,
                $user_id,
            ));
        } elseif ($user_id > 0) {
            return self::asRowList(db_get_array(
                $select . ' AND user_id = ?i ORDER BY booking_id DESC',
                $product_ids,
                $statuses,
                $user_id,
            ));
        }

        return self::asRowList(db_get_array(
            $select . ' AND session_id = ?s ORDER BY booking_id DESC',
            $product_ids,
            $statuses,
            $session_id,
        ));
    }

    /**
     * Cart-stage booking owned by the caller — the guard behind the guest-facing
     * edit_booking / update_booking modes.
     *
     * Only a booking still in its pending state and not yet attached to an
     * order (order_id = 0) is returned: once an order exists the booking has
     * been (or is about to be) sent to Novoton, so its travellers must not be
     * rewritten through a stale cart link.
     *
     * @return array<string, mixed>|null
     */
    public function findByIdWithOwnership(int $booking_id, int $user_id, string $session_id): ?array
    {
        $scope = self::ownershipScope($user_id, $session_id);
        if ($booking_id <= 0 || $scope === null) {
            return null;
        }

        $row = self::asRow(db_get_row(
            'SELECT * FROM ?:novoton_bookings WHERE booking_id = ?i AND order_id = 0 AND status = ?s AND ' . $scope[0],
            $booking_id,
            TravelConstants::STATUS_PENDING,
            ...$scope[1],
        ));
        return $row === [] ? null : $row;
    }

    /**
     * Check that the caller owns a cart-stage booking (same rule as
     * findByIdWithOwnership); returns the booking_id or null.
     */
    public function checkOwnership(int $booking_id, int $user_id, string $session_id): ?int
    {
        $scope = self::ownershipScope($user_id, $session_id);
        if ($booking_id <= 0 || $scope === null) {
            return null;
        }

        $id = TypeCoerce::toInt(db_get_field(
            'SELECT booking_id FROM ?:novoton_bookings WHERE booking_id = ?i AND order_id = 0 AND status = ?s AND ' . $scope[0],
            $booking_id,
            TravelConstants::STATUS_PENDING,
            ...$scope[1],
        ));
        return $id > 0 ? $id : null;
    }

    /**
     * Ownership predicate for a single-booking lookup.
     *
     * Guest bookings are stored with user_id = 0 and an anonymous visitor's
     * user_id is 0 too, so `user_id = ?i` may only be used for a real account
     * (> 0); likewise `session_id = ?s` only for a non-empty session id.
     * Otherwise every anonymous visitor would own every guest booking, and
     * booking ids are sequential (audit C1).
     *
     * @return array{0: string, 1: list<int|string>}|null SQL fragment + params; null = no ownership context
     */
    private static function ownershipScope(int $user_id, string $session_id): ?array
    {
        $hasUser = $user_id > 0;
        $hasSession = trim($session_id) !== '';

        if ($hasUser && $hasSession) {
            return ['(user_id = ?i OR session_id = ?s)', [$user_id, $session_id]];
        }
        if ($hasUser) {
            return ['user_id = ?i', [$user_id]];
        }
        if ($hasSession) {
            return ['session_id = ?s', [$session_id]];
        }

        return null;
    }
}
