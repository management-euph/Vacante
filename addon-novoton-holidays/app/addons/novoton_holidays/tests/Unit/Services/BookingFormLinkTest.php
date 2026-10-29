<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Services\BookedRooms;
use Tygh\Addons\NovotonHolidays\Services\BookingFormLink;

/**
 * The booking form an add_to_cart that could not price the booking sends
 * the guest back to. The old link had no room: booking_form answered it
 * with "Date de rezervare invalide (missing room_id)" and the homepage,
 * under the "Verificarea prețului a eșuat" error.
 */
#[CoversClass(BookingFormLink::class)]
final class BookingFormLinkTest extends TestCase
{
    private const array POSTED = [
        'hotel_id' => '270',
        'room_id' => 'DBL 2+1',
        'board_id' => 'ALL INCL',
        'package_name' => 'HOLIDAY PARK **** 2027',
        'check_in' => '2027-07-10',
        'check_out' => '2027-07-17',
        'nights' => '7',
        'adults' => '2',
        'children' => '1',
        'children_ages' => '7',
        'total_price' => '721.05',
        'num_rooms' => '1',
        'rooms_data' => '[{"adults":2,"children":1,"childrenAges":[7]}]',
    ];

    public function testOneRoomGoesBackToItsOwnForm(): void
    {
        $rooms = BookedRooms::fromRequest(self::POSTED, []);
        self::assertNotNull($rooms);

        $link = self::query(BookingFormLink::back(self::POSTED, 77, '7', $rooms, 721.05));

        self::assertSame([
            'hotel_id' => '270', 'product_id' => '77', 'room_id' => 'DBL 2+1', 'board_id' => 'ALL INCL',
            'package_name' => 'HOLIDAY PARK **** 2027', 'check_in' => '2027-07-10', 'check_out' => '2027-07-17',
            'nights' => '7', 'adults' => '2', 'children' => '1', 'children_ages' => '7', 'total_price' => '721.05', 'rooms' => '1',
        ], array_diff_key($link, ['rooms_data' => true]));
        self::assertSame(
            [['room_id' => 'DBL 2+1', 'board_id' => 'ALL INCL', 'package_name' => 'HOLIDAY PARK **** 2027', 'adults' => 2, 'children' => 1, 'childrenAges' => [7]]],
            json_decode($link['rooms_data'], true),
            'the room the form books, without the posted prices',
        );
        // The form takes it as a search card's link: nothing booking_form refuses
        self::assertNotSame('', $link['room_id']);
        self::assertSame($rooms, BookedRooms::fromRequest($link, []), 'and posts the same booking again');
    }

    public function testSeveralRoomsGoBackWithEveryRoom(): void
    {
        $posted = ['rooms_data' => json_encode([
            ['room_id' => 'DBL 2+1', 'board_id' => 'ALL INCL', 'package_name' => 'HOLIDAY PARK **** 2027', 'adults' => 2, 'children' => 1, 'childrenAges' => [7], 'price' => 1],
            ['room_id' => 'TRP 2+1 AQUA', 'board_id' => 'ALL INCL', 'package_name' => 'HOLIDAY PARK **** +AQUAPARK 2027', 'adults' => 3, 'children' => 0, 'price' => 1],
        ]), 'num_rooms' => '2'] + self::POSTED;
        $rooms = BookedRooms::fromRequest($posted, []);
        self::assertNotNull($rooms);

        $link = self::query(BookingFormLink::back($posted, 77, '7', $rooms, 1500.0));

        self::assertSame('2', $link['rooms']);
        self::assertSame(['DBL 2+1', 'TRP 2+1 AQUA'], array_column((array) json_decode($link['rooms_data'], true), 'room_id'));
        self::assertArrayNotHasKey('price', (array) json_decode($link['rooms_data'], true)[0]);
    }

    public function testRoomsThatDoNotMatchTheGuestsAreNotSentBack(): void
    {
        $link = self::query(BookingFormLink::back(self::POSTED, 77, '7', null, 721.05));

        self::assertArrayNotHasKey('rooms_data', $link);
        self::assertSame(['DBL 2+1', 'ALL INCL', '1'], [$link['room_id'], $link['board_id'], $link['rooms']], 'the form opens on the posted room');
    }

    /** @return array<string, string> */
    private static function query(string $dispatch): array
    {
        self::assertStringStartsWith('novoton_booking.booking_form?', $dispatch);
        parse_str(substr($dispatch, strlen('novoton_booking.booking_form?')), $query);

        /** @var array<string, string> $query */
        return $query;
    }
}
