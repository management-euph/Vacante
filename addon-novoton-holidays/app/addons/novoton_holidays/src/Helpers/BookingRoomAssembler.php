<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Helpers;

use Tygh\Addons\NovotonHolidays\Services\PriceInfoFormatter;
use Tygh\Addons\NovotonHolidays\Services\RoomAvailability;
use Tygh\Addons\NovotonHolidays\Services\RoomOfferRows;

/**
 * Assembles multi-room booking data into the reservation requests and guest
 * lists the Novoton reservation API expects.
 *
 * Stateless transformation, extracted from BookingSubmissionService:
 *   - groupRoomsByPackage()      the rooms one hotel_res_RQ books: those of the
 *                                same hotel, package and stay
 *   - buildGroupGuestsAndRooms() builds each group's guest list + API rooms and
 *                                reverses commission to the net API price
 */
class BookingRoomAssembler
{
    /**
     * The reservation requests a booking's rooms go in: one per hotel,
     * package and stay.
     *
     * Novoton books several rooms in one hotel_res_RQ when they are for the
     * same hotel, the same package and the same stay, its documentation's
     * mandatory condition. Its example books two "DBL 2+1" rooms of SLAVEY
     * **** for 1–6 Jul 2026 in one request: the hotel, package and dates said
     * once, every guest listed once, one <hotel_acc> per room naming its
     * guests. So every room that shares the three goes in one request, in
     * booking order; a room of another package or stay starts a request of
     * its own (order number -G2, BookingSubmissionService).
     *
     * The package is compared as RoomOfferRows compares it: a "+BEACH" that a
     * link turned into " BEACH" is the same package, not a second
     * reservation. The request names it as Novoton spells it, URL-decoded,
     * in the spelling that kept its "+". A room without a package or dates
     * of its own takes the booking's.
     *
     * The rooms on request (RoomAvailability, read from the hotel's quota
     * when the booking went into the cart) go in a request of their own,
     * after those the hotel confirms at once: the split booking the owner
     * chose, so a room on request does not hold the others.
     *
     * @param list<mixed> $roomsData Decoded rooms_data — elements may be non-arrays
     * @param array<string, mixed> $bookingData
     * @return array<string, array{hotel_id: string, package_name: string, check_in: string, check_out: string, on_request: bool, rooms: list<array<int|string, mixed>>}>
     */
    public function groupRoomsByPackage(array $roomsData, array $bookingData): array
    {
        $groups = [];

        foreach ($roomsData as $roomIdx => $room) {
            if (!is_array($room)) {
                continue;
            }
            $stay = self::stayOf($room, $bookingData);
            $onRequest = RoomAvailability::onRequest($room);
            $groupKey = self::requestKey($stay) . ($onRequest ? '|on request' : '');

            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = $stay + ['on_request' => $onRequest, 'rooms' => []];
            } elseif (substr_count($stay['package_name'], '+') > substr_count($groups[$groupKey]['package_name'], '+')) {
                $groups[$groupKey]['package_name'] = $stay['package_name'];
            }

            $room['original_index'] = $roomIdx;
            $groups[$groupKey]['rooms'][] = $room;
        }

        // The rooms confirmed at once first: the booking's own record is theirs
        return array_filter($groups, static fn (array $g): bool => !$g['on_request'])
            + array_filter($groups, static fn (array $g): bool => $g['on_request']);
    }

    /**
     * A room's hotel, package and stay: its own, else the defaults' (the
     * booking's, or the group's the room is in).
     *
     * @param array<int|string, mixed> $room
     * @param array<string, mixed> $defaults hotel_id, package_name, check_in, check_out
     * @return array{hotel_id: string, package_name: string, check_in: string, check_out: string}
     */
    public static function stayOf(array $room, array $defaults): array
    {
        $own = static function (string $field) use ($room, $defaults): string {
            $value = trim(PriceInfoFormatter::toScalar($room[$field] ?? ''));

            return $value !== '' ? $value : trim(PriceInfoFormatter::toScalar($defaults[$field] ?? ''));
        };

        return [
            'hotel_id' => $own('hotel_id'),
            'package_name' => RoomOfferRows::packageName($own('package_name')),
            'check_in' => $own('check_in'),
            'check_out' => $own('check_out'),
        ];
    }

    /**
     * Rooms with the same key go in one hotel_res_RQ: the same hotel, the
     * same package (RoomOfferRows::packageKey) and the same stay.
     *
     * @param array{hotel_id: string, package_name: string, check_in: string, check_out: string} $stay
     */
    public static function requestKey(array $stay): string
    {
        return implode('|', [$stay['hotel_id'], RoomOfferRows::packageKey($stay['package_name']), $stay['check_in'], $stay['check_out']]);
    }

    /**
     * Build per-group guest list and API room payload.
     *
     * For each room in the group, extracts adult/child guest names from
     * guestsData (keyed "room{N}_adult_{I}" / "room{N}_child_{I}") and
     * calculates the API price (without commission).
     *
     * @param array<string, mixed> $group
     * @param array<int|string, mixed> $guestsData
     * @param array<string, mixed> $bookingData
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>, 2: float, 3: float} [allGuests[], apiRooms[], totalApiPrice, totalGroupPrice]
     */
    public function buildGroupGuestsAndRooms(
        array $group,
        array $guestsData,
        array $bookingData,
        float $commission,
    ): array {
        $allGuests = [];
        $apiRooms = [];
        $totalApiPrice = 0.0;
        $totalGroupPrice = 0.0;

        $groupRooms = is_array($group['rooms'] ?? null) ? $group['rooms'] : [];
        foreach ($groupRooms as $room) {
            if (!is_array($room)) {
                continue;
            }
            $roomIdx = PriceInfoFormatter::toInt($room['original_index'] ?? 0);
            $roomNum = $roomIdx + 1;
            $adultsCount = PriceInfoFormatter::toInt($room['adults'] ?? 2);
            $childrenCount = PriceInfoFormatter::toInt($room['children'] ?? 0);
            $childrenAges = is_array($room['childrenAges'] ?? null) ? $room['childrenAges'] : [];
            $roomGuests = [];

            // Adults
            for ($i = 1; $i <= $adultsCount; $i++) {
                $guestKey = "room{$roomNum}_adult_{$i}";
                $name = $this->extractGuestName($guestsData, $guestKey);

                if (empty($name)) {
                    $name = ($roomNum === 1 && $i === 1 && !empty($bookingData['holder_name']))
                        ? PriceInfoFormatter::toScalar($bookingData['holder_name'])
                        : "Adult {$i} Room {$roomNum}";
                }

                $guestEntry = is_array($guestsData[$guestKey] ?? null) ? $guestsData[$guestKey] : [];
                $guest = [
                    'name' => $name,
                    'birthday' => PriceInfoFormatter::toScalar($guestEntry['birthday'] ?? ''),
                    'age' => PriceInfoFormatter::toInt($guestEntry['age'] ?? 30),
                    'type' => 'adult',
                    'room' => $roomNum,
                ];
                $roomGuests[] = $guest;
                $allGuests[] = $guest;
            }

            // Children
            for ($i = 1; $i <= $childrenCount; $i++) {
                $guestKey = "room{$roomNum}_child_{$i}";
                $name = $this->extractGuestName($guestsData, $guestKey);

                if (empty($name)) {
                    $name = "Child {$i} Room {$roomNum}";
                }

                $childEntry = is_array($guestsData[$guestKey] ?? null) ? $guestsData[$guestKey] : [];
                $age = 0;
                if (isset($childEntry['age'])) {
                    $age = PriceInfoFormatter::toInt($childEntry['age']);
                } elseif (isset($childrenAges[$i - 1])) {
                    $age = PriceInfoFormatter::toInt($childrenAges[$i - 1]);
                }

                if ($age <= 0) {
                    fn_log_event('general', 'runtime', [
                        'message' => 'Novoton - Child age missing, cannot determine correct pricing',
                        'guest_key' => $guestKey,
                        'room_num' => $roomNum,
                    ]);
                }

                $guest = [
                    'name' => $name,
                    'birthday' => PriceInfoFormatter::toScalar($childEntry['birthday'] ?? ''),
                    'age' => $age,
                    'type' => 'child',
                    'room' => $roomNum,
                ];
                $roomGuests[] = $guest;
                $allGuests[] = $guest;
            }

            // Price: reverse commission to get API (net) price
            $roomPriceWithCommission = PriceInfoFormatter::toFloat($room['price'] ?? 0);
            $roomApiPrice = $roomPriceWithCommission / (1 + ($commission / 100));
            $totalApiPrice += $roomApiPrice;
            $totalGroupPrice += $roomPriceWithCommission;

            $apiRooms[] = [
                'room_num' => $roomNum,
                'room_id' => PriceInfoFormatter::toScalar($room['room_id'] ?? $bookingData['room_id'] ?? ''),
                'board_id' => PriceInfoFormatter::toScalar($room['board_id'] ?? $bookingData['board_id'] ?? ''),
                // The board's supplement ("BB" + "HB" is half board) comes with
                // whichever board the room takes
                'ext_board_id' => PriceInfoFormatter::toScalar(isset($room['board_id']) ? ($room['ext_board_id'] ?? '') : ($bookingData['ext_board_id'] ?? '')),
                'guests' => $roomGuests,
            ];
        }

        return [$allGuests, $apiRooms, $totalApiPrice, $totalGroupPrice];
    }

    /**
     * Extract a guest name from the guestsData array.
     *
     * Prefers api_name (First Last format) over display_name/name.
     *
     * @param array<int|string, mixed> $guestsData
     */
    private function extractGuestName(array $guestsData, string $guestKey): string
    {
        if (!isset($guestsData[$guestKey])) {
            return '';
        }

        $entry = $guestsData[$guestKey];
        if (!is_array($entry)) {
            return '';
        }

        if (!empty($entry['api_name'])) {
            return PriceInfoFormatter::toScalar($entry['api_name']);
        }
        if (!empty($entry['name'])) {
            return PriceInfoFormatter::toScalar($entry['name']);
        }

        return '';
    }
}
