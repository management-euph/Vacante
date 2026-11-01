<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Api\Contracts\ReservationApiClientInterface;
use Tygh\Addons\NovotonHolidays\Repository\BookingRepositoryInterface;
use Tygh\Addons\NovotonHolidays\Services\BookingSubmissionService;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;

/**
 * A booking of several rooms reaches Novoton in one hotel_res_RQ when its
 * rooms are for the same hotel, the same package and the same stay, the
 * condition Novoton's documentation sets for several rooms in one request;
 * a room of another package goes in a request of its own.
 */
#[CoversClass(BookingSubmissionService::class)]
final class BookingSubmissionServiceOneRequestTest extends TestCase
{
    protected function tearDown(): void
    {
        ConfigProvider::reset();
    }

    /**
     * Admiral (476), 10–17 Jul 2027: the rooms as add_to_cart stored them,
     * two adults each.
     *
     * @param list<string> $packages each room's package
     * @return list<array<string, mixed>> the requests sent, in order
     */
    private function submit(array $packages): array
    {
        $rooms = [];
        $guests = [];
        foreach ($packages as $i => $package) {
            $n = $i + 1;
            $rooms[] = ['room_id' => 'DBL 2+0 DELUXE', 'board_id' => 'ULTRA ALL INCL', 'package_name' => $package,
                'adults' => 2, 'children' => 0, 'childrenAges' => [], 'price' => 1485.0];
            $guests["room{$n}_adult_1"] = ['api_name' => "Guest {$n}A", 'age' => 40];
            $guests["room{$n}_adult_2"] = ['api_name' => "Guest {$n}B", 'age' => 40];
        }

        $repo = $this->createMock(BookingRepositoryInterface::class);
        $repo->method('findById')->willReturn(['booking_id' => 12, 'order_id' => 0]);
        $repo->method('findByIdHydrated')->willReturn([
            'booking_id' => 12, 'hotel_id' => '476', 'hotel_name' => 'Admiral', 'package_name' => $packages[0],
            'check_in' => '2027-07-10', 'check_out' => '2027-07-17', 'nights' => 7, 'total_price' => 1485.0 * count($packages),
            'rooms_data' => $rooms, 'guests_data' => $guests,
        ]);
        $repo->method('update')->willReturn(true);
        $repo->method('create')->willReturn(13);

        $sent = [];
        $reservations = $this->createMock(ReservationApiClientInterface::class);
        $reservations->method('createReservation')->willReturnCallback(static function (array $data) use (&$sent): \SimpleXMLElement {
            $sent[] = $data;

            return new \SimpleXMLElement('<hotel_res_RS><IdNum>3119' . count($sent) . '</IdNum><Status>Good</Status></hotel_res_RS>');
        });

        (new BookingSubmissionService($repo, $reservations))->submitOrder(55, ['products' => [
            'c1' => ['product_id' => 7, 'price' => 1485.0 * count($packages), 'extra' => ['novoton_booking' => true, 'novoton_booking_id' => 12]],
        ]]);

        return $sent;
    }

    public function testRoomsOfOneHotelPackageAndStayAreOneRequest(): void
    {
        // The third room's "+BEACH" came back from a link as " BEACH": the same package
        $sent = $this->submit(['ADMIRAL ***** +BEACH 2027', 'ADMIRAL ***** +BEACH 2027', 'ADMIRAL *****  BEACH 2027']);

        self::assertCount(1, $sent, 'one hotel_res_RQ');
        self::assertSame(
            ['476', 'ADMIRAL ***** +BEACH 2027', '2027-07-10', '2027-07-17', '55'],
            [$sent[0]['hotel_id'], $sent[0]['package_name'], $sent[0]['check_in'], $sent[0]['check_out'], $sent[0]['order_num']],
        );
        self::assertCount(3, $sent[0]['rooms'], 'one <hotel_acc> per room');
        self::assertSame([1, 2, 3], array_column($sent[0]['rooms'], 'room_num'));
        self::assertCount(6, $sent[0]['guests'], 'every guest once');
    }

    public function testARoomOfAnotherPackageIsARequestOfItsOwn(): void
    {
        $sent = $this->submit(['ADMIRAL ***** +BEACH 2027', 'ADMIRAL ***** 2027', 'ADMIRAL ***** +BEACH 2027']);

        self::assertCount(2, $sent);
        self::assertSame(['55-G1', '55-G2'], array_column($sent, 'order_num'));
        self::assertSame(['ADMIRAL ***** +BEACH 2027', 'ADMIRAL ***** 2027'], array_column($sent, 'package_name'));
        self::assertSame([[1, 3], [2]], [array_column($sent[0]['rooms'], 'room_num'), array_column($sent[1]['rooms'], 'room_num')]);
    }
}
