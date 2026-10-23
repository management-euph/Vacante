<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Repository\BookingRepositoryInterface;
use Tygh\Addons\NovotonHolidays\Services\BookingRecordBuilder;
use Tygh\Addons\NovotonHolidays\Tests\Support\DbStub;
use Tygh\Addons\TravelCore\TravelConstants;

/**
 * Characterization coverage for BookingRecordBuilder — the booking-record
 * assembly + upsert seam extracted from BookingSubmissionService. Pins the
 * column mapping (nights, room_id CSV, occupancy sums, group numbering, holder
 * resolution, test-mode notes) and the Single-Source-of-Truth persist rules:
 * group-1 updates the cart's original booking, otherwise dedup by
 * (order + hotel + dates) before inserting.
 */
#[CoversClass(BookingRecordBuilder::class)]
class BookingRecordBuilderTest extends TestCase
{
    private BookingRepositoryInterface $repo;

    protected function setUp(): void
    {
        DbStub::reset();
        $this->repo = $this->createMock(BookingRepositoryInterface::class);
    }

    private function builder(): BookingRecordBuilder
    {
        return new BookingRecordBuilder($this->repo);
    }

    /** @return array<string, mixed> */
    private function group(): array
    {
        return [
            'package_name' => 'Summer',
            'check_in' => '2026-07-01',
            'check_out' => '2026-07-08',
            'rooms' => [
                ['room_id' => 'DBL', 'adults' => 2, 'children' => 1, 'board_id' => 'AI', 'board_name' => 'All In', 'room_name' => 'Double'],
                ['room_id' => 'SGL', 'adults' => 1, 'children' => 0],
            ],
        ];
    }

    public function testBuildMapsCoreColumns(): void
    {
        $record = $this->builder()->build(
            $this->group(),
            [['name' => 'John Doe'], ['name' => 'Jane Doe']],
            ['hotel_id' => 'H1', 'hotel_name' => 'Hotel One', 'holder_name' => 'Holder'],
            ['product_id' => 55, 'item_id' => 'IT1'],
            900,
            1,
            2,
            1200.0,
            1300.0,
            ['api' => 'payload'],
            false,
        );

        $this->assertSame(900, $record['order_id']);
        $this->assertSame('H1', $record['hotel_id']);
        $this->assertSame('Hotel One', $record['hotel_name']);
        $this->assertSame(7, $record['nights']);                 // 2026-07-01 → 2026-07-08
        $this->assertSame('DBL, SGL', $record['room_id']);       // CSV join of group rooms
        $this->assertSame(3, $record['adults']);                 // 2 + 1
        $this->assertSame(1, $record['children']);               // 1 + 0
        $this->assertSame(2, $record['num_rooms']);
        $this->assertSame(1, $record['room_number']);            // groupNum
        $this->assertSame(2, $record['total_rooms']);            // totalGroups
        $this->assertSame('John Doe, Jane Doe', $record['guest_name']);
        $this->assertSame('John Doe', $record['holder_name']);   // first guest wins
        $this->assertSame(1200.0, $record['base_price']);
        $this->assertSame(1300.0, $record['total_price']);
        $this->assertSame(TravelConstants::STATUS_PENDING, $record['status']);
        $this->assertSame('', $record['notes']);                 // API enabled
    }

    public function testBuildHolderFallsBackToBookingHolderWhenNoGuests(): void
    {
        $record = $this->builder()->build(
            $this->group(),
            [],
            ['holder_name' => 'Booking Holder'],
            [],
            1,
            1,
            1,
            0.0,
            0.0,
            [],
            false,
        );

        $this->assertSame('Booking Holder', $record['holder_name']);
        $this->assertSame('', $record['guest_name']);
    }

    public function testBuildTestModeStampsNotes(): void
    {
        $record = $this->builder()->build($this->group(), [], [], [], 1, 1, 1, 0.0, 0.0, [], true);

        $this->assertSame('API submission disabled - test mode', $record['notes']);
    }

    public function testBuildInvalidDatesFallBackToBookingNights(): void
    {
        $group = $this->group();
        $group['check_in'] = 'not-a-date';
        $group['check_out'] = 'also-bad';

        $record = $this->builder()->build($group, [], ['nights' => 5], [], 1, 1, 1, 0.0, 0.0, [], false);

        $this->assertSame(5, $record['nights']); // DateTime throws → bookingData['nights'] fallback
    }

    public function testBuildPullsOrderUserAndEmail(): void
    {
        DbStub::$getOrderInfo = static fn (int $id): array => ['user_id' => 77, 'email' => 'buyer@example.com'];

        $record = $this->builder()->build($this->group(), [], [], [], 900, 1, 1, 0.0, 0.0, [], false);

        $this->assertSame(77, $record['user_id']);
        $this->assertSame('buyer@example.com', $record['guest_email']);
    }

    public function testPersistGroupOneUpdatesOriginalBooking(): void
    {
        $record = ['hotel_id' => 'H1'];
        $this->repo->expects($this->once())->method('update')->with(42, $record);
        $this->repo->expects($this->never())->method('create');
        $this->repo->expects($this->never())->method('findIdByOrderAndHotelDates');

        $id = $this->builder()->persist($record, 42, 1, 900);

        $this->assertSame(42, $id);
    }

    public function testPersistDedupesByOrderHotelDates(): void
    {
        $record = ['hotel_id' => 'H1', 'check_in' => '2026-07-01', 'check_out' => '2026-07-08'];
        $this->repo->method('findIdByOrderAndHotelDates')->willReturn(7);
        $this->repo->expects($this->once())->method('update')->with(7, $record);
        $this->repo->expects($this->never())->method('create');

        // group 2 (no original booking) → dedup path
        $id = $this->builder()->persist($record, 0, 2, 900);

        $this->assertSame(7, $id);
    }

    /**
     * A booking whose rooms use two packages is submitted as two groups of
     * the same order, hotel and dates: group 2 must find its own row, not
     * group 1's (which it overwrote, losing group 1's record).
     */
    public function testPersistDedupesWithinTheGroup(): void
    {
        $record = ['hotel_id' => 'H1', 'check_in' => '2026-07-01', 'check_out' => '2026-07-08'];
        $this->repo->expects($this->once())->method('findIdByOrderAndHotelDates')
            ->with(900, 'H1', '2026-07-01', '2026-07-08', 2)
            ->willReturn(null);
        $this->repo->expects($this->never())->method('update');
        $this->repo->expects($this->once())->method('create')->willReturn(99);

        $this->assertSame(99, $this->builder()->persist($record, 42, 2, 900));
    }

    public function testPersistInsertsWhenNoExistingRow(): void
    {
        $record = ['hotel_id' => 'H1', 'check_in' => '2026-07-01', 'check_out' => '2026-07-08'];
        $this->repo->method('findIdByOrderAndHotelDates')->willReturn(null);
        $this->repo->expects($this->never())->method('update');
        $this->repo->expects($this->once())->method('create')->willReturn(99);

        // group 1 but no original booking id → falls through to insert
        $id = $this->builder()->persist($record, 0, 1, 900);

        $this->assertSame(99, $id);
    }
}
