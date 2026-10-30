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

    public function testATestBookingSaysSoToNovoton(): void
    {
        Registry::set('addons.novoton_holidays', ['test_booking' => 'Y']);
        ConfigProvider::resetSettingsCache();

        $rq = simplexml_load_string($this->client()->generateReservationXml(self::BOOKING));

        self::assertNotFalse($rq);
        self::assertSame('test reservation, do not proceed', (string) $rq->hotel_acc->Remark);
        self::assertSame('test reservation, do not proceed', (string) $rq->hotel_acc->Comment);
    }
}
