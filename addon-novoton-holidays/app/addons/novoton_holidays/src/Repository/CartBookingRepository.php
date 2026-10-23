<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Repository;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Repository\RowNarrowingTrait;
use Tygh\Addons\TravelCore\TravelConstants;

/**
 * The bookings a visitor's cart may still be charged for (audit H3).
 *
 * Stage — one of:
 *   - cart stage: pending and not attached to an order (order_id = 0);
 *   - this cart's own placed order: linked to one of the cart's
 *     processed/failed order ids (a NETOPIA payment failed or was abandoned
 *     and CS-Cart sent the customer back to checkout with the same cart —
 *     the reservation was submitted when that order was created), unless
 *     the submission failed or was cancelled (nothing live to pay for).
 *
 * Ownership — one of (the C1 rule: guest bookings are stored with
 * user_id = 0, so user_id counts only for a real account):
 *   - user_id = the account (> 0);
 *   - session_id = this session (non-empty);
 *   - the id is among the bookings this session's cart already verified
 *     (CartBookingGuard::SESSION_KEY). CS-Cart regenerates the session id
 *     at login, so a guest booking stops matching session_id() the moment
 *     its owner logs in at checkout; the verified list lives in the
 *     session data, which survives the regeneration.
 *
 * Price — a booking linked to one of the cart's orders also carries
 * line_total: the sum of total_price over the rows of that order, hotel and
 * dates. Submission splits a booking whose rooms use different packages
 * into one row per package group (BookingRecordBuilder: group 1 rewrites the
 * booking's own row, the others are new rows), so the booking's own row
 * holds group 1 only while the cart line charges every group.
 */
final class CartBookingRepository
{
    use RowNarrowingTrait;

    /** Submission outcomes with no live reservation behind them. */
    private const array DEAD_STATUSES = [TravelConstants::STATUS_FAILED, TravelConstants::STATUS_CANCELLED];

    /**
     * Returns booking_id => row (booking_id, product_id, total_price,
     * currency, order_id, status, hotel_id, check_in, check_out; line_total
     * when order_id > 0).
     *
     * @param list<int> $bookingIds the ids the cart lines claim
     * @param list<int> $ownOrderIds the cart's processed / failed order ids
     * @param list<int> $verifiedBookingIds ids this session's cart already verified
     * @return array<int, array<string, mixed>>
     */
    public function findCartStage(
        array $bookingIds,
        int $userId,
        string $sessionId,
        array $ownOrderIds = [],
        array $verifiedBookingIds = [],
    ): array {
        $bookingIds = self::positiveIds($bookingIds);
        $ownOrderIds = self::positiveIds($ownOrderIds);
        $verifiedBookingIds = self::positiveIds($verifiedBookingIds);

        $owners = [];
        $ownerParams = [];
        if ($userId > 0) {
            $owners[] = 'user_id = ?i';
            $ownerParams[] = $userId;
        }
        if (trim($sessionId) !== '') {
            $owners[] = 'session_id = ?s';
            $ownerParams[] = $sessionId;
        }
        if ($verifiedBookingIds !== []) {
            $owners[] = 'booking_id IN (?n)';
            $ownerParams[] = $verifiedBookingIds;
        }
        if ($bookingIds === [] || $owners === []) {
            return [];
        }

        $stage = '(order_id = 0 AND status = ?s)';
        $params = [$bookingIds, TravelConstants::STATUS_PENDING];
        if ($ownOrderIds !== []) {
            $stage = '(' . $stage . ' OR (order_id IN (?n) AND status NOT IN (?a)))';
            $params[] = $ownOrderIds;
            $params[] = self::DEAD_STATUSES;
        }

        $rows = db_get_array(
            'SELECT booking_id, product_id, total_price, currency, order_id, status, hotel_id, check_in, check_out FROM ?:novoton_bookings'
            . ' WHERE booking_id IN (?n) AND ' . $stage . ' AND (' . implode(' OR ', $owners) . ')',
            ...$params,
            ...$ownerParams,
        );

        $byId = [];
        foreach (self::asRowList($rows) as $row) {
            $id = TypeCoerce::toInt($row['booking_id'] ?? 0);
            if ($id > 0) {
                $byId[$id] = $row;
            }
        }

        return self::withLineTotals($byId);
    }

    /**
     * Each placed booking with the total of its order's live rows for the
     * same hotel and dates (its package groups). Two separate bookings of one
     * stay in one order share that total, so CartBookingGuard accepts a line
     * at either its row's own total or this one.
     *
     * @param array<int, array<string, mixed>> $byId
     * @return array<int, array<string, mixed>>
     */
    private static function withLineTotals(array $byId): array
    {
        $orderIds = [];
        foreach ($byId as $row) {
            $orderIds[] = TypeCoerce::toInt($row['order_id'] ?? 0);
        }
        $orderIds = self::positiveIds($orderIds);
        if ($orderIds === []) {
            return $byId;
        }

        $totals = [];
        $groups = db_get_array(
            'SELECT order_id, hotel_id, check_in, check_out, SUM(total_price) AS line_total FROM ?:novoton_bookings'
            . ' WHERE order_id IN (?n) AND status NOT IN (?a) GROUP BY order_id, hotel_id, check_in, check_out',
            $orderIds,
            self::DEAD_STATUSES,
        );
        foreach (self::asRowList($groups) as $group) {
            $totals[self::stayKey($group)] = TypeCoerce::toFloat($group['line_total'] ?? 0);
        }
        foreach ($byId as $id => $row) {
            if (TypeCoerce::toInt($row['order_id'] ?? 0) > 0) {
                $byId[$id]['line_total'] = $totals[self::stayKey($row)] ?? TypeCoerce::toFloat($row['total_price'] ?? 0);
            }
        }

        return $byId;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function stayKey(array $row): string
    {
        return implode('|', [
            TypeCoerce::toInt($row['order_id'] ?? 0),
            TypeCoerce::toString($row['hotel_id'] ?? ''),
            TypeCoerce::toString($row['check_in'] ?? ''),
            TypeCoerce::toString($row['check_out'] ?? ''),
        ]);
    }

    /**
     * @param list<int> $ids
     * @return list<int>
     */
    private static function positiveIds(array $ids): array
    {
        return array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
    }
}
