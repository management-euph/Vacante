<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Addons\NovotonHolidays\Repository\BookingRepositoryInterface;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\TravelConstants;

/**
 * Assembles the `novoton_bookings` DB record for a single room group and upserts
 * it using the Single-Source-of-Truth dedup rules.
 *
 * Extracted from BookingSubmissionService (the buildBookingRecord / persist
 * seam): `build()` maps a resolved group + guests + pricing into the column
 * array (nights, room/board, occupancy sums, terms, order holder), and
 * `persist()` writes it — updating the cart's original booking for group 1,
 * else de-duplicating by (order + hotel + dates) before inserting. The service
 * delegates to it inside its per-group loop.
 */
class BookingRecordBuilder
{
    private readonly BookingRepositoryInterface $bookingRepo;

    public function __construct(BookingRepositoryInterface $bookingRepo)
    {
        $this->bookingRepo = $bookingRepo;
    }

    /**
     * Build the booking record array for DB persistence.
     *
     * @return array<string, mixed> Column => value map for novoton_bookings
     * @param array<string, mixed> $group
     * @param list<array<string, mixed>> $allGuests
     * @param array<string, mixed> $bookingData
     * @param array<string, mixed> $product
     * @param array<string, mixed> $apiData
     */
    public function build(
        array $group,
        array $allGuests,
        array $bookingData,
        array $product,
        int $orderId,
        int $groupNum,
        int $totalGroups,
        float $totalApiPrice,
        float $totalGroupPrice,
        array $apiData,
        bool $disableApi,
    ): array {
        $groupRooms = is_array($group['rooms'] ?? null) ? $group['rooms'] : [];

        // Calculate nights safely
        $groupCheckIn = PriceInfoFormatter::toScalar($group['check_in'] ?? '');
        $groupCheckOut = PriceInfoFormatter::toScalar($group['check_out'] ?? '');
        try {
            $checkInDate = new \DateTime($groupCheckIn);
            $checkOutDate = new \DateTime($groupCheckOut);
            $nights = $checkInDate->diff($checkOutDate)->days;
        } catch (\Exception $e) {
            fn_log_event('general', 'error', [
                'message' => 'Novoton - Invalid date in booking group',
                'check_in' => $group['check_in'] ?? '',
                'check_out' => $group['check_out'] ?? '',
                'error' => $e->getMessage(),
            ]);
            $nights = PriceInfoFormatter::toInt($bookingData['nights'] ?? 7);
        }

        $orderInfo = fn_get_order_info($orderId);
        /** @var array<string, mixed> $orderInfo */
        $orderInfo = is_array($orderInfo) ? $orderInfo : [];
        $orderUserId = PriceInfoFormatter::toInt($orderInfo['user_id'] ?? 0);
        $orderEmail = PriceInfoFormatter::toScalar($orderInfo['email'] ?? '');

        $firstGroupRoom = is_array($groupRooms[0] ?? null) ? $groupRooms[0] : [];
        $firstGuestName = (is_array($allGuests[0] ?? null) && !empty($allGuests[0]['name']))
            ? PriceInfoFormatter::toScalar($allGuests[0]['name'])
            : PriceInfoFormatter::toScalar($bookingData['holder_name'] ?? '');

        return [
            'order_id' => $orderId,
            'product_id' => PriceInfoFormatter::toScalar($product['product_id'] ?? ''),
            'item_id' => PriceInfoFormatter::toScalar($product['item_id'] ?? ''),
            'hotel_id' => PriceInfoFormatter::toScalar($bookingData['hotel_id'] ?? ''),
            'hotel_name' => PriceInfoFormatter::toScalar($bookingData['hotel_name'] ?? ''),
            'package_name' => PriceInfoFormatter::toScalar($group['package_name'] ?? ''),
            'room_id' => implode(', ', TypeCoerce::toStringList(array_column($groupRooms, 'room_id'))),
            'room_type' => PriceInfoFormatter::toScalar($firstGroupRoom['room_type_display'] ?? $firstGroupRoom['room_name'] ?? ''),
            'board_id' => PriceInfoFormatter::toScalar($firstGroupRoom['board_id'] ?? $bookingData['board_id'] ?? ''),
            'board_name' => PriceInfoFormatter::toScalar($firstGroupRoom['board_name'] ?? $bookingData['board_name'] ?? ''),
            'check_in' => $groupCheckIn,
            'check_out' => $groupCheckOut,
            'nights' => $nights,
            'adults' => array_sum(array_column($groupRooms, 'adults')),
            'children' => array_sum(array_column($groupRooms, 'children')),
            'children_ages' => PriceInfoFormatter::toScalar($bookingData['children_ages'] ?? ''),
            'num_rooms' => count($groupRooms),
            'room_number' => $groupNum,
            'total_rooms' => $totalGroups,
            'rooms_data' => json_encode($groupRooms),
            'guest_name' => implode(', ', TypeCoerce::toStringList(array_column($allGuests, 'name'))),
            'holder_name' => $firstGuestName,
            'guests_data' => json_encode($allGuests),
            'base_price' => $totalApiPrice,
            'total_price' => $totalGroupPrice,
            'currency' => ConfigProvider::getApiCurrency(),
            'status' => TravelConstants::STATUS_PENDING,
            'api_request' => json_encode($apiData),
            'notes' => $disableApi ? 'API submission disabled - test mode' : '',
            'user_id' => $orderUserId,
            'guest_email' => $orderEmail,
            'terms_of_payment_raw' => $bookingData['terms_of_payment_raw'] ?? null,
            'terms_of_cancellation_raw' => $bookingData['terms_of_cancellation_raw'] ?? null,
            'terms_of_payment_formatted' => $bookingData['terms_of_payment'] ?? $bookingData['terms_of_payment_formatted'] ?? null,
            'terms_of_cancellation_formatted' => $bookingData['terms_of_cancellation'] ?? $bookingData['terms_of_cancellation_formatted'] ?? null,
        ];
    }

    /**
     * Upsert a booking record using the Single Source of Truth pattern.
     *
     * Priority:
     *   1. Update by originalBookingId (from cart) for group 1
     *   2. Update existing row matching (order + hotel + dates + group) to prevent duplicates
     *   3. Insert new row
     * @param array<string, mixed> $record
     */
    public function persist(
        array $record,
        int $originalBookingId,
        int $groupNum,
        int $orderId,
    ): int {
        // Group 1: update the original booking from cart
        if ($groupNum === 1 && $originalBookingId > 0) {
            $this->bookingRepo->update($originalBookingId, $record);
            return $originalBookingId;
        }

        // Dedup: find this group's existing row for this order + hotel +
        // dates (all groups share those: without the group number, group 2
        // overwrote group 1's row and the booking lost a group's record)
        $existingId = $this->bookingRepo->findIdByOrderAndHotelDates(
            $orderId,
            PriceInfoFormatter::toScalar($record['hotel_id'] ?? ''),
            PriceInfoFormatter::toScalar($record['check_in'] ?? ''),
            PriceInfoFormatter::toScalar($record['check_out'] ?? ''),
            $groupNum,
        );

        if ($existingId !== null && $existingId !== 0) {
            $this->bookingRepo->update($existingId, $record);
            return $existingId;
        }

        // New booking
        $record['session_id'] = session_id();
        return $this->bookingRepo->create($record);
    }
}
