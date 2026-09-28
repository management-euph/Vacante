<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\Eurosite\Services\RoomOccupancy;

/**
 * Eurosite multi-room: each room is its own <Room> in the price request, the
 * offer answers a room per requested room (same order, one total price), and
 * the booking sends every room back with its own travellers.
 */
final class RoomOccupancyTest extends TestCase
{
    private const string TWO_ROOMS = '[{"adults":2,"children":0,"childrenAges":[]},{"adults":2,"children":2,"childrenAges":[8,3]}]';

    /** @return array<string, mixed> */
    private static function snapshot(): array
    {
        return [
            'adults' => 4,
            'children_ages' => [8, 3],
            'rooms_occupancy' => [
                ['adults' => 2, 'children_ages' => []],
                ['adults' => 2, 'children_ages' => [8, 3]],
            ],
            'rooms' => [
                ['code' => '40', 'gcode' => 'DB', 'name' => 'Double Room', 'quantity' => '1'],
                ['code' => '41', 'gcode' => 'DB', 'name' => 'Family Room', 'quantity' => '1'],
            ],
        ];
    }

    public function testEachRoomOfTheSearchIsItsOwnRoom(): void
    {
        $rooms = RoomOccupancy::fromRequest(self::TWO_ROOMS, 4, [8, 3]);

        self::assertSame([
            ['adults' => 2, 'children_ages' => []],
            ['adults' => 2, 'children_ages' => [8, 3]],
        ], $rooms);
        self::assertSame(['adults' => 4, 'children_ages' => [8, 3]], RoomOccupancy::totals($rooms));
        // The spec's own example request: <Room Code="DB" NoAdults="2"/> twice.
        self::assertSame([
            ['code' => 'DB', 'adults' => 2, 'children' => []],
            ['code' => 'DB', 'adults' => 2, 'children' => [8, 3]],
        ], RoomOccupancy::searchPayload($rooms));
    }

    public function testALinkWithoutRoomsDataIsOneRoomWithTheTotals(): void
    {
        self::assertSame([['adults' => 3, 'children_ages' => [5]]], RoomOccupancy::fromRequest('', 3, [5]));
        self::assertSame([['adults' => 1, 'children_ages' => []]], RoomOccupancy::fromRequest('not json', 0, []));
    }

    public function testRoomCodesFollowTheAdultsOfEachRoom(): void
    {
        self::assertSame('SB', RoomOccupancy::roomCode(1));
        self::assertSame('DB', RoomOccupancy::roomCode(2));
        self::assertSame('TR', RoomOccupancy::roomCode(3));
        self::assertSame('Q', RoomOccupancy::roomCode(4));
    }

    public function testFeesAndBookingSendEachRoomWithTheOffersRoomCode(): void
    {
        $pax = [
            1 => [['type' => 'adult', 'name' => 'POP / ANA', 'room' => 1]],
            2 => [['type' => 'child', 'name' => 'POP / DAN', 'room' => 2]],
        ];

        self::assertSame([
            ['code' => '40', 'adults' => 2, 'children' => [], 'pax' => $pax[1]],
            ['code' => '41', 'adults' => 2, 'children' => [8, 3], 'pax' => $pax[2]],
        ], RoomOccupancy::itemRooms(self::snapshot(), $pax));
    }

    public function testTheCartCardGetsEachRoomWithItsGuests(): void
    {
        self::assertSame([
            ['room_name' => 'Double Room', 'code' => '40', 'adults' => 2, 'children' => 0, 'childrenAges' => []],
            ['room_name' => 'Family Room', 'code' => '41', 'adults' => 2, 'children' => 2, 'childrenAges' => [8, 3]],
        ], RoomOccupancy::displayRooms(self::snapshot()));
    }

    public function testASnapshotFromBeforeMultiRoomIsOneRoom(): void
    {
        $old = ['adults' => 2, 'children_ages' => [4], 'rooms' => [['code' => '40', 'name' => 'Double Room']]];

        self::assertSame([['adults' => 2, 'children_ages' => [4]]], RoomOccupancy::fromSnapshot($old));
        self::assertSame([['code' => '40', 'adults' => 2, 'children' => [4]]], RoomOccupancy::itemRooms($old));
    }

    public function testTheBookingGroupsTravellersIntoTheirRooms(): void
    {
        $booking = ['rooms_data' => (string) json_encode(RoomOccupancy::displayRooms(self::snapshot()))];
        $pax = [
            ['type' => 'adult', 'name' => 'A', 'room' => 1],
            ['type' => 'adult', 'name' => 'B', 'room' => 1],
            ['type' => 'adult', 'name' => 'C', 'room' => 2],
            ['type' => 'child', 'name' => 'D', 'room' => 2],
        ];

        $rooms = RoomOccupancy::bookingRooms($booking, $pax);

        self::assertCount(2, $rooms);
        self::assertSame(['40', '41'], array_column($rooms, 'code'));
        self::assertSame(['A', 'B'], array_column($rooms[0]['pax'], 'name'));
        self::assertSame(['C', 'D'], array_column($rooms[1]['pax'], 'name'));
        self::assertSame([8, 3], $rooms[1]['children']);
    }

    public function testAnOlderSingleRoomBookingKeepsEveryTravellerInOneRoom(): void
    {
        $booking = [
            'rooms_data' => '[{"code":"40","name":"Double Room"}]',
            'adults' => 2,
            'children_ages' => '6',
        ];
        $pax = [['type' => 'adult', 'name' => 'A'], ['type' => 'child', 'name' => 'B']];

        self::assertSame([[
            'code' => '40',
            'adults' => 2,
            'children' => [6],
            'pax' => $pax,
        ]], RoomOccupancy::bookingRooms($booking, $pax));
    }

    /** Every step of the flow goes through RoomOccupancy — none assumes one room. */
    public function testEveryStepOfTheFlowUsesTheRooms(): void
    {
        $root = dirname(__DIR__, 3);
        $read = static fn (string $rel): string => (string) file_get_contents($root . '/' . $rel);

        $search = $read('controllers/frontend/eurosite_booking/search.php');
        self::assertStringContainsString("RoomOccupancy::fromRequest(RequestCoerce::string(\$_REQUEST, 'rooms_data')", $search);
        self::assertStringContainsString('$roomsPayload = RoomOccupancy::searchPayload($occupancy);', $search);
        self::assertStringContainsString("'rooms_occupancy' => \$occupancy,", $search);

        foreach (['controllers/frontend/eurosite_booking/booking_form.php', 'controllers/frontend/eurosite_booking/offer_terms.php'] as $file) {
            self::assertStringContainsString("'rooms'        => RoomOccupancy::itemRooms(\$snapshot),", $read($file), $file);
        }

        $cart = $read('controllers/frontend/eurosite_booking/add_to_cart.php');
        self::assertStringContainsString("'room'       => max(1, TypeCoerce::toInt(\$guest['room'] ?? 1)),", $cart);
        self::assertStringContainsString("'num_rooms'     => count(\$roomsData),", $cart);
        self::assertStringContainsString('$roomsMatch', $cart);

        self::assertStringContainsString(
            "'rooms' => RoomOccupancy::bookingRooms(\$booking, \$pax),",
            $read('src/Services/BookingSubmissionService.php'),
        );
    }
}
