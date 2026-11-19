<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Repository;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Repository\BookingRepository;
use Tygh\Addons\NovotonHolidays\Tests\Support\DbStub;
use Tygh\Addons\NovotonHolidays\Tests\Support\RecordingBookingSyncRepository;

/**
 * Two place-order requests for one cart both read order_id = 0 and both sent
 * the reservation. The claim is one conditional UPDATE: only the request
 * whose statement changed the row may send it.
 */
final class BookingClaimTest extends TestCase
{
    /** @var list<array{0: string, 1: list<mixed>}> */
    private array $queries = [];

    protected function tearDown(): void
    {
        DbStub::reset();
    }

    private function repo(int $affected, RecordingBookingSyncRepository $sync = new RecordingBookingSyncRepository()): BookingRepository
    {
        DbStub::reset();
        $this->queries = [];
        DbStub::$query = function (string $query, mixed ...$params) use ($affected): int {
            $this->queries[] = [$query, $params];

            return $affected;
        };

        return new BookingRepository($sync);
    }

    public function testTheRequestWhoseUpdateChangedTheRowHasTheBooking(): void
    {
        $sync = new RecordingBookingSyncRepository();
        self::assertTrue($this->repo(1, $sync)->claimForOrder(12, 55));

        self::assertSame(
            ['UPDATE ?:novoton_bookings SET order_id = ?i WHERE booking_id = ?i AND (order_id = 0 OR order_id IS NULL)', [55, 12]],
            $this->queries[0],
        );
        self::assertSame([['applyBookingUpdate', 12, ['order_id' => 55]]], $sync->calls, 'the mirror follows');
    }

    public function testABookingAnOrderAlreadyHasIsNotClaimed(): void
    {
        $sync = new RecordingBookingSyncRepository();
        self::assertFalse($this->repo(0, $sync)->claimForOrder(12, 55));
        self::assertSame([], $sync->calls);
    }

    public function testNothingIsClaimedWithoutABookingOrAnOrder(): void
    {
        self::assertFalse($this->repo(1)->claimForOrder(0, 55));
        self::assertFalse($this->repo(1)->claimForOrder(12, 0));
        self::assertSame([], $this->queries);
    }
}
