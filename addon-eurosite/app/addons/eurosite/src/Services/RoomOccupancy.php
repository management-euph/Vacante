<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Services;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;

/**
 * The guests of each room of a Eurosite booking.
 *
 * getHotelPriceRequest takes one <Room> per room (its own NoAdults and child
 * ages) and every offer answers with one <Room> per requested room, in the
 * same order, under a single total price; AddBookingRequest takes the rooms
 * again with each room's travellers. Search, booking form, terms, cart and
 * booking submission all go through this class so they agree on the rooms.
 *
 * One occupancy is {adults: int, children_ages: list<int>}.
 */
final class RoomOccupancy
{
    /**
     * The rooms of a search request: the booking engine's rooms_data JSON
     * ([{adults, children, childrenAges}, …]), else ONE room holding the
     * totals (links and forms that predate multi-room).
     *
     * @param list<int> $childrenAges
     * @return list<array{adults: int, children_ages: list<int>}>
     */
    public static function fromRequest(string $roomsDataJson, int $adults, array $childrenAges): array
    {
        $decoded = json_decode($roomsDataJson, true);
        $rooms = [];
        foreach (TypeCoerce::toRowList(is_array($decoded) ? $decoded : null) as $room) {
            $ages = [];
            foreach ((array) ($room['childrenAges'] ?? $room['children_ages'] ?? []) as $age) {
                if (is_numeric($age)) {
                    $ages[] = (int) $age;
                }
            }
            $rooms[] = [
                'adults' => max(1, TypeCoerce::toInt($room['adults'] ?? 2)),
                'children_ages' => $ages,
            ];
        }

        return $rooms !== [] ? $rooms : [['adults' => max(1, $adults), 'children_ages' => $childrenAges]];
    }

    /**
     * The rooms of a stored offer (OfferContextStore snapshot). Snapshots
     * written before multi-room carry only the totals: one room.
     *
     * @param array<string, mixed> $snapshot
     * @return list<array{adults: int, children_ages: list<int>}>
     */
    public static function fromSnapshot(array $snapshot): array
    {
        $rooms = [];
        foreach (TypeCoerce::toRowList($snapshot['rooms_occupancy'] ?? null) as $room) {
            $rooms[] = [
                'adults' => max(1, TypeCoerce::toInt($room['adults'] ?? 2)),
                'children_ages' => TypeCoerce::toIntList($room['children_ages'] ?? []),
            ];
        }

        return $rooms !== [] ? $rooms : [[
            'adults' => max(1, TypeCoerce::toInt($snapshot['adults'] ?? 2)),
            'children_ages' => TypeCoerce::toIntList($snapshot['children_ages'] ?? []),
        ]];
    }

    /**
     * Totals across rooms, for the fields that stay booking-level.
     *
     * @param list<array{adults: int, children_ages: list<int>}> $rooms
     * @return array{adults: int, children_ages: list<int>}
     */
    public static function totals(array $rooms): array
    {
        $adults = 0;
        $ages = [];
        foreach ($rooms as $room) {
            $adults += $room['adults'];
            foreach ($room['children_ages'] as $age) {
                $ages[] = $age;
            }
        }

        return ['adults' => $adults, 'children_ages' => $ages];
    }

    /** Eurosite's generic room code for a number of adults (children ride as ages). */
    public static function roomCode(int $adults): string
    {
        return match (true) {
            $adults <= 1 => 'SB',
            $adults === 2 => 'DB',
            $adults === 3 => 'TR',
            default => 'Q',
        };
    }

    /**
     * getHotelPriceRequest <Rooms>: one generic room per occupancy.
     *
     * @param list<array{adults: int, children_ages: list<int>}> $rooms
     * @return list<array{code: string, adults: int, children: list<int>}>
     */
    public static function searchPayload(array $rooms): array
    {
        $payload = [];
        foreach ($rooms as $room) {
            $payload[] = [
                'code' => self::roomCode($room['adults']),
                'adults' => $room['adults'],
                'children' => $room['children_ages'],
            ];
        }

        return $payload;
    }

    /**
     * getItemFees / AddBookingRequest <Rooms>: each occupancy with the room
     * code the offer answered for it (same order as requested). $pax, when
     * given, is the travellers per room number (1-based).
     *
     * @param array<string, mixed> $snapshot
     * @param array<int, list<array<string, mixed>>> $pax
     * @return list<array<string, mixed>>
     */
    public static function itemRooms(array $snapshot, array $pax = []): array
    {
        $offerRooms = TypeCoerce::toRowList($snapshot['rooms'] ?? null);
        $items = [];
        foreach (self::fromSnapshot($snapshot) as $i => $room) {
            $offerRoom = $offerRooms[$i] ?? $offerRooms[0] ?? [];
            $item = [
                'code' => TypeCoerce::toString($offerRoom['code'] ?? '') ?: self::roomCode($room['adults']),
                'adults' => $room['adults'],
                'children' => $room['children_ages'],
            ];
            if (isset($pax[$i + 1])) {
                $item['pax'] = $pax[$i + 1];
            }
            $items[] = $item;
        }

        return $items;
    }

    /**
     * The rooms as the shared cart card and booking record read them
     * (rooms_data: room_name, adults, children, childrenAges) — plus the
     * offer's room code, which the booking submission sends back.
     *
     * @param array<string, mixed> $snapshot
     * @return list<array{room_name: string, code: string, adults: int, children: int, childrenAges: list<int>}>
     */
    public static function displayRooms(array $snapshot): array
    {
        $offerRooms = TypeCoerce::toRowList($snapshot['rooms'] ?? null);
        $rows = [];
        foreach (self::itemRooms($snapshot) as $i => $room) {
            $offerRoom = $offerRooms[$i] ?? $offerRooms[0] ?? [];
            /** @var list<int> $ages */
            $ages = $room['children'];
            $rows[] = [
                'room_name' => TypeCoerce::toString($offerRoom['name'] ?? ''),
                'code' => TypeCoerce::toString($room['code']),
                'adults' => TypeCoerce::toInt($room['adults']),
                'children' => count($ages),
                'childrenAges' => $ages,
            ];
        }

        return $rows;
    }

    /**
     * The rooms of a booking row for AddBookingRequest. Rows written before
     * multi-room hold the offer's rooms without guests in rooms_data: those
     * become one room with the booking's totals and every traveller.
     *
     * @param array<string, mixed> $booking
     * @param list<array<string, mixed>> $pax travellers, each with a 1-based 'room'
     * @return list<array<string, mixed>>
     */
    public static function bookingRooms(array $booking, array $pax): array
    {
        $decoded = json_decode(TypeCoerce::toString($booking['rooms_data'] ?? '[]'), true);
        $stored = TypeCoerce::toRowList(is_array($decoded) ? $decoded : null);

        $byRoom = [];
        foreach ($pax as $traveller) {
            $byRoom[max(1, TypeCoerce::toInt($traveller['room'] ?? 1))][] = $traveller;
        }

        $withGuests = $stored !== [] && array_key_exists('adults', $stored[0]);
        if (!$withGuests) {
            $ages = array_values(array_filter(array_map(
                'intval',
                explode(',', TypeCoerce::toString($booking['children_ages'] ?? '')),
            ), static fn (int $a): bool => $a >= 0 && TypeCoerce::toString($booking['children_ages'] ?? '') !== ''));

            return [[
                'code' => $stored !== [] ? TypeCoerce::toString($stored[0]['code'] ?? '') : '',
                'adults' => TypeCoerce::toInt($booking['adults'] ?? 2),
                'children' => $ages,
                'pax' => $pax,
            ]];
        }

        $rooms = [];
        foreach ($stored as $i => $room) {
            $rooms[] = [
                'code' => TypeCoerce::toString($room['code'] ?? ''),
                'adults' => max(1, TypeCoerce::toInt($room['adults'] ?? 2)),
                'children' => TypeCoerce::toIntList($room['childrenAges'] ?? []),
                'pax' => $byRoom[$i + 1] ?? [],
            ];
        }

        return $rooms;
    }
}
