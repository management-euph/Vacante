<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Exceptions\NovotonException;
use Tygh\Addons\NovotonHolidays\Services\ApiBookingRequestBuilder;

/**
 * Characterization coverage for ApiBookingRequestBuilder — the reservation API
 * payload construction extracted from BookingSubmissionService. Pins the holder
 * resolution (first guest, else booking holder, else "Guest"), the per-group
 * order-number suffix, and the single-room shortcut that pins room_id/board_id.
 */
#[CoversClass(ApiBookingRequestBuilder::class)]
class ApiBookingRequestBuilderTest extends TestCase
{
    private ApiBookingRequestBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new ApiBookingRequestBuilder();
    }

    /** @return array<string, mixed> */
    private function group(): array
    {
        return [
            'package_name' => 'Summer',
            'check_in' => '2026-07-01',
            'check_out' => '2026-07-08',
            'rooms' => [['room_id' => 'DBL', 'board_id' => 'AI']],
        ];
    }

    public function testSingleGroupSingleRoomPayload(): void
    {
        $result = $this->builder->build(
            $this->group(),
            [['name' => 'John Doe']],
            [['Room' => 'DBL']],
            ['hotel_id' => 'H1', 'holder_name' => 'Holder', 'room_id' => 'RX', 'board_id' => 'BX'],
            555,
            1,
            1,
            'note',
        );

        $this->assertSame('H1', $result['hotel_id']);
        $this->assertSame('Summer', $result['package_name']);
        $this->assertSame('John Doe', $result['holder']); // first guest wins
        $this->assertSame('555', $result['order_num']);   // no suffix for a single group
        $this->assertSame('note', $result['comment']);
        $this->assertSame('DBL', $result['room_id']);     // single-room shortcut
        $this->assertSame('AI', $result['board_id']);
    }

    public function testHolderFallsBackToBookingHolder(): void
    {
        $result = $this->builder->build($this->group(), [], [], ['holder_name' => 'Booking Holder'], 1, 1, 1);

        $this->assertSame('Booking Holder', $result['holder']);
    }

    public function testHolderDefaultsToGuestWhenNothingAvailable(): void
    {
        $result = $this->builder->build($this->group(), [], [], [], 1, 1, 1);

        $this->assertSame('Guest', $result['holder']);
    }

    public function testOrderNumberSuffixedPerGroup(): void
    {
        $result = $this->builder->build($this->group(), [], [], [], 555, 2, 3);

        $this->assertSame('555-G2', $result['order_num']);
    }

    public function testMultiRoomGroupHasNoRoomShortcut(): void
    {
        $group = $this->group();
        $group['rooms'] = [['room_id' => 'DBL'], ['room_id' => 'SGL']];

        $result = $this->builder->build($group, [], [], [], 1, 1, 1);

        $this->assertArrayNotHasKey('room_id', $result);
        $this->assertArrayNotHasKey('board_id', $result);
    }

    /**
     * The guest's notes from checkout are the rooms' remark for the hotel:
     * plain text on one line, without the diacritics windows-1251 cannot
     * carry. The comment keeps them as typed.
     */
    public function testTheGuestsNotesAreTheRemark(): void
    {
        $notes = "Vă rugăm camere <b>alăturate</b>,\n  sosire târzie. Mulțumim!";

        $result = $this->builder->build($this->group(), [], [], ['hotel_id' => 'H1'], 1, 1, 1, $notes);

        $this->assertSame('Va rugam camere alaturate, sosire tarzie. Multumim!', $result['remark']);
        $this->assertSame($notes, $result['comment']);
        $this->assertSame('', $this->builder->build($this->group(), [], [], [], 1, 1, 1)['remark'], 'no notes, no remark');
        $this->assertSame(2000, mb_strlen(ApiBookingRequestBuilder::remark(str_repeat('ă', 2500))));
    }

    /** The request is its group's: hotel, package and stay come from the group. */
    public function testTheRequestTakesItsGroupsHotelPackageAndStay(): void
    {
        $group = ['hotel_id' => '387', 'package_name' => 'SLAVEY ****', 'check_in' => '2026-07-01', 'check_out' => '2026-07-06',
            'rooms' => [['room_id' => 'DBL 2+1'], ['room_id' => 'DBL 2+1', 'package_name' => 'slavey  ****']]];

        $result = $this->builder->build($group, [], [], ['hotel_id' => '1', 'package_name' => 'Other', 'check_in' => '2026-01-01'], 1, 1, 1);

        $this->assertSame(['387', 'SLAVEY ****', '2026-07-01', '2026-07-06'], [$result['hotel_id'], $result['package_name'], $result['check_in'], $result['check_out']]);
    }

    /**
     * Novoton takes several rooms in one request only for the same hotel,
     * package and stay: a group that mixes them is refused, nothing is sent.
     *
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function mixedRooms(): iterable
    {
        yield 'another package' => [['package_name' => 'Winter']];
        yield 'another check-in' => [['check_in' => '2026-07-02']];
        yield 'another check-out' => [['check_out' => '2026-07-09']];
        yield 'another hotel' => [['hotel_id' => 'H2']];
    }

    /** @param array<string, mixed> $other */
    #[\PHPUnit\Framework\Attributes\DataProvider('mixedRooms')]
    public function testOneRequestNeverMixesHotelsPackagesOrStays(array $other): void
    {
        $group = $this->group();
        $group['rooms'] = [['room_id' => 'DBL'], ['room_id' => 'DBL'] + $other];

        $this->expectException(NovotonException::class);
        $this->builder->build($group, [], [], ['hotel_id' => 'H1'], 1, 1, 1);
    }
}
