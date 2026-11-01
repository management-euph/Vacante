<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Addons\NovotonHolidays\Exceptions\NovotonException;
use Tygh\Addons\NovotonHolidays\Helpers\BookingRoomAssembler;

/**
 * Constructs the Novoton reservation API request payload for a single room
 * group: hotel/package/dates, the holder name (first guest, else booking
 * holder), guests + rooms, the order number (suffixed per group), and the
 * single-room shortcut that pins room_id/board_id.
 *
 * One request books every room of its group, and Novoton takes several
 * rooms in one request only when they are for the same hotel, the same
 * package and the same stay (BookingRoomAssembler::groupRoomsByPackage):
 * a group whose rooms do not all share them is refused, nothing is sent.
 *
 * Extracted from BookingSubmissionService. Pure — depends only on the
 * passed group/guests/rooms/booking arrays — so it is directly unit-testable;
 * the service delegates to it inside its per-group loop.
 */
class ApiBookingRequestBuilder
{
    /**
     * @param array<string, mixed> $group
     * @param list<array<string, mixed>> $allGuests
     * @param list<array<string, mixed>> $apiRooms
     * @param array<string, mixed> $bookingData
     * @return array<string, mixed>
     * @throws NovotonException rooms of other hotels, packages or stays in one group
     */
    public function build(
        array $group,
        array $allGuests,
        array $apiRooms,
        array $bookingData,
        int $orderId,
        int $groupNum,
        int $totalGroups,
        string $orderComment = '',
    ): array {
        $groupRooms = is_array($group['rooms'] ?? null) ? $group['rooms'] : [];
        $stay = BookingRoomAssembler::stayOf($group, $bookingData);
        foreach ($groupRooms as $room) {
            // What a room does not state itself is its group's
            if (is_array($room) && BookingRoomAssembler::requestKey(BookingRoomAssembler::stayOf($room, $stay)) !== BookingRoomAssembler::requestKey($stay)) {
                throw new NovotonException('The rooms of one reservation must be for the same hotel, package and stay', [
                    'hotel_id' => $stay['hotel_id'],
                    'package_name' => $stay['package_name'],
                    'room' => PriceInfoFormatter::toInt($room['original_index'] ?? 0) + 1,
                ]);
            }
        }

        $suffix = $totalGroups > 1 ? "-G{$groupNum}" : '';

        $firstGuestName = (is_array($allGuests[0] ?? null) && !empty($allGuests[0]['name']))
            ? PriceInfoFormatter::toScalar($allGuests[0]['name'])
            : PriceInfoFormatter::toScalar($bookingData['holder_name'] ?? 'Guest');

        $apiData = [
            'hotel_id' => $stay['hotel_id'],
            'package_name' => $stay['package_name'],
            'check_in' => $stay['check_in'],
            'check_out' => $stay['check_out'],
            'holder' => $firstGuestName,
            'guests' => $allGuests,
            'rooms' => $apiRooms,
            'order_num' => $orderId . $suffix,
            'remark' => '',
            'comment' => $orderComment,
        ];

        // Single-room shortcut
        if (count($groupRooms) === 1) {
            $firstGroupRoom = is_array($groupRooms[0] ?? null) ? $groupRooms[0] : [];
            $apiData['room_id'] = PriceInfoFormatter::toScalar($firstGroupRoom['room_id'] ?? $bookingData['room_id'] ?? '');
            $apiData['board_id'] = PriceInfoFormatter::toScalar($firstGroupRoom['board_id'] ?? $bookingData['board_id'] ?? '');
        }

        return $apiData;
    }
}
