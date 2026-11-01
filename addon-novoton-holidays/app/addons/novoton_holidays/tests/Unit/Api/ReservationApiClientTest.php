<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Api;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Api\RequestGate;
use Tygh\Addons\NovotonHolidays\Api\ReservationApiClient;
use Tygh\Addons\NovotonHolidays\Helpers\BookingRoomAssembler;
use Tygh\Addons\NovotonHolidays\NovotonHttpClient;
use Tygh\Addons\NovotonHolidays\NovotonXmlParser;
use Tygh\Addons\NovotonHolidays\Services\ApiBookingRequestBuilder;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Registry;

/**
 * The hotel_res_RQ a booking sends, and the same request built without
 * sending it, for the booking's admin page: its login masked.
 */
#[CoversClass(ReservationApiClient::class)]
final class ReservationApiClientTest extends TestCase
{
    private const string LOGIN = '<usr>agent-7</usr><psw>s3cr&amp;t</psw>';

    /** One room, two adults: the payload ApiBookingRequestBuilder makes. */
    private const array BOOKING = [
        'hotel_id' => '4658',
        'package_name' => 'Q-Direct booking prices ArdoVel',
        'check_in' => '2026-10-31',
        'check_out' => '2026-11-02',
        'holder' => 'Ana Pop',
        'guests' => [
            ['name' => 'Ana Pop', 'birthday' => '', 'age' => 30, 'type' => 'adult', 'room' => 1],
            ['name' => 'Ion Pop', 'birthday' => '', 'age' => 30, 'type' => 'adult', 'room' => 1],
        ],
        'rooms' => [[
            'room_id' => 'DBL 2+0',
            'board_id' => 'BB',
            'guests' => [
                ['name' => 'Ana Pop', 'birthday' => '', 'age' => 30, 'type' => 'adult', 'room' => 1],
                ['name' => 'Ion Pop', 'birthday' => '', 'age' => 30, 'type' => 'adult', 'room' => 1],
            ],
        ]],
        'order_num' => '19',
        'remark' => '',
        'comment' => 'Late arrival',
        'room_id' => 'DBL 2+0',
        'board_id' => 'BB',
    ];

    protected function tearDown(): void
    {
        Registry::set('addons.novoton_holidays', []);
        ConfigProvider::resetSettingsCache();
    }

    /** A client whose API confirms every reservation, without a network. */
    private function client(): ReservationApiClient
    {
        $http = new NovotonHttpClient(
            ['api_url' => 'http://127.0.0.1:9', 'api_key' => 'k', 'api_user' => 'agent-7', 'api_password' => 's3cr&t'],
            null,
            new RequestGate('/nonexistent-dir/novoton_gate.json'),
        );

        return new class ($http, new NovotonXmlParser(), null, false) extends ReservationApiClient {
            #[\Override]
            protected function callApi(string $function, string $xml, string $lang = 'UK'): string
            {
                return '<hotel_res_RS><IdNum>311921</IdNum><Status>Good</Status></hotel_res_RS>';
            }
        };
    }

    public function testTheRequestShownIsTheRequestSentWithItsLoginMasked(): void
    {
        $client = $this->client();
        $client->createReservation(self::BOOKING);
        $shown = $client->generateReservationXml(self::BOOKING);

        self::assertStringContainsString(self::LOGIN, $client->lastRequest, 'what goes to Novoton carries the login');
        self::assertSame(str_replace(self::LOGIN, '<usr>*****</usr><psw>*****</psw>', $client->lastRequest), $shown);
        self::assertStringNotContainsString('agent-7', $shown);
        self::assertStringNotContainsString('s3cr', $shown);
    }

    public function testBuildingTheRequestSendsNothing(): void
    {
        $client = $this->client();
        $client->generateReservationXml(self::BOOKING);

        self::assertSame('', $client->lastRequest);
    }

    public function testTheRequestNamesTheHotelTheRoomAndTheStay(): void
    {
        $rq = simplexml_load_string($this->client()->generateReservationXml(self::BOOKING));

        self::assertNotFalse($rq);
        self::assertSame('hotel_res_RQ', $rq->getName());
        self::assertSame('4658', (string) $rq->IdHotel);
        self::assertSame('Q-Direct booking prices ArdoVel', (string) $rq->PackageName);
        self::assertSame('2026-10-31', (string) $rq->hotel_acc->CheckIn);
        self::assertSame('DBL 2+0', (string) $rq->hotel_acc->IdRoom);
        self::assertSame('BB', (string) $rq->hotel_acc->IdBoard);
        self::assertSame('Late arrival', (string) $rq->hotel_acc->Comment);
        self::assertSame('19', (string) $rq->OrderNum);
    }

    /**
     * Admiral's half board off-season is "BB" with the "HB" supplement: the
     * reservation books both, room by room. It was always sent empty, so a
     * half-board stay was booked on breakfast alone.
     */
    public function testTheBoardsSupplementIsBooked(): void
    {
        $one = simplexml_load_string($this->client()->generateReservationXml(['ext_board_id' => 'HB'] + self::BOOKING));
        $plain = simplexml_load_string($this->client()->generateReservationXml(self::BOOKING));
        $two = simplexml_load_string($this->client()->generateReservationXml(['rooms' => [
            ['room_id' => 'DBL 2+0', 'board_id' => 'BB', 'ext_board_id' => 'HB', 'guests' => []],
            ['room_id' => 'DBL 2+0', 'board_id' => 'BB', 'guests' => []],
        ]] + self::BOOKING));

        self::assertNotFalse($one);
        self::assertNotFalse($plain);
        self::assertNotFalse($two);
        self::assertSame(['BB', 'HB'], [(string) $one->hotel_acc->IdBoard, (string) $one->hotel_acc->IdExtBoard]);
        self::assertSame('', (string) $plain->hotel_acc->IdExtBoard);
        self::assertSame(['HB', ''], [(string) $two->hotel_acc[0]->IdExtBoard, (string) $two->hotel_acc[1]->IdExtBoard]);
    }

    public function testEveryGuestIsSent(): void
    {
        $rq = simplexml_load_string($this->client()->generateReservationXml(self::BOOKING));

        self::assertNotFalse($rq);
        self::assertSame(['1' => 'Ana Pop', '2' => 'Ion Pop'], self::guests($rq->Guests));
        self::assertSame('30', (string) $rq->Guests[0]->Age);
        self::assertSame(['1' => 'Ana Pop', '2' => 'Ion Pop'], self::guests($rq->hotel_acc->room_acc), 'the room lists who sleeps in it');
    }

    public function testEachRoomListsItsOwnGuestsNumberedAcrossTheBooking(): void
    {
        $adult = static fn (string $name): array => ['name' => $name, 'birthday' => '', 'age' => 30, 'type' => 'adult'];
        $child = ['name' => 'Dan Pop', 'birthday' => '2019-05-01', 'age' => 7, 'type' => 'child'];
        $booking = ['guests' => [$adult('Ana Pop'), $adult('Ion Pop'), $adult('Eva Ilie'), $adult('Radu Ilie'), $child], 'rooms' => [
            ['room_id' => 'DBL 2+0', 'board_id' => 'BB', 'guests' => [$adult('Ana Pop'), $adult('Ion Pop')]],
            ['room_id' => 'FAM 2+2', 'board_id' => 'HB', 'guests' => [$adult('Eva Ilie'), $adult('Radu Ilie'), $child]],
        ]] + self::BOOKING;

        $rq = simplexml_load_string($this->client()->generateReservationXml($booking));

        self::assertNotFalse($rq);
        self::assertSame(['1' => 'Ana Pop', '2' => 'Ion Pop', '3' => 'Eva Ilie', '4' => 'Radu Ilie', '5' => 'Dan Pop'], self::guests($rq->Guests));
        self::assertSame('7', (string) $rq->Guests[4]->Age);
        self::assertCount(2, $rq->hotel_acc);
        self::assertSame(['1' => 'Ana Pop', '2' => 'Ion Pop'], self::guests($rq->hotel_acc[0]->room_acc));
        self::assertSame(['3' => 'Eva Ilie', '4' => 'Radu Ilie', '5' => 'Dan Pop'], self::guests($rq->hotel_acc[1]->room_acc));
        self::assertSame('Eva Ilie', (string) $rq->hotel_acc[1]->Holder);
    }

    /**
     * Novoton's documented example of several rooms in one request: SLAVEY
     * **** (387), package "SLAVEY ****", 1–6 Jul 2026, two "DBL 2+1" on ALL
     * INCL, each with two adults and a child of 7. The booking's rooms_data
     * and guests, through the store's grouping and request builder, give
     * that request: the hotel, package and stay once, the six guests once,
     * one <hotel_acc> per room with its holder, its three guests and the
     * guest's notes from checkout as its remark.
     *
     * IdRoom keeps its "+": the form post sends it as the example's "%2b".
     * IdStar stays empty, as in every reservation the store has sent.
     */
    public function testSeveralRoomsGoInOneRequestAsInNovotonsExample(): void
    {
        $booking = ['hotel_id' => '387', 'package_name' => 'SLAVEY ****', 'check_in' => '2026-07-01', 'check_out' => '2026-07-06'];
        $room = ['room_id' => 'DBL 2+1', 'board_id' => 'ALL INCL', 'package_name' => 'SLAVEY ****', 'adults' => 2, 'children' => 1, 'childrenAges' => [7], 'price' => 900];
        $guests = [
            'room1_adult_1' => ['api_name' => 'Name 1', 'age' => 40], 'room1_adult_2' => ['api_name' => 'Name 2', 'age' => 40],
            'room1_child_1' => ['api_name' => 'Name 3', 'age' => 7, 'birthday' => '2018-08-25'],
            'room2_adult_1' => ['api_name' => 'Name 4', 'age' => 40], 'room2_adult_2' => ['api_name' => 'Name 5', 'age' => 40],
            'room2_child_1' => ['api_name' => 'Name 6', 'age' => 7, 'birthday' => '2019-01-10'],
        ];
        $assembler = new BookingRoomAssembler();
        $groups = array_values($assembler->groupRoomsByPackage([$room, $room], $booking));
        self::assertCount(1, $groups, 'one request');
        [$all, $rooms] = $assembler->buildGroupGuestsAndRooms($groups[0], $guests, $booking, 0.0);
        $notes = 'Please provide rooms close to each other, if possible';
        $xml = $this->client()->generateReservationXml((new ApiBookingRequestBuilder())->build($groups[0], $all, $rooms, $booking, 7, 1, 1, $notes));
        $rq = simplexml_load_string($xml);

        self::assertNotFalse($rq);
        self::assertSame(
            ['387', 'SLAVEY ****', '2026-07-01', '2026-07-06'],
            [(string) $rq->IdHotel, (string) $rq->PackageName, (string) $rq->CheckIn, (string) $rq->CheckOut],
        );
        self::assertSame(['1' => 'Name 1', '2' => 'Name 2', '3' => 'Name 3', '4' => 'Name 4', '5' => 'Name 5', '6' => 'Name 6'], self::guests($rq->Guests));
        self::assertSame(['40', '40', '7', '40', '40', '7'], array_map(static fn (\SimpleXMLElement $g): string => (string) $g->Age, iterator_to_array($rq->Guests, false)));
        self::assertSame(['', '', '2018-08-25', '', '', '2019-01-10'], array_map(static fn (\SimpleXMLElement $g): string => (string) $g->BirthDay, iterator_to_array($rq->Guests, false)));

        self::assertCount(2, $rq->hotel_acc);
        foreach ([0 => ['Name 1', ['1' => 'Name 1', '2' => 'Name 2', '3' => 'Name 3']], 1 => ['Name 4', ['4' => 'Name 4', '5' => 'Name 5', '6' => 'Name 6']]] as $i => [$holder, $inRoom]) {
            $acc = $rq->hotel_acc[$i];
            self::assertSame(
                ['2026-07-01', '2026-07-06', 'DBL 2+1', 'ALL INCL', '', $holder],
                [(string) $acc->CheckIn, (string) $acc->CheckOut, (string) $acc->IdRoom, (string) $acc->IdBoard, (string) $acc->IdExtBoard, (string) $acc->Holder],
            );
            self::assertSame($inRoom, self::guests($acc->room_acc));
            self::assertSame($notes, (string) $acc->Remark);
            self::assertStringEndsWith('[Room ' . ($i + 1) . ']', (string) $acc->Comment);
        }
        self::assertStringContainsString('<IdRoom>DBL 2+1</IdRoom>', $xml);
        self::assertStringNotContainsStringIgnoringCase('%2b', $xml);
    }

    /**
     * The request is declared windows-1251, which has no Romanian letters:
     * names, holders, the remark and the comment go without diacritics,
     * Cyrillic as Bulgaria writes it in Latin letters.
     */
    public function testNamesAndNotesReachNovotonInPlainLatinLetters(): void
    {
        $adult = static fn (string $name): array => ['name' => $name, 'birthday' => '', 'age' => 40, 'type' => 'adult'];
        $booking = ['guests' => [$adult('Ștefan Țurcanu'), $adult('Ioana Bălan'), $adult('Николай Иванов')], 'rooms' => [
            ['room_id' => 'DBL 2+0', 'board_id' => 'BB', 'guests' => [$adult('Ștefan Țurcanu'), $adult('Ioana Bălan')]],
            ['room_id' => 'SGL', 'board_id' => 'BB', 'guests' => [$adult('Николай Иванов')]],
        ], 'remark' => 'Vă rugăm camere alăturate', 'comment' => 'Sosire târzie'] + self::BOOKING;

        $xml = $this->client()->generateReservationXml($booking);
        $rq = simplexml_load_string($xml);

        self::assertNotFalse($rq);
        self::assertSame(['1' => 'Stefan Turcanu', '2' => 'Ioana Balan', '3' => 'Nikolay Ivanov'], self::guests($rq->Guests));
        self::assertSame(['1' => 'Stefan Turcanu', '2' => 'Ioana Balan'], self::guests($rq->hotel_acc[0]->room_acc));
        self::assertSame(['Stefan Turcanu', 'Nikolay Ivanov'], [(string) $rq->hotel_acc[0]->Holder, (string) $rq->hotel_acc[1]->Holder]);
        self::assertSame('Va rugam camere alaturate', (string) $rq->hotel_acc[0]->Remark);
        self::assertSame('Sosire tarzie [Room 1]', (string) $rq->hotel_acc[0]->Comment);
        self::assertSame(1, preg_match('/^[\x00-\x7F]*$/', $xml), 'nothing windows-1251 would misread');

        $one = simplexml_load_string($this->client()->generateReservationXml(['holder' => 'Ștefan Țurcanu', 'remark' => 'Vă rog', 'comment' => 'Mulțumim'] + self::BOOKING));
        self::assertNotFalse($one);
        self::assertSame(['Stefan Turcanu', 'Va rog', 'Multumim'], [(string) $one->hotel_acc->Holder, (string) $one->hotel_acc->Remark, (string) $one->hotel_acc->Comment]);

        $request = simplexml_load_string($this->client()->generateHotelRequestXml([
            'hotel_id' => '4658', 'check_in' => '2026-10-31', 'check_out' => '2026-11-02',
            'holder' => 'Request from ana@example.com', 'remark' => 'Cameră cu vedere la mare', 'comment' => 'Contact: Ana Bălan',
            'guests' => [['id' => 1, 'name' => 'Ana Bălan']], 'room_guests' => [['id' => 1, 'name' => 'Ana Bălan']],
        ]));
        self::assertNotFalse($request);
        self::assertSame(['Camera cu vedere la mare', 'Contact: Ana Balan'], [(string) $request->hotel_acc->Remark, (string) $request->hotel_acc->Comment]);
        self::assertSame(['1' => 'Ana Balan'], self::guests($request->Guests));
    }

    public function testAnAlternativesRequestSendsItsGuestsToo(): void
    {
        $xml = $this->client()->generateHotelRequestXml([
            'hotel_id' => '4658',
            'check_in' => '2026-10-31',
            'check_out' => '2026-11-02',
            'room_id' => 'DBL 2+0',
            'guests' => [['id' => 1, 'name' => 'Guest 1', 'birthday' => '', 'age' => 30], ['id' => 2, 'name' => 'Guest 2', 'birthday' => '', 'age' => 30]],
            'room_guests' => [['id' => 1, 'name' => 'Guest 1'], ['id' => 2, 'name' => 'Guest 2']],
        ]);
        $rq = simplexml_load_string($xml);

        self::assertNotFalse($rq);
        self::assertSame(['1' => 'Guest 1', '2' => 'Guest 2'], self::guests($rq->Guests));
        self::assertSame(['1' => 'Guest 1', '2' => 'Guest 2'], self::guests($rq->hotel_acc->room_acc));
        self::assertStringContainsString('<usr>*****</usr><psw>*****</psw>', $xml);
    }

    public function testATestBookingSaysSoToNovoton(): void
    {
        Registry::set('addons.novoton_holidays', ['test_booking' => 'Y']);
        ConfigProvider::resetSettingsCache();

        $rq = simplexml_load_string($this->client()->generateReservationXml(self::BOOKING));

        self::assertNotFalse($rq);
        self::assertSame('test reservation, do not proceed', (string) $rq->hotel_acc->Remark);
        self::assertSame('test reservation, do not proceed', (string) $rq->hotel_acc->Comment);
    }

    /**
     * IdGuest => Name of each <Guests> or <room_acc> element.
     *
     * @return array<string, string>
     */
    private static function guests(?\SimpleXMLElement $elements): array
    {
        $guests = [];
        foreach ($elements ?? [] as $guest) {
            $guests[(string) $guest->IdGuest] = (string) $guest->Name;
        }

        return $guests;
    }
}
