<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Api;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Api\RequestGate;
use Tygh\Addons\NovotonHolidays\Api\ReservationApiClient;
use Tygh\Addons\NovotonHolidays\NovotonHttpClient;
use Tygh\Addons\NovotonHolidays\NovotonXmlParser;
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
