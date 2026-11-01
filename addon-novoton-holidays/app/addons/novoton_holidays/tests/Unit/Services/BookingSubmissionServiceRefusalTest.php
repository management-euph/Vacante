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
 * A reservation the hotel refuses (RQ) or cancels (ST) as soon as it is sent
 * tells the store what to refund, as a refusal the poller sees later does
 * (RequestAnswerMail); a confirmed one tells nobody here.
 */
#[CoversClass(BookingSubmissionService::class)]
final class BookingSubmissionServiceRefusalTest extends TestCase
{
    protected function tearDown(): void
    {
        ConfigProvider::reset();
    }

    /** @return list<array{array<string, mixed>, string}> the bookings and statuses handed on */
    private function submit(string $answer): array
    {
        $row = [
            'booking_id' => 12, 'order_id' => 0, 'hotel_id' => '476', 'hotel_name' => 'Admiral',
            'package_name' => 'ADMIRAL ***** +BEACH 2027', 'check_in' => '2027-07-10', 'check_out' => '2027-07-17',
            'total_price' => 2632.0,
            'rooms_data' => [['room_id' => 'FAM 2+3 DELUXE', 'board_id' => 'AI', 'package_name' => 'ADMIRAL ***** +BEACH 2027',
                'adults' => 2, 'children' => 0, 'childrenAges' => [], 'price' => 2632.0]],
            'guests_data' => ['room1_adult_1' => ['api_name' => 'Ana Pop', 'age' => 40], 'room1_adult_2' => ['api_name' => 'Ion Pop', 'age' => 40]],
        ];
        $repo = $this->createMock(BookingRepositoryInterface::class);
        $repo->method('findById')->willReturn($row);
        $repo->method('findByIdHydrated')->willReturn($row);
        $repo->method('update')->willReturn(true);

        $reservations = $this->createMock(ReservationApiClientInterface::class);
        $reservations->method('createReservation')->willReturn(
            new \SimpleXMLElement('<hotel_res_RS><IdNum>311922</IdNum><Status>' . $answer . '</Status></hotel_res_RS>'),
        );

        $handed = [];
        $answered = static function (array $booking, string $status) use (&$handed): string {
            $handed[] = [$booking, $status];

            return 'store';
        };

        (new BookingSubmissionService($repo, $reservations, null, $answered))->submitOrder(55, ['products' => [
            'c1' => ['product_id' => 7, 'price' => 2632.0, 'extra' => ['novoton_booking' => true, 'novoton_booking_id' => 12]],
        ]]);

        return $handed;
    }

    public function testARefusalAsSoonAsSentTellsTheStore(): void
    {
        $handed = $this->submit('RQ');

        self::assertCount(1, $handed);
        [$booking, $status] = $handed[0];
        self::assertSame('RQ', $status);
        self::assertSame([55, '311922', 'Admiral', 2632.0], [$booking['order_id'], $booking['novoton_invoice_id'], $booking['hotel_name'], $booking['total_price']]);
    }

    public function testACancellationAsSoonAsSentTellsTheStoreToo(): void
    {
        self::assertSame('ST', $this->submit('ST')[0][1] ?? null);
    }

    public function testAConfirmedOrAskedReservationTellsNobodyHere(): void
    {
        self::assertSame([], $this->submit('OK'));
        self::assertSame([], $this->submit('ASK'), 'the poller follows it');
    }
}
