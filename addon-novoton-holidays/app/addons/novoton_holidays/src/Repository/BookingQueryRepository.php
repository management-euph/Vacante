<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Repository;

use Tygh\Addons\NovotonHolidays\Constants;
use Tygh\Addons\TravelCore\Repository\RowNarrowingTrait;
use Tygh\Addons\TravelCore\TravelConstants;

/**
 * Read-model queries over the `novoton_bookings` table — the list/collection
 * reads that serve admin listings, account history, batch/cron processing and
 * reporting.
 *
 * Extracted from BookingRepository to separate the (stateless, side-effect-free)
 * query surface from the transactional CRUD + travel_bookings sync. These
 * methods never write and never touch the sync mirror, so they are directly
 * unit-testable; BookingRepository keeps thin delegators for its interface.
 */
class BookingQueryRepository
{
    use RowNarrowingTrait;

    /**
     * Columns selected for listing queries (excludes large JSON/text fields:
     * rooms_data, guests_data, api_request, api_response, alternatives_data,
     * notes, terms_of_payment_raw/formatted, terms_of_cancellation_raw/formatted).
     */
    private const string LIST_COLUMNS = 'booking_id, order_id, product_id, user_id,
        session_id, novoton_confirm_id, novoton_invoice_id, novoton_res_num,
        novoton_status, hotel_id, hotel_name, package_id, package_name,
        room_id, room_type, board_id, board_name, item_id,
        check_in, check_out, nights, adults, children, children_ages,
        num_rooms, room_number, total_rooms, guest_name, guest_email, guest_phone,
        holder_name, base_price, extras_price, total_price, api_price,
        currency, status, alternatives_requested, last_status_check,
        created_at, updated_at';

    /**
     * Find bookings by order ID
     * @return list<array<string, mixed>>
     */
    public function findByOrderId(int $order_id): array
    {
        return self::asRowList(db_get_array('SELECT * FROM ?:novoton_bookings WHERE order_id = ?i ORDER BY booking_id', $order_id));
    }

    /**
     * Find bookings by user ID
     * @return list<array<string, mixed>>
     */
    public function findByUserId(int $user_id, int $limit = 100): array
    {
        return self::asRowList(db_get_array(
            'SELECT ' . self::LIST_COLUMNS . ' FROM ?:novoton_bookings WHERE user_id = ?i ORDER BY created_at DESC LIMIT ?i',
            $user_id,
            $limit,
        ));
    }

    /**
     * Find bookings by session ID
     * @return list<array<string, mixed>>
     */
    public function findBySessionId(string $session_id): array
    {
        return self::asRowList(db_get_array(
            'SELECT ' . self::LIST_COLUMNS . ' FROM ?:novoton_bookings WHERE session_id = ?s AND order_id = 0 ORDER BY created_at DESC LIMIT 50',
            $session_id,
        ));
    }

    /**
     * Find bookings by hotel ID
     * @return list<array<string, mixed>>
     */
    public function findByHotelId(string $hotel_id, int $limit = 100): array
    {
        return self::asRowList(db_get_array(
            'SELECT ' . self::LIST_COLUMNS . ' FROM ?:novoton_bookings WHERE hotel_id = ?s ORDER BY check_in DESC LIMIT ?i',
            $hotel_id,
            $limit,
        ));
    }

    /**
     * Find pending bookings
     * @return list<array<string, mixed>>
     */
    public function findPending(int $limit = 500): array
    {
        return self::asRowList(db_get_array(
            'SELECT ' . self::LIST_COLUMNS . ' FROM ?:novoton_bookings WHERE status = ?s ORDER BY created_at DESC LIMIT ?i',
            TravelConstants::STATUS_PENDING,
            $limit,
        ));
    }

    /**
     * Find bookings that carry a Novoton reservation number in any of
     * ReservationNumber::COLUMNS (submission writes novoton_invoice_id).
     *
     * @return list<array<string, mixed>>
     */
    public function findWithReservationId(int $limit = 1000): array
    {
        return self::asRowList(db_get_array(
            'SELECT ' . self::LIST_COLUMNS . " FROM ?:novoton_bookings
             WHERE (novoton_invoice_id IS NOT NULL AND novoton_invoice_id != '')
                OR (novoton_confirm_id IS NOT NULL AND novoton_confirm_id != '')
                OR (novoton_res_num IS NOT NULL AND novoton_res_num != '')
             ORDER BY created_at DESC LIMIT ?i",
            $limit,
        ));
    }

    /**
     * Find bookings for multiple order IDs in a single batch query.
     *
     * @param list<int> $order_ids
     * @return list<array<string, mixed>> Booking summary rows
     */
    public function findByOrderIds(array $order_ids): array
    {
        if (empty($order_ids)) {
            return [];
        }
        return self::asRowList(db_get_array(
            'SELECT booking_id, order_id, hotel_id, hotel_name, room_type, board_id,
                    check_in, check_out, nights, adults, children, total_price,
                    currency, status, novoton_status, novoton_confirm_id
             FROM ?:novoton_bookings
             WHERE order_id IN (?n)',
            $order_ids,
        ));
    }

    /**
     * Find bookings by Novoton API status (e.g. ASK, RQ).
     *
     * @param string $novoton_status API-level status (e.g. 'ASK')
     * @param list<string> $statuses Internal statuses to match
     * @param int $limit Max rows
     * @return list<array<string, mixed>>
     */
    public function findByNovotonStatus(string $novoton_status, array $statuses, int $limit = 50): array
    {
        return self::asRowList(db_get_array(
            'SELECT * FROM ?:novoton_bookings
             WHERE novoton_status = ?s AND status IN (?a)
             ORDER BY created_at DESC LIMIT ?i',
            $novoton_status,
            $statuses,
            $limit,
        ));
    }

    /**
     * Find RQ bookings that haven't had alternatives requested yet.
     * @return list<array<string, mixed>>
     */
    public function findRqWithoutAlternatives(int $limit = 50): array
    {
        return self::asRowList(db_get_array(
            'SELECT * FROM ?:novoton_bookings
             WHERE novoton_status = ?s AND alternatives_requested = 0
             ORDER BY created_at ASC LIMIT ?i',
            Constants::NOVOTON_STATUS_ALTERNATIVES_PENDING,
            $limit,
        ));
    }
}
