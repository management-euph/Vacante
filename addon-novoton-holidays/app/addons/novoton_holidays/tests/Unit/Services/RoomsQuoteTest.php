<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Api\Contracts\PricingApiClientInterface;
use Tygh\Addons\NovotonHolidays\Helpers\BookingRoomAssembler;
use Tygh\Addons\NovotonHolidays\Services\ApiBookingRequestBuilder;
use Tygh\Addons\NovotonHolidays\Services\BookedRooms;
use Tygh\Addons\NovotonHolidays\Services\RoomOfferRows;
use Tygh\Addons\NovotonHolidays\Services\RoomsQuote;

/**
 * add_to_cart prices every room the reservation books (audit C2/H4).
 *
 * The room_price double answers like Novoton: only the asked room, priced
 * for the asked occupancy. Commission 10 %, applied per room.
 *
 *   DBL / BB (package P):        950 for 2 adults, 1,050 with one child
 *   FAM 2+2 / BB (package P):    750 for 2 adults + 1 child
 *   SUITE / AI (package LUX):  1,500 for 2 adults
 */
#[CoversClass(RoomsQuote::class)]
#[CoversClass(BookedRooms::class)]
final class RoomsQuoteTest extends TestCase
{
    private const float COMMISSION = 10.0;

    /** @var list<array<string, mixed>> */
    private array $calls = [];

    protected function setUp(): void
    {
        $this->calls = [];
    }

    /**
     * C2: the form posts a cheap double room (and its price) while
     * rooms_data books two all-inclusive suites. The charge is both suites.
     */
    public function testTamperedRoomsDataIsChargedEveryBookedSuite(): void
    {
        $request = self::request([
            'room_id' => 'DBL',
            'board_id' => 'BB',
            'adults' => '2',
            'total_price' => '1045',
            'rooms_data' => json_encode([
                ['room_id' => 'SUITE', 'board_id' => 'AI', 'package_name' => 'LUX', 'adults' => 2, 'children' => 0, 'price' => 1],
                ['room_id' => 'SUITE', 'board_id' => 'AI', 'package_name' => 'LUX', 'adults' => 2, 'children' => 0, 'price' => 1],
            ]),
        ]);

        $quote = $this->quote($request, []);

        self::assertNotNull($quote);
        self::assertEqualsWithDelta(3300.0, $quote['total'], 0.001);
        self::assertEqualsWithDelta(3000.0, $quote['base'], 0.001);
        self::assertSame(['SUITE', 'SUITE'], array_column($quote['rooms'], 'room_id'));
        self::assertSame(['AI', 'AI'], array_column($quote['rooms'], 'board_id'));
        // The request's cheap double room is never asked for
        self::assertSame(['SUITE'], array_values(array_unique(array_column($this->calls, 'room_id'))));
        // Identical rooms share one room_price call
        self::assertCount(1, $this->calls);
    }

    /**
     * H4: two rooms worth 1,700 (net) with a child in room 2. The old quote
     * was room 1's type for 2 adults plus every child (1,050) and the cart
     * was set to it. Now each room is priced for its own guests.
     */
    public function testAMultiRoomBookingWithChildrenIsChargedTheSumOfItsRooms(): void
    {
        $request = self::request([
            'room_id' => 'DBL',
            'adults' => '2',
            'rooms_data' => json_encode([
                ['room_id' => 'DBL', 'board_id' => 'BB', 'package_name' => 'P', 'adults' => 2, 'children' => 0, 'childrenAges' => []],
                ['room_id' => 'FAM 2+2', 'board_id' => 'BB', 'package_name' => 'P', 'adults' => 2, 'children' => 1, 'childrenAges' => [7]],
            ]),
        ]);
        $guests = [
            'room1_adult_1' => self::guest('adult', 1),
            'room2_adult_1' => self::guest('adult', 2),
            'room2_child_1' => self::guest('child', 2, 7),
        ];

        $quote = $this->quote($request, $guests);

        self::assertNotNull($quote);
        self::assertEqualsWithDelta(1700.0, $quote['base'], 0.001);
        self::assertEqualsWithDelta(1870.0, $quote['total'], 0.001);
        self::assertEqualsWithDelta(1045.0, $quote['rooms'][0]['price'], 0.001);
        self::assertEqualsWithDelta(825.0, $quote['rooms'][1]['price'], 0.001);
        self::assertSame(
            [['DBL', 2, []], ['FAM 2+2', 2, [7]]],
            array_map(static fn (array $call): array => [$call['room_id'], $call['adults'], $call['children']], $this->calls),
        );
    }

    /** One room: the same room_price call and offer as before, now the only price. */
    public function testASingleRoomIsPricedAsBefore(): void
    {
        $request = self::request([
            'room_id' => 'DBL',
            'board_id' => '',
            'adults' => '2',
            'total_price' => '2000',
            'rooms_data' => json_encode([['adults' => 2, 'children' => 1, 'childrenAges' => [5]]]),
        ]);

        $quote = $this->quote($request, ['room1_child_1' => self::guest('child', 1, 5)]);

        self::assertNotNull($quote);
        // The posted 2,000 is not the charge
        self::assertEqualsWithDelta(1155.0, $quote['total'], 0.001);
        self::assertSame([[
            'hotel_id' => '476',
            'room_id' => 'DBL',
            'board_id' => '',
            'star_rating' => '',
            'check_in' => '2026-10-05',
            'check_out' => '2026-10-11',
            'adults' => 2,
            'children' => [5],
        ]], $this->calls);
        // No board asked for: the board it was priced on is the one booked
        self::assertSame('BB', $quote['rooms'][0]['board_id']);
        self::assertSame('P', $quote['rooms'][0]['package_name']);
        self::assertSame('DBL', $quote['offer']['room']);
    }

    /**
     * Holiday Park (270): Novoton answers "DBL 2+0 ECONOMY" on "ALL INCL"
     * with an empty document (false) and the hotel's every room and board
     * with the offer the search showed. add_to_cart rejected the booking
     * ("Verificarea prețului a eșuat"); it is priced from that answer now,
     * with that offer's own terms.
     */
    public function testARoomNovotonPricesOnlyWithoutItsBoardIsPricedAsTheSearchShowedIt(): void
    {
        $calls = [];
        $pricing = $this->createStub(PricingApiClientInterface::class);
        $pricing->method('applyCommission')->willReturnCallback(
            static fn (float $price): float => $price * (1 + self::COMMISSION / 100),
        );
        $pricing->method('getRoomPrice')->willReturnCallback(static function (array $params) use (&$calls): \SimpleXMLElement|false {
            $calls[] = [$params['room_id'], $params['board_id']];
            if ($params['board_id'] !== '') {
                return false;
            }

            return new \SimpleXMLElement('<room_price>'
                . '<agent>T</agent><idcont>1</idcont><PackageName>HOLIDAY PARK **** +AQUAPARK 2027</PackageName>'
                . '<Price>750.00</Price><IdRoom>TRP 2+1 AQUA</IdRoom><Board>ALL INCL</Board>'
                . '<TermsOfPayment><Percent tillDate="2027-06-01">100</Percent></TermsOfPayment>'
                . '<agent>T</agent><idcont>2</idcont><PackageName>HOLIDAY PARK **** 2027</PackageName>'
                . '<Price>613.50</Price><IdRoom>DBL 2+0 ECONOMY</IdRoom><Board>ALL INCL</Board>'
                . '<TermsOfPayment><Percent tillDate="2027-06-20">100</Percent></TermsOfPayment>'
                . '</room_price>');
        });
        $rooms = BookedRooms::fromRequest(self::request([
            'room_id' => 'DBL 2+0 ECONOMY',
            'board_id' => 'ALL INCL',
            'package_name' => 'HOLIDAY PARK **** 2027',
        ]), []);
        self::assertNotNull($rooms);

        $quote = (new RoomsQuote($pricing))->price('270', '2027-07-10', '2027-07-17', $rooms);

        self::assertNotNull($quote);
        self::assertEqualsWithDelta(674.85, $quote['total'], 0.001);
        self::assertSame(['ALL INCL', 'HOLIDAY PARK **** 2027'], [$quote['rooms'][0]['board_id'], $quote['rooms'][0]['package_name']]);
        self::assertSame([['DBL 2+0 ECONOMY', 'ALL INCL'], ['', '']], $calls);
        self::assertStringContainsString('2027-06-20', RoomOfferRows::terms($quote['answer'], $quote['offer'])['terms_of_payment']);
    }

    /**
     * Admiral (476), 5–9 Nov 2026: the room on "BB" comes back as "BB" alone
     * at 415 and "BB" with the "HB" supplement (half board) at 535. A
     * half-board booking is charged 535 and the reservation books "BB" +
     * "HB"; it was charged 415 and booked on breakfast alone.
     */
    public function testAHalfBoardSupplementIsChargedAndBooked(): void
    {
        $pricing = $this->createStub(PricingApiClientInterface::class);
        $pricing->method('applyCommission')->willReturnCallback(
            static fn (float $price): float => $price * (1 + self::COMMISSION / 100),
        );
        $offer = static fn (string $price, string $ext): string => '<agent>T</agent><idcont>1292618</idcont>'
            . '<PackageName>ADMIRAL ***** BB/HB [STAY 22.09 - 19.12]</PackageName><Price>' . $price . '</Price>'
            . '<IdRoom>DBL 2+0 DELUXE NO BALCONY</IdRoom><Board>BB</Board><ExtBoard>' . $ext . '</ExtBoard>';
        $pricing->method('getRoomPrice')->willReturn(new \SimpleXMLElement('<room_price>' . $offer('415.00', '') . $offer('535.00', 'HB') . '</room_price>'));
        $booked = static fn (string $ext): ?array => BookedRooms::fromRequest(self::request([
            'room_id' => 'DBL 2+0 DELUXE NO BALCONY',
            'board_id' => 'BB',
            'ext_board_id' => $ext,
            'package_name' => 'ADMIRAL ***** BB/HB [STAY 22.09 - 19.12]',
        ]), []);
        $half = $booked('HB');
        $plain = $booked('');
        self::assertNotNull($half);
        self::assertNotNull($plain);

        $quote = (new RoomsQuote($pricing))->price('476', '2026-11-05', '2026-11-09', $half);
        $plainQuote = (new RoomsQuote($pricing))->price('476', '2026-11-05', '2026-11-09', $plain);

        self::assertNotNull($quote);
        self::assertNotNull($plainQuote);
        self::assertEqualsWithDelta(588.5, $quote['total'], 0.001);
        self::assertEqualsWithDelta(456.5, $plainQuote['total'], 0.001);
        self::assertSame(['BB', 'HB'], [$quote['rooms'][0]['board_id'], $quote['rooms'][0]['ext_board_id']]);

        $bookingData = ['hotel_id' => '476', 'room_id' => 'X', 'board_id' => 'X', 'package_name' => 'X', 'check_in' => '2026-11-05', 'check_out' => '2026-11-09', 'holder_name' => 'Ana Pop'];
        $assembler = new BookingRoomAssembler();
        $groups = array_values($assembler->groupRoomsByPackage($quote['rooms'], $bookingData));
        [$allGuests, $apiRooms] = $assembler->buildGroupGuestsAndRooms($groups[0], [], $bookingData, self::COMMISSION);
        $apiData = (new ApiBookingRequestBuilder())->build($groups[0], $allGuests, $apiRooms, $bookingData, 1001, 1, 1);

        self::assertSame(['BB', 'HB'], [$apiRooms[0]['board_id'], $apiRooms[0]['ext_board_id']]);
        self::assertSame(['BB', 'HB'], [$apiData['board_id'], $apiData['ext_board_id']]);
    }

    public function testARoomWithoutAPriceRejectsTheWholeBooking(): void
    {
        $request = self::request([
            'rooms_data' => json_encode([
                ['room_id' => 'DBL', 'board_id' => 'BB', 'adults' => 2],
                // No FAM room for 3 adults + 2 children
                ['room_id' => 'FAM 2+2', 'board_id' => 'BB', 'adults' => 3, 'children' => 2, 'childrenAges' => [3, 4]],
            ]),
        ]);

        self::assertNull($this->quote($request, []));
    }

    /**
     * What is sent to Novoton is what was priced: the reservation built
     * from the priced rooms books the same rooms, boards, package and child
     * ages, and the booking record's price is the quote (novoton-money-9).
     */
    public function testTheReservationBooksThePricedRooms(): void
    {
        $request = self::request([
            'rooms_data' => json_encode([
                ['room_id' => 'DBL', 'board_id' => 'BB', 'package_name' => 'P', 'adults' => 2, 'children' => 0, 'price' => 1],
                ['room_id' => 'FAM 2+2', 'board_id' => 'BB', 'package_name' => 'P', 'adults' => 2, 'children' => 1, 'childrenAges' => [7], 'price' => 1],
            ]),
        ]);
        $guests = ['room2_child_1' => self::guest('child', 2, 7)];
        $quote = $this->quote($request, $guests);
        self::assertNotNull($quote);

        $assembler = new BookingRoomAssembler();
        $bookingData = ['hotel_id' => '476', 'room_id' => 'X', 'board_id' => 'X', 'package_name' => 'X', 'check_in' => '2026-10-05', 'check_out' => '2026-10-11', 'holder_name' => 'Ana Pop'];
        $groups = array_values($assembler->groupRoomsByPackage($quote['rooms'], $bookingData));
        self::assertCount(1, $groups);
        [$allGuests, $apiRooms, $totalApiPrice, $totalGroupPrice] = $assembler->buildGroupGuestsAndRooms($groups[0], $guests, $bookingData, self::COMMISSION);
        $apiData = (new ApiBookingRequestBuilder())->build($groups[0], $allGuests, $apiRooms, $bookingData, 1001, 1, 1);

        self::assertSame('P', $apiData['package_name']);
        self::assertSame([['DBL', 'BB'], ['FAM 2+2', 'BB']], array_map(static fn (array $room): array => [$room['room_id'], $room['board_id']], $apiRooms));
        $childAges = array_column(array_filter($allGuests, static fn (array $guest): bool => $guest['type'] === 'child'), 'age');
        self::assertSame([7], array_values($childAges));
        self::assertEqualsWithDelta($quote['total'], $totalGroupPrice, 0.001);
        self::assertEqualsWithDelta($quote['base'], $totalApiPrice, 0.001);
    }

    /**
     * @param array<string, mixed> $request
     * @param array<string, mixed> $guests
     * @return array<string, mixed>|null
     */
    private function quote(array $request, array $guests): ?array
    {
        $rooms = BookedRooms::fromRequest($request, $guests);
        self::assertNotNull($rooms);

        return (new RoomsQuote($this->pricing()))->price('476', '2026-10-05', '2026-10-11', $rooms, ['source' => 'test']);
    }

    private function pricing(): PricingApiClientInterface
    {
        $pricing = $this->createStub(PricingApiClientInterface::class);
        $pricing->method('applyCommission')->willReturnCallback(
            static fn (float $price): float => $price * (1 + self::COMMISSION / 100),
        );
        $pricing->method('getRoomPrice')->willReturnCallback(function (array $params): \SimpleXMLElement {
            $this->calls[] = $params;
            $children = is_array($params['children']) ? count($params['children']) : 0;
            $occupancy = $params['adults'] . '+' . $children;
            $table = [
                'DBL' => ['board' => 'BB', 'package' => 'P', 'prices' => ['2+0' => 950.0, '2+1' => 1050.0]],
                'FAM 2+2' => ['board' => 'BB', 'package' => 'P', 'prices' => ['2+1' => 750.0]],
                'SUITE' => ['board' => 'AI', 'package' => 'LUX', 'prices' => ['2+0' => 1500.0]],
            ];
            $offer = $table[$params['room_id']] ?? null;
            $xml = '<room_price>';
            if ($offer !== null && isset($offer['prices'][$occupancy])) {
                $xml .= '<agent>T</agent><idcont>1</idcont><PackageName>' . $offer['package'] . '</PackageName>'
                    . '<Price>' . $offer['prices'][$occupancy] . '</Price><IdRoom>' . $params['room_id'] . '</IdRoom>'
                    . '<Board>' . $offer['board'] . '</Board>'
                    . '<TermsOfPayment><Percent tillDate="2026-09-30">100</Percent></TermsOfPayment>';
            }

            return new \SimpleXMLElement($xml . '</room_price>');
        });

        return $pricing;
    }

    /**
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private static function request(array $override): array
    {
        return $override + [
            'hotel_id' => '476',
            'room_id' => 'DBL',
            'board_id' => 'BB',
            'package_name' => '',
            'adults' => '2',
            'check_in' => '2026-10-05',
            'check_out' => '2026-10-11',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function guest(string $type, int $room, int $age = 30): array
    {
        return ['name' => 'Pop, Ana', 'api_name' => 'Ana Pop', 'type' => $type, 'age' => $age, 'room' => $room];
    }
}
