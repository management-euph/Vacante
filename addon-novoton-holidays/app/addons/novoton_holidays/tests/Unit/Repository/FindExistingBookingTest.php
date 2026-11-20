<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Repository;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Repository\BookingRepository;
use Tygh\Addons\NovotonHolidays\Tests\Support\DbStub;
use Tygh\Addons\NovotonHolidays\Tests\Support\RecordingBookingSyncRepository;

/**
 * Add to cart reused a pending booking with the same hotel, dates and holder
 * name, whoever had made it: a second visitor typing the same name rewrote
 * the first visitor's travellers and prices. Only the session that made the
 * booking finds it now.
 */
final class FindExistingBookingTest extends TestCase
{
    /** @var list<array{0: string, 1: list<mixed>}> */
    private array $queries = [];

    protected function tearDown(): void
    {
        DbStub::reset();
    }

    private function repo(): BookingRepository
    {
        DbStub::reset();
        $this->queries = [];
        DbStub::$getRow = function (string $query, mixed ...$params): array {
            $this->queries[] = [$query, $params];

            return ['booking_id' => 31];
        };

        return new BookingRepository(new RecordingBookingSyncRepository());
    }

    public function testOnlyTheVisitorsOwnSessionIsSearched(): void
    {
        $found = $this->repo()->findExisting('4535', '2027-07-10', '2027-07-17', 'Ion Popescu', 'sess-a', 1);

        self::assertSame(31, $found['booking_id'] ?? null);
        self::assertCount(1, $this->queries);
        self::assertStringContainsString('AND session_id = ?s', $this->queries[0][0]);
        self::assertStringContainsString('WHERE order_id = 0', $this->queries[0][0]);
        self::assertSame(['sess-a', '4535', '2027-07-10', '2027-07-17', 'Ion Popescu', 1], $this->queries[0][1]);
    }

    public function testWithoutASessionNothingIsReused(): void
    {
        self::assertNull($this->repo()->findExisting('4535', '2027-07-10', '2027-07-17', 'Ion Popescu', ''));
        self::assertSame([], $this->queries);
    }

    public function testAReusedBookingHasEveryColumnRewritten(): void
    {
        $cart = (string) file_get_contents(dirname(__DIR__, 3) . '/controllers/frontend/novoton_booking/add_to_cart.php');

        self::assertStringContainsString('$bookingRepo->findExisting($bdHotelId, $check_in, $check_out, $holder_name, $session_id, 1);', $cart);
        // One record for both branches: the package, board, nights and
        // product of the new request replace the old ones.
        self::assertSame(1, substr_count($cart, "'package_name' => \$package_name,\n        'room_id' => \$room_id_column,"));
        self::assertStringContainsString("unset(\$booking_record['order_id'], \$booking_record['session_id']);", $cart);
        self::assertStringContainsString('$bookingRepo->update($booking_id, $booking_record);', $cart);
    }
}
