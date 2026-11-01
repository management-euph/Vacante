<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Services\RoomAvailability;

/**
 * The split booking: which rooms the hotel confirms at once and which are on
 * request, decided from the hotel's quota when the booking goes into the
 * cart, never by the page.
 */
#[CoversClass(RoomAvailability::class)]
final class RoomAvailabilityTest extends TestCase
{
    public function testRoomsBeyondTheRoomsLeftAreOnRequest(): void
    {
        $flags = RoomAvailability::flags(
            ['DBL 2+0', 'FAM 2+2', 'DBL 2+0', 'DBL 2+0'],
            static fn (): array => ['DBL 2+0' => '2', 'FAM 2+2' => 'RQ'],
        );

        self::assertSame([false, true, false, true], $flags, 'two doubles left: the third is on request; the family room is on request');
    }

    public function testTheQuotaIsReadAsTheSearchReadsIt(): void
    {
        $flags = RoomAvailability::flags(
            ['DBL 2+1', 'STUDIO 2+1', 'SGL', 'APP 4+0', 'TRP 3+0'],
            // Novoton's own spelling of a code, "0" and "" (on request), a type it does not list (bookable)
            static fn (): array => ['DBL 2%2b1' => '1', 'STUDIO 2+1' => '0', 'SGL' => '', 'TRP 3+0' => 'REQUEST'],
        );

        self::assertSame([false, true, true, false, true], $flags);
    }

    public function testOneRoomIsNeverSplitAndAsksNothing(): void
    {
        $asked = false;
        $flags = RoomAvailability::flags(['DBL 2+0'], static function () use (&$asked): array {
            $asked = true;

            return ['DBL 2+0' => '0'];
        });

        self::assertFalse($asked);
        self::assertNull($flags);
    }

    public function testAQuotaThatCannotBeReadLeavesOneReservation(): void
    {
        $flags = RoomAvailability::flags(['DBL 2+0', 'DBL 2+0'], static function (): array {
            throw new \RuntimeException('hotel_quota timed out');
        });

        self::assertNull($flags);
    }

    public function testABookedRoomIsOnRequestOnlyWhenMarkedSo(): void
    {
        self::assertTrue(RoomAvailability::onRequest(['room_id' => 'DBL', 'on_request' => true]));
        self::assertFalse(RoomAvailability::onRequest(['room_id' => 'DBL', 'on_request' => false]));
        self::assertFalse(RoomAvailability::onRequest(['room_id' => 'DBL']), 'unmarked: confirmed at once, one reservation as before');
        self::assertFalse(RoomAvailability::onRequest(['room_id' => 'DBL', 'on_request' => '1']), 'only the server\'s own mark');
    }
}
