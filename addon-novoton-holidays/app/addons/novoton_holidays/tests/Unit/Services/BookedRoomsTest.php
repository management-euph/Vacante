<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Services\BookedRooms;

/**
 * The rooms add_to_cart prices are the rooms the reservation books
 * (audit C2/H4): rooms_data, with each room's children taken from that
 * room's own child guests, and nothing that does not fit.
 */
#[CoversClass(BookedRooms::class)]
final class BookedRoomsTest extends TestCase
{
    /** The one-room search card posts rooms_data with occupancy only. */
    public function testASingleRoomTakesTheRequestRoomBoardAndPackage(): void
    {
        $rooms = BookedRooms::fromRequest(
            self::request(['rooms_data' => json_encode([['adults' => 2, 'children' => 1, 'childrenAges' => [5]]])]),
            ['room1_adult_1' => self::guest('adult', 1), 'room1_child_1' => self::guest('child', 1, 6)],
        );

        self::assertSame([[
            'room_id' => 'DBL 2+1',
            'board_id' => 'BB',
            'package_name' => 'ADMIRAL +BEACH',
            'adults' => 2,
            'children' => 1,
            // The child guest's age, as the reservation sends it
            'childrenAges' => [6],
        ]], $rooms);
    }

    public function testNoRoomsDataIsOneRoomFromTheRequest(): void
    {
        $rooms = BookedRooms::fromRequest(self::request(['adults' => '3', 'children' => '2', 'children_ages' => '4,9']), []);

        self::assertSame([[
            'room_id' => 'DBL 2+1',
            'board_id' => 'BB',
            'package_name' => 'ADMIRAL +BEACH',
            'adults' => 3,
            'children' => 2,
            'childrenAges' => [4, 9],
        ]], $rooms);
    }

    /**
     * H4: the multi-room form posts no adults or room id; each room is its
     * own room, board, package and occupancy, with its own children.
     */
    public function testEveryRoomKeepsItsOwnTupleAndItsOwnChildren(): void
    {
        $rooms = BookedRooms::fromRequest(
            self::request([
                'room_id' => 'DBL',
                'adults' => '2',
                'rooms_data' => json_encode([
                    ['room_id' => 'DBL', 'board_id' => 'BB', 'package_name' => 'P1', 'adults' => 2, 'children' => 0, 'childrenAges' => [], 'price' => 1],
                    ['room_id' => 'FAM 2%2B2', 'board_id' => 'AI', 'package_name' => 'P2', 'adults' => 2, 'children' => 2, 'childrenAges' => [3, 8], 'price' => 1],
                ]),
            ]),
            [
                'room1_adult_1' => self::guest('adult', 1),
                'room2_adult_1' => self::guest('adult', 2),
                'room2_child_1' => self::guest('child', 2, 4),
                'room2_child_2' => self::guest('child', 2, 8),
            ],
        );

        self::assertNotNull($rooms);
        self::assertCount(2, $rooms);
        self::assertSame(['DBL', 'BB', 'P1', 2, 0, []], array_values($rooms[0]));
        self::assertSame(['FAM 2+2', 'AI', 'P2', 2, 2, [4, 8]], array_values($rooms[1]));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array<string, mixed>}>
     */
    public static function inconsistentPostings(): iterable
    {
        $two = [
            ['room_id' => 'DBL', 'board_id' => 'BB', 'adults' => 2, 'children' => 0],
            ['room_id' => 'FAM', 'board_id' => 'BB', 'adults' => 2, 'children' => 1, 'childrenAges' => [7]],
        ];
        $guests = ['room2_child_1' => self::guest('child', 2, 7)];

        yield 'not JSON' => [['rooms_data' => '{oops'], []];
        yield 'not a list' => [['rooms_data' => json_encode(['a' => ['room_id' => 'DBL', 'adults' => 2]])], []];
        yield 'a room that is not an object' => [['rooms_data' => json_encode([1, 2])], []];
        yield 'more rooms than allowed' => [['rooms_data' => json_encode(array_fill(0, 6, $two[0]))], []];
        yield 'a second room without a room id' => [['rooms_data' => json_encode([$two[0], ['adults' => 2]])], []];
        yield 'a second room without adults' => [['rooms_data' => json_encode([$two[0], ['room_id' => 'DBL']])], []];
        yield 'no adults' => [['rooms_data' => json_encode([['room_id' => 'DBL', 'adults' => 0]])], []];
        yield 'adults not a number' => [['rooms_data' => json_encode([['room_id' => 'DBL', 'adults' => '2; DROP']])], []];
        yield 'ages do not match the children' => [['rooms_data' => json_encode([['room_id' => 'DBL', 'adults' => 2, 'children' => 2, 'childrenAges' => [5]]])], []];
        yield 'a child without an age' => [['rooms_data' => json_encode([['room_id' => 'DBL', 'adults' => 2, 'children' => 1]])], []];
        yield 'a child of 18' => [['rooms_data' => json_encode([['room_id' => 'DBL', 'adults' => 2, 'children' => 1, 'childrenAges' => [18]]])], []];
        yield 'a child guest in a room that does not exist' => [['rooms_data' => json_encode($two)], $guests + ['room3_child_1' => self::guest('child', 3, 5)]];
        yield 'a child guest beyond its room' => [['rooms_data' => json_encode($two)], $guests + ['room1_child_1' => self::guest('child', 1, 5)]];
        yield 'an adult guest beyond its room' => [['rooms_data' => json_encode($two)], $guests + ['room1_adult_3' => self::guest('adult', 1)]];
        yield 'a guest whose room field disagrees' => [['rooms_data' => json_encode($two)], ['room2_child_1' => self::guest('child', 1, 7)]];
        yield 'a child posted as an adult' => [['rooms_data' => json_encode($two)], ['room2_child_1' => self::guest('adult', 2, 7)]];
        yield 'a guest without a room key' => [['rooms_data' => json_encode($two)], $guests + ['child_1' => self::guest('child', 1, 5)]];

        // Aliases of room1_child_1: the reservation and the checkout check
        // read only the canonical key, so an alias would price one age and
        // book another (a 14-year-old at the infant rate).
        $teen = ['rooms_data' => json_encode([['room_id' => 'DBL', 'adults' => 2, 'children' => 1, 'childrenAges' => [14]]])];
        $canonical = ['room1_child_1' => self::guest('child', 1, 14)];
        yield 'a zero-padded room number' => [$teen, ['room01_child_1' => self::guest('child', 1, 1)]];
        yield 'a zero-padded guest number' => [$teen, ['room1_child_01' => self::guest('child', 1, 1)]];
        yield 'a key with a trailing newline' => [$teen, ["room1_child_1\n" => self::guest('child', 1, 1)]];
        yield 'room zero' => [$teen, ['room0_child_1' => self::guest('child', 1, 1)]];
        yield 'an alias posted after the canonical key' => [$teen, $canonical + ['room01_child_1' => self::guest('child', 1, 1)]];
        // guests[0][...] arrives with an integer key: refused, not a TypeError.
        yield 'an integer guest key' => [$teen, [0 => self::guest('child', 1, 1)]];
    }

    /**
     * @param array<string, mixed> $override
     * @param array<string, mixed> $guests
     */
    #[DataProvider('inconsistentPostings')]
    public function testInconsistentRoomsOrGuestsAreRejected(array $override, array $guests): void
    {
        self::assertNull(BookedRooms::fromRequest(self::request($override), $guests));
    }

    /**
     * update_booking (audit C2/H4): edited guests may change names and dates
     * of birth, but not the age a child was priced for — the reservation
     * sends the guest's age.
     */
    public function testEditedGuestsKeepingThePricedAgesAreAccepted(): void
    {
        self::assertTrue(BookedRooms::keepsPricedChildAges(self::row(), [
            'room1_adult_1' => self::guest('adult', 1),
            'room2_adult_1' => self::guest('adult', 2),
            'room2_child_1' => self::guest('child', 2, 4),
            'room2_child_2' => self::guest('child', 2, 8),
        ]));
        // A child left without a name (not in guests_data) keeps its priced age
        self::assertTrue(BookedRooms::keepsPricedChildAges(self::row(), ['room2_child_2' => self::guest('child', 2, 8)]));
    }

    public function testAnEditedChildAgeIsRefused(): void
    {
        // A new date of birth makes the child 14: the stay was priced for 4
        self::assertFalse(BookedRooms::keepsPricedChildAges(self::row(), [
            'room2_child_1' => self::guest('child', 2, 14),
            'room2_child_2' => self::guest('child', 2, 8),
        ]));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array<string, mixed>}>
     */
    public static function unverifiableEdits(): iterable
    {
        yield 'a guest beyond the booked occupancy' => [self::row(), ['room1_child_1' => self::guest('child', 1, 4)]];
        yield 'a guest in a room that was not booked' => [self::row(), ['room3_adult_1' => self::guest('adult', 3)]];
        yield 'an aliased guest key' => [self::row(), ['room02_child_1' => self::guest('child', 2, 1)]];
        yield 'a row whose rooms cannot be read' => [self::row(['rooms_data' => '{oops']), []];
        yield 'a row child without a priced age' => [self::row(['rooms_data' => json_encode([['room_id' => 'DBL', 'adults' => 2, 'children' => 1]])]), []];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $guests
     */
    #[DataProvider('unverifiableEdits')]
    public function testAnEditThatCannotBeCheckedIsRefused(array $row, array $guests): void
    {
        self::assertFalse(BookedRooms::keepsPricedChildAges($row, $guests));
    }

    public function testARowWithoutRoomsDataIsItsOwnRoom(): void
    {
        $row = self::row(['rooms_data' => '', 'room_id' => 'DBL', 'adults' => '2', 'children' => '1', 'children_ages' => '6']);

        self::assertTrue(BookedRooms::keepsPricedChildAges($row, ['room1_child_1' => self::guest('child', 1, 6)]));
        self::assertFalse(BookedRooms::keepsPricedChildAges($row, ['room1_child_1' => self::guest('child', 1, 7)]));
        self::assertTrue(BookedRooms::keepsPricedChildAges(['rooms_data' => '[]'] + $row, ['room1_child_1' => self::guest('child', 1, 6)]));
    }

    /**
     * A novoton_bookings row as add_to_cart stores it (two rooms, the second
     * with children of 4 and 8).
     *
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private static function row(array $override = []): array
    {
        return $override + [
            'room_id' => 'DBL, FAM 2+2',
            'board_id' => 'BB',
            'package_name' => 'P1',
            'adults' => '4',
            'children' => '2',
            'children_ages' => '4,8',
            'rooms_data' => json_encode([
                ['room_id' => 'DBL', 'board_id' => 'BB', 'package_name' => 'P1', 'adults' => 2, 'children' => 0, 'childrenAges' => [], 'price' => 700.0, 'price_raw' => 636.36],
                ['room_id' => 'FAM 2+2', 'board_id' => 'AI', 'package_name' => 'P2', 'adults' => 2, 'children' => 2, 'childrenAges' => [4, 8], 'price' => 900.0, 'price_raw' => 818.18],
            ]),
        ];
    }

    /**
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private static function request(array $override = []): array
    {
        return $override + [
            'hotel_id' => '476',
            'room_id' => 'DBL 2+1',
            'board_id' => 'BB',
            'package_name' => 'ADMIRAL +BEACH',
            'adults' => '2',
            'children' => '0',
            'check_in' => '2026-10-05',
            'check_out' => '2026-10-11',
        ];
    }

    /**
     * A guest as GuestDataService::parseAndValidateGuests() returns it.
     *
     * @return array<string, mixed>
     */
    private static function guest(string $type, int $room, int $age = 30): array
    {
        return ['name' => 'Pop, Ana', 'api_name' => 'Ana Pop', 'type' => $type, 'age' => $age, 'room' => $room];
    }
}
