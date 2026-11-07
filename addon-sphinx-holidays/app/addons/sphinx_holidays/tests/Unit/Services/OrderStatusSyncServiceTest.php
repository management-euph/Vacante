<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Repository\SphinxBookingRepository;
use Tygh\Addons\SphinxHolidays\Services\OrderStatusSyncService;
use Tygh\Addons\SphinxHolidays\SphinxApi;
use Tygh\Addons\TravelCore\TravelConstants;

/**
 * The status sync settles a booking left pending without a clear answer
 * from Sphinx (BookOutcome): it reads the order's booking by its reference
 * code. It knew only confirmed, on_request and "cancelled". The book call's
 * other statuses, errored and unconfirmed (no booking made) and canceled
 * (the doc's spelling), left such a row pending for good.
 */
#[CoversClass(OrderStatusSyncService::class)]
final class OrderStatusSyncServiceTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: ?string}>
     */
    public static function statuses(): iterable
    {
        yield 'confirmed' => ['confirmed', TravelConstants::STATUS_CONFIRMED];
        yield 'errored: no booking was made' => ['errored', TravelConstants::STATUS_FAILED];
        yield 'unconfirmed: no booking was made' => ['unconfirmed', TravelConstants::STATUS_FAILED];
        yield 'canceled, the doc\'s spelling' => ['canceled', TravelConstants::STATUS_CANCELLED];
        yield 'cancelled' => ['cancelled', TravelConstants::STATUS_CANCELLED];
        yield 'processing: still pending' => ['processing', null];
        yield 'on request: still pending' => ['on_request', null];
    }

    #[DataProvider('statuses')]
    public function testAPendingBookingTakesItsStatusFromSphinx(string $apiStatus, ?string $expected): void
    {
        $repo = $this->createMock(SphinxBookingRepository::class);
        $api = $this->createMock(SphinxApi::class);
        $repo->method('findForStatusCheck')->willReturn([
            ['booking_id' => 10, 'order_id' => 501, 'hotel_name' => 'Hotel Ana', 'room_type' => 'Double', 'status' => TravelConstants::STATUS_PENDING, 'api_booking_ref' => ''],
        ]);
        $api->method('getOrders')->with(1, 10, ['reference_code' => '501'])->willReturn([
            'results' => [['id' => 9, 'reference_code' => '501', 'bookings' => [['status' => $apiStatus]]]],
        ]);
        $written = [];
        $repo->method('update')->willReturnCallback(static function (int $id, array $data) use (&$written): bool {
            $written[] = [$id, $data];

            return true;
        });

        $stats = (new OrderStatusSyncService($api, $repo))->syncAll();

        self::assertSame($expected === null ? [] : [[10, ['status' => $expected]]], $written);
        self::assertSame($expected === null ? 0 : 1, $stats['changed']);
    }
}
