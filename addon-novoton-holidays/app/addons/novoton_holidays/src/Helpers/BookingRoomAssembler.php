<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Helpers;

use Tygh\Addons\NovotonHolidays\Services\PriceInfoFormatter;

/**
 * Assembles multi-room booking data into the per-package room groups and guest
 * lists the Novoton reservation API expects.
 *
 * Stateless transformation, extracted from BookingSubmissionService:
 *   - groupRoomsByPackage()      groups rooms sharing package + dates
 *   - buildGroupGuestsAndRooms() builds each group's guest list + API rooms and
 *                                reverses commission to the net API price
 *
 * Behaviour is preserved verbatim from BookingSubmissionService.
 */
class BookingRoomAssembler
{
    /**
     * Group rooms by (package_name + check_in + check_out).
     *
     * Rooms with identical grouping keys can be sent in a single API call.
     *
     * @param list<mixed> $roomsData Decoded rooms_data — elements may be non-arrays
     * @param array<string, mixed> $bookingData
     * @return array<string, array{package_name: string, check_in: string, check_out: string, rooms: list<array<int|string, mixed>>}>
     */
    public function groupRoomsByPackage(array $roomsData, array $bookingData): array
    {
        $groups = [];
        $defaultPackage = PriceInfoFormatter::toScalar($bookingData['package_name'] ?? '');
        $defaultCheckIn = PriceInfoFormatter::toScalar($bookingData['check_in'] ?? '');
        $defaultCheckOut = PriceInfoFormatter::toScalar($bookingData['check_out'] ?? '');

        foreach ($roomsData as $roomIdx => $room) {
            if (!is_array($room)) {
                continue;
            }
            $package = PriceInfoFormatter::toScalar($room['package_name'] ?? $defaultPackage);
            $checkIn = PriceInfoFormatter::toScalar($room['check_in'] ?? $defaultCheckIn);
            $checkOut = PriceInfoFormatter::toScalar($room['check_out'] ?? $defaultCheckOut);

            $groupKey = md5($package . '|' . $checkIn . '|' . $checkOut);

            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'package_name' => $package,
                    'check_in' => $checkIn,
                    'check_out' => $checkOut,
                    'rooms' => [],
                ];
            }

            $room['original_index'] = $roomIdx;
            $groups[$groupKey]['rooms'][] = $room;
        }

        return $groups;
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
