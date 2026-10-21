<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\TravelConstants;
use Tygh\Addons\TravelCore\ValueObjects\RoomType;

/**
 * The rooms an add_to_cart books, read from the posted form and checked
 * against the posted guests (audit C2/H4).
 *
 * The reservation sent to Novoton is built from rooms_data (room, board,
 * package, adults and each room's children), so rooms_data is what has to
 * be priced. add_to_cart used to price one tuple (the request's room, board
 * and adults plus every child of every room) and store the hidden JSON as
 * posted: two all-inclusive suites could be booked at the price of one
 * double room, and a two-room form (which posts no adults or room id) was
 * charged one room's price.
 *
 * Each room keeps exactly the values the reservation will carry:
 *   - room_id, board_id, package_name: the room's own, else the request's
 *     (single room only for the room id, as the one-room search card posts
 *     rooms_data with occupancy only);
 *   - adults, children: the room's own (the request's for a single room
 *     without them);
 *   - childrenAges: for child i of room N the age of guest room{N}_child_{i}
 *     when that guest was posted (BookingRoomAssembler sends that age), else
 *     the room's own childrenAges[i - 1].
 *
 * Every posted guest must sit in a room that exists and within its
 * occupancy (key room{N}_{adult|child}_{i}, the same N in its [room] field,
 * the same type). Anything else is null: nothing is priced, nothing booked.
 *
 * Pure: no API, no CS-Cart.
 *
 * @phpstan-type BookedRoom array{room_id: string, board_id: string, package_name: string, adults: int, children: int, childrenAges: list<int>}
 */
final class BookedRooms
{
    /**
     * Guest field names of the shared guest cards (booking_guest_room_body.tpl).
     * Only the canonical spelling: the reservation (BookingRoomAssembler) and
     * the checkout check (PreOrderPriceVerifier::roomLines) read exactly
     * "room{N}_child_{i}", so an alias ("room01_child_1", "room1_child_01",
     * a trailing newline) priced here would not be the guest they send.
     */
    private const string GUEST_KEY = '/^room([1-9]\d*)_(adult|child)_([1-9]\d*)\z/';

    /**
     * @param array<string, mixed> $request the posted booking form (room_id already normalised)
     * @param array<string, mixed> $guestsData GuestDataService::parseAndValidateGuests()['guests_data']
     * @return list<BookedRoom>|null null: rooms_data or the guests are inconsistent
     */
    public static function fromRequest(array $request, array $guestsData, int $maxRooms = TravelConstants::MAX_ROOMS): ?array
    {
        $posted = self::postedRooms($request['rooms_data'] ?? null);
        if ($posted === null || count($posted) > $maxRooms) {
            return null;
        }
        if ($posted === []) {
            $ages = self::agesFromCsv($request['children_ages'] ?? null);
            $posted = [[
                'adults' => $request['adults'] ?? 2,
                'children' => $request['children'] ?? count($ages),
                'childrenAges' => $ages,
            ]];
        }

        $single = count($posted) === 1;
        $rooms = [];
        foreach ($posted as $posting) {
            $room = self::room($posting, $request, $single);
            if ($room === null) {
                return null;
            }
            $rooms[] = $room;
        }

        $guestChildAges = self::guestChildAges($guestsData, $rooms);
        if ($guestChildAges === null) {
            return null;
        }

        foreach ($rooms as $index => $room) {
            $ages = [];
            for ($i = 1; $i <= $room['children']; $i++) {
                $age = $guestChildAges[$index][$i] ?? $room['childrenAges'][$i - 1] ?? null;
                if ($age === null || $age < TravelConstants::MIN_CHILD_AGE || $age > TravelConstants::MAX_CHILD_AGE) {
                    return null;
                }
                $ages[] = $age;
            }
            $rooms[$index]['childrenAges'] = $ages;
        }

        return $rooms;
    }

    /**
     * Whether guests edited on a booked stay (update_booking) keep every
     * child at the age the stay was priced for (audit C2/H4).
     *
     * The rooms are rebuilt from the novoton_bookings row twice: from its
     * stored rooms alone (the priced ages) and with the edited guests (the
     * ages the reservation will send: BookingRoomAssembler reads the guest's
     * age first). They must agree room by room. false as well when either
     * cannot be rebuilt: guests that do not fit the booked rooms, or a row
     * whose rooms cannot be read.
     *
     * @param array<string, mixed> $row the booking row as add_to_cart stored it
     * @param array<string, mixed> $guestsData GuestDataService::parseAndValidateGuests()['guests_data']
     */
    public static function keepsPricedChildAges(array $row, array $guestsData): bool
    {
        $rooms = $row['rooms_data'] ?? null;
        if (is_string($rooms) && json_decode($rooms, true) === []) {
            $rooms = null; // an empty list: the row's own tuple is the room
        }
        $request = [
            'rooms_data' => $rooms,
            'room_id' => $row['room_id'] ?? null,
            'board_id' => $row['board_id'] ?? null,
            'package_name' => $row['package_name'] ?? null,
            'adults' => $row['adults'] ?? null,
            'children' => $row['children'] ?? null,
            'children_ages' => $row['children_ages'] ?? null,
        ];

        $priced = self::fromRequest($request, []);
        $edited = self::fromRequest($request, $guestsData);
        if ($priced === null || $edited === null || count($priced) !== count($edited)) {
            return false;
        }
        foreach ($edited as $index => $room) {
            if ($room['childrenAges'] !== $priced[$index]['childrenAges']) {
                return false;
            }
        }

        return true;
    }

    /**
     * The posted rooms_data as a list of rooms: [] when none was posted,
     * null when something was posted that is not a list of rooms.
     *
     * @return list<array<string, mixed>>|null
     */
    private static function postedRooms(mixed $raw): ?array
    {
        if ($raw === null || $raw === '' || $raw === []) {
            return [];
        }
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw) || $raw === [] || !array_is_list($raw)) {
            return null;
        }

        $rooms = [];
        foreach ($raw as $room) {
            if (!is_array($room)) {
                return null;
            }
            $rooms[] = TypeCoerce::toStringMap($room);
        }

        return $rooms;
    }

    /**
     * One posted room with its fallbacks applied; null when a value is
     * missing or out of range.
     *
     * @param array<string, mixed> $posting
     * @param array<string, mixed> $request
     * @return BookedRoom|null
     */
    private static function room(array $posting, array $request, bool $single): ?array
    {
        $roomId = self::text($posting['room_id'] ?? null);
        if ($roomId === '' && $single) {
            $roomId = self::text($request['room_id'] ?? null);
        }
        $roomId = RoomType::normalizeRoomCode($roomId);
        if ($roomId === '') {
            return null;
        }

        $board = self::text($posting['board_id'] ?? null);
        if ($board === '') {
            $board = self::text($request['board_id'] ?? null);
        }
        $package = self::text($posting['package_name'] ?? null);
        if ($package === '') {
            $package = self::text($request['package_name'] ?? null);
        }

        $adults = self::count($posting['adults'] ?? ($single ? ($request['adults'] ?? 2) : null));
        if ($adults === null || $adults < 1 || $adults > TravelConstants::MAX_ADULTS) {
            return null;
        }

        $rawAges = $posting['childrenAges'] ?? [];
        if (!is_array($rawAges) || !array_is_list($rawAges)) {
            return null;
        }
        $ages = [];
        foreach ($rawAges as $rawAge) {
            $age = self::count($rawAge);
            if ($age === null) {
                return null;
            }
            $ages[] = $age;
        }

        $children = self::count($posting['children'] ?? count($ages));
        if ($children === null || $children > TravelConstants::MAX_CHILDREN
            || ($ages !== [] && count($ages) !== $children)) {
            return null;
        }

        return [
            'room_id' => $roomId,
            'board_id' => $board,
            'package_name' => $package,
            'adults' => $adults,
            'children' => $children,
            'childrenAges' => $ages,
        ];
    }

    /**
     * The posted child guests' ages by room index and child number; null
     * when a guest does not fit the rooms.
     *
     * @param array<array-key, mixed> $guestsData (a crafted post can give an integer key)
     * @param list<BookedRoom> $rooms
     * @return array<int, array<int, int>>|null
     */
    private static function guestChildAges(array $guestsData, array $rooms): ?array
    {
        $ages = [];
        $seen = [];
        foreach ($guestsData as $key => $guest) {
            if (!is_array($guest) || !is_string($key) || preg_match(self::GUEST_KEY, $key, $m) !== 1) {
                return null;
            }
            $roomNum = (int) $m[1];
            $type = $m[2];
            $number = (int) $m[3];
            $slot = "room{$roomNum}_{$type}_{$number}";
            if ($key !== $slot || isset($seen[$slot])) {
                return null; // not the canonical key, or a second guest in one slot
            }
            $seen[$slot] = true;
            $room = $rooms[$roomNum - 1] ?? null;
            if ($room === null
                || TypeCoerce::toInt($guest['room'] ?? 0) !== $roomNum
                || strtolower(TypeCoerce::toString($guest['type'] ?? '')) !== $type
                || $number < 1
                || $number > ($type === 'adult' ? $room['adults'] : $room['children'])) {
                return null;
            }
            if ($type === 'child') {
                $age = self::count($guest['age'] ?? null);
                if ($age === null) {
                    return null;
                }
                $ages[$roomNum - 1][$number] = $age;
            }
        }

        return $ages;
    }

    /**
     * @return list<int>
     */
    private static function agesFromCsv(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = array_filter(explode(',', $raw), static fn (string $v): bool => trim($v) !== '');
        }

        return is_array($raw) ? TypeCoerce::toIntList(array_values($raw)) : [];
    }

    /** A whole, non-negative number (int or digit string); null otherwise. */
    private static function count(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if (is_string($value) && preg_match('/^\s*\d{1,3}\s*$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    private static function text(mixed $value): string
    {
        return is_string($value) || is_int($value) ? trim((string) $value) : '';
    }
}
