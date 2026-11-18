<?php

declare(strict_types=1);

/**
 * Novoton Holidays - Booking Query & Display Service
 *
 * Handles complex booking queries with joins, row mapping, and display
 * enrichment (room types, board names, guest formatting).
 *
 * Extracted from BookingRepository to support single-responsibility:
 * the repository handles CRUD persistence; this service handles
 * read-heavy display queries and formatting.
 *
 * @package NovotonHolidays
 * @since 3.5.0
 */

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Addons\NovotonHolidays\Helpers\ReservationNumber;
use Tygh\Addons\NovotonHolidays\Repository\BookingReportingRepositoryInterface;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\GuestDataNormalizer;
use Tygh\Addons\TravelCore\TravelConstants;
use Tygh\Addons\TravelCore\ValueObjects\RoomType;

class BookingQueryService implements BookingQueryServiceInterface
{
    private readonly BookingReportingRepositoryInterface $bookingReporting;
    private readonly GuestDataNormalizer $guestDataNormalizer;

    public function __construct(
        BookingReportingRepositoryInterface $bookingReporting,
        ?GuestDataNormalizer $guestDataNormalizer = null,
    ) {
        $this->bookingReporting = $bookingReporting;
        $this->guestDataNormalizer = $guestDataNormalizer ?? new GuestDataNormalizer();
    }

    /**
     * Get booking statistics
     * @return array{total: int, pending: int, confirmed: int, cancelled: int, with_orders: int, orphans: int}
     */
    #[\Override]
    public function getStats(): array
    {
        return [
            'total' => $this->bookingReporting->count(),
            'pending' => $this->bookingReporting->count(['status' => TravelConstants::STATUS_PENDING]),
            'confirmed' => $this->bookingReporting->count(['status' => TravelConstants::STATUS_CONFIRMED]),
            'cancelled' => $this->bookingReporting->count(['status' => TravelConstants::STATUS_CANCELLED]),
            'with_orders' => $this->bookingReporting->count(['has_order' => true]),
            'orphans' => $this->bookingReporting->count(['no_order' => true]),
        ];
    }

    /**
     * Get unified booking list - uses novoton_bookings as single source of truth
     * Joins with orders table for order status information
     *
     * @param array<string, mixed> $params Filter parameters
     * @return list<array<string, mixed>> Unified bookings list
     */
    #[\Override]
    public function getUnifiedBookings(array $params = []): array
    {
        $bookings_raw = $this->queryUnifiedBookings($params);

        $bookings = [];
        foreach ($bookings_raw as $nb) {
            $booking = $this->mapRawToUnified($nb);
            $this->enrichWithRoomDisplay($booking, $nb);
            $this->enrichWithGuestDisplay($booking, $nb);
            $bookings[] = $booking;
        }

        return $bookings;
    }

    /**
     * Execute the unified bookings query with filters.
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    private function queryUnifiedBookings(array $params): array
    {
        $conditions = [];

        if (empty($params['show_orphans'])) {
            $conditions[] = 'nb.order_id > 0';
        }
        if (!empty($params['order_id'])) {
            $conditions[] = db_quote('nb.order_id = ?i', $params['order_id']);
        }
        if (!empty($params['hotel_id'])) {
            $conditions[] = db_quote('nb.hotel_id = ?s', $params['hotel_id']);
        }
        if (!empty($params['novoton_status'])) {
            $conditions[] = db_quote('nb.novoton_status = ?s', $params['novoton_status']);
        }
        if (!empty($params['status'])) {
            $conditions[] = db_quote('nb.status = ?s', $params['status']);
        }
        if (!empty($params['check_in_from'])) {
            $conditions[] = db_quote('nb.check_in >= ?s', $params['check_in_from']);
        }
        if (!empty($params['check_in_to'])) {
            $conditions[] = db_quote('nb.check_in <= ?s', $params['check_in_to']);
        }

        $where_clause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

        return TypeCoerce::toRowList(db_get_array(
            "SELECT nb.*,
                    nh.hotel_name, nh.city AS hotel_city, nh.region AS hotel_region, nh.country AS hotel_country,
                    o.status AS order_status, o.timestamp AS order_timestamp,
                    o.firstname AS order_firstname, o.lastname AS order_lastname,
                    o.email AS order_email, o.phone AS order_phone
             FROM ?:novoton_bookings nb
             LEFT JOIN ?:novoton_hotels nh ON nb.hotel_id = nh.hotel_id
             LEFT JOIN ?:orders o ON nb.order_id = o.order_id
             {$where_clause}
             ORDER BY nb.order_id DESC, nb.booking_id DESC",
        ));
    }

    /**
     * Map a raw joined DB row to the unified booking structure.
     * @param array<string, mixed> $nb
     * @return array<string, mixed>
     */
    private function mapRawToUnified(array $nb): array
    {
        return [
            'booking_id' => $nb['booking_id'],
            'order_id' => $nb['order_id'],
            'product_id' => $nb['product_id'] ?? 0,
            'item_id' => $nb['item_id'] ?? '',
            'hotel_id' => $nb['hotel_id'],
            'hotel_name' => $nb['hotel_name'] ?? '',
            'city' => $nb['hotel_city'] ?? $nb['city'] ?? '',
            'hotel_city' => $nb['hotel_city'] ?? $nb['city'] ?? '',
            'region' => $nb['hotel_region'] ?? $nb['region'] ?? '',
            'hotel_region' => $nb['hotel_region'] ?? $nb['region'] ?? '',
            'country' => $nb['hotel_country'] ?? $nb['country'] ?? '',
            'package_id' => $nb['package_id'] ?? '',
            'package_name' => $nb['package_name'] ?? '',
            'room_id' => $nb['room_id'] ?? '',
            'room_type' => $nb['room_type'] ?? '',
            'board_id' => $nb['board_id'] ?? '',
            'board_name' => $nb['board_name'] ?? '',
            'check_in' => $nb['check_in'],
            'check_out' => $nb['check_out'],
            'nights' => $nb['nights'] ?? 0,
            'adults' => $nb['adults'] ?? 0,
            'children' => $nb['children'] ?? 0,
            'children_ages' => $nb['children_ages'] ?? '',
            'num_rooms' => $nb['num_rooms'] ?? 1,
            'room_number' => $nb['room_number'] ?? 1,
            'total_rooms' => $nb['total_rooms'] ?? 1,
            'rooms_data' => $nb['rooms_data'] ?? null,
            'guests_data' => $nb['guests_data'] ?? null,
            'base_price' => $nb['base_price'] ?? 0,
            'api_price' => $nb['api_price'] ?? $nb['base_price'] ?? 0,
            'total_price' => $nb['total_price'] ?? 0,
            'currency' => $nb['currency'] ?? 'EUR',
            'holder_name' => $nb['holder_name'] ?? '',
            'guest_name' => $nb['guest_name'] ?? $nb['holder_name'] ?? '',
            'guest_email' => $nb['order_email'] ?? $nb['guest_email'] ?? '',
            'guest_phone' => $nb['order_phone'] ?? $nb['guest_phone'] ?? '',
            'status' => $nb['status'] ?? TravelConstants::STATUS_PENDING,
            'novoton_status' => $nb['novoton_status'] ?? '',
            'novoton_invoice_id' => $nb['novoton_invoice_id'] ?? '',
            'novoton_confirm_id' => $nb['novoton_confirm_id'] ?? '',
            // Derived: no novoton_reservation_id column exists.
            'novoton_reservation_id' => ReservationNumber::of($nb),
            'api_request' => $nb['api_request'] ?? null,
            'api_response' => $nb['api_response'] ?? null,
            'alternatives_data' => $nb['alternatives_data'] ?? null,
            'order_status' => $nb['order_status'] ?? '',
            'created_at' => $nb['created_at'] ?? (!empty($nb['order_timestamp']) ? date('Y-m-d H:i:s', TypeCoerce::toInt($nb['order_timestamp'])) : ''),
            '_source' => ($nb['order_id'] > 0) ? 'novoton_bookings' : 'orphan',
        ];
    }

    /**
     * Add room_types_list and board_display from rooms_data JSON.
     * @param array<string, mixed> $booking
     * @param array<string, mixed> $nb
     */
    private function enrichWithRoomDisplay(array &$booking, array $nb): void
    {
        $rooms_data = null;
        if (!empty($nb['rooms_data'])) {
            $rooms_data = is_string($nb['rooms_data']) ? json_decode($nb['rooms_data'], true) : $nb['rooms_data'];
        }

        if (!empty($rooms_data) && is_array($rooms_data)) {
            $room_types = [];
            $board_names = [];
            foreach (TypeCoerce::toRowList($rooms_data) as $room) {
                $room_display = $room['room_type_display'] ?? $room['room_name'] ?? $room['room_id'] ?? 'Room';
                $room_display = str_replace(['%2b', '%2B'], '+', TypeCoerce::toString($room_display));
                $room_types[] = $room_display;
                if (!empty($room['board_name'])) {
                    $board_names[] = $room['board_name'];
                }
            }
            $booking['room_types_list'] = implode(', ', $room_types);
            $booking['board_display'] = !empty($board_names) ? $board_names[0] : $booking['board_name'];
        } else {
            $booking['room_types_list'] = $booking['room_type'] ?: RoomType::formatRoomLabel(TypeCoerce::toString($booking['room_id']));
            $booking['board_display'] = $booking['board_name'] ?: fn_novoton_holidays_format_board_name(TypeCoerce::toString($booking['board_id']));
        }
    }

    /**
     * Add guests_by_room from guests_data JSON.
     * @param array<string, mixed> $booking
     * @param array<string, mixed> $nb
     */
    private function enrichWithGuestDisplay(array &$booking, array $nb): void
    {
        if (empty($nb['guests_data'])) {
            return;
        }

        $guests_data = $this->guestDataNormalizer->normalize(TypeCoerce::toString($nb['guests_data']));
        if (empty($guests_data)) {
            return;
        }

        $by_room = [];
        foreach (TypeCoerce::toRowList($guests_data) as $guest) {
            $room_num = TypeCoerce::toInt($guest['room'] ?? 1);
            if (!isset($by_room[$room_num])) {
                $by_room[$room_num] = [];
            }
            $by_room[$room_num][] = $guest['name'] ?? 'Guest';
        }
        $booking['guests_by_room'] = $by_room;
    }
}
