<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Repository;

use Tygh\Addons\TravelCore\TravelConstants;

/**
 * Ownership-scoped read access to novoton_bookings.
 *
 * These are the security-sensitive booking lookups: every method scopes its
 * result to the requesting user_id and/or session_id, so a guest or a
 * logged-in customer can only ever reach their own bookings. Kept apart from
 * the general BookingRepository so the ownership boundary is a small,
 * auditable surface.
 */
interface BookingOwnershipRepositoryInterface
{
    /**
     * Find bookings by multiple product IDs (batch query for cart), scoped to
     * the owning user and/or session.
     *
     * @param list<int> $product_ids Product IDs
     * @param list<string> $statuses Optional status filter (default: pending + confirmed)
     * @return list<array<string, mixed>> Booking rows
     */
    public function findByProductIds(array $product_ids, array $statuses = [TravelConstants::STATUS_PENDING, TravelConstants::STATUS_CONFIRMED], string $session_id = '', int $user_id = 0): array;

    /**
     * Find a cart-stage booking (order_id = 0, status pending) by ID only when
     * it belongs to the given user or session. user_id counts only when > 0
     * and session_id only when non-empty: an anonymous visitor with no
     * session owns nothing (guest bookings are stored with user_id = 0).
     *
     * @return array<string, mixed>|null
     */
    public function findByIdWithOwnership(int $booking_id, int $user_id, string $session_id): ?array;

    /**
     * Same rule as findByIdWithOwnership; returns the booking_id when owned,
     * else null.
     */
    public function checkOwnership(int $booking_id, int $user_id, string $session_id): ?int;
}
