<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Repository;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\Repository\SphinxBookingRepository;
use Tygh\Addons\SphinxHolidays\Tests\Support\BookingTableFake;
use Tygh\Addons\SphinxHolidays\Tests\Support\DbStub;

/**
 * Ownership rule behind sphinx_booking.edit_booking / update_booking (audit C1).
 *
 * Guest bookings are stored with user_id = 0 and an anonymous visitor's
 * user_id is 0 too, so `user_id = 0` must never grant ownership, nor an empty
 * session id. The queries run against fixture rows, so these fail if either
 * ever matches a stranger's booking.
 */
#[CoversClass(SphinxBookingRepository::class)]
final class SphinxBookingOwnershipTest extends TestCase
{
    private SphinxBookingRepository $repo;

    protected function setUp(): void
    {
        DbStub::reset();
        $this->repo = new SphinxBookingRepository();
    }

    protected function tearDown(): void
    {
        DbStub::reset();
    }

    /**
     * Row ids: 1 stranger's guest booking, 2 user 42's booking, 3 session-less
     * guest booking, 4 our guest booking, 5 ours but already ordered.
     */
    private function useBookingTable(): void
    {
        $row = static fn (int $id, int $user, string $session, int $order = 0): array => [
            'booking_id' => $id,
            'user_id' => $user,
            'session_id' => $session,
            'order_id' => $order,
        ];
        $table = new BookingTableFake([
            $row(1, 0, 'victim-sess'),
            $row(2, 42, 'sess-42'),
            $row(3, 0, ''),
            $row(4, 0, 'my-sess'),
            $row(5, 0, 'my-sess', 77),
        ]);
        DbStub::$getRow = static fn (string $query, ...$params): array => $table->select($query, array_values($params))[0] ?? [];
    }

    /**
     * @return array<string, array{0: int, 1: int, 2: string, 3: bool}>
     */
    public static function ownershipCases(): array
    {
        return [
            'guest with a foreign session cannot reach a guest booking' => [1, 0, 'attacker-sess', false],
            'guest with an empty session cannot reach a guest booking' => [1, 0, '', false],
            'empty session never matches a session-less guest booking' => [3, 0, '', false],
            'guest with its own session reaches its booking' => [4, 0, 'my-sess', true],
            'guest cannot reach a logged-in user booking' => [2, 0, 'attacker-sess', false],
            'logged-in user reaches own booking from any session' => [2, 42, 'other-sess', true],
            'logged-in user reaches own booking without a session' => [2, 42, '', true],
            'logged-in user cannot reach another user booking' => [2, 99, 'sess-99', false],
            'logged-in user cannot reach a stranger guest booking' => [1, 42, 'sess-42', false],
            'logged-in user reaches the guest booking of its own session' => [4, 42, 'my-sess', true],
            'own booking attached to an order is refused' => [5, 0, 'my-sess', false],
        ];
    }

    #[DataProvider('ownershipCases')]
    public function testFindByIdWithOwnershipMatchesOnlyRealOwners(int $bookingId, int $userId, string $sessionId, bool $owned): void
    {
        $this->useBookingTable();

        $row = $this->repo->findByIdWithOwnership($bookingId, $userId, $sessionId);

        if ($owned) {
            self::assertNotNull($row);
            self::assertSame($bookingId, $row['booking_id']);
        } else {
            self::assertNull($row);
        }
    }

    public function testNoQueryRunsWithoutAnOwnershipContext(): void
    {
        $called = false;
        DbStub::$getRow = static function () use (&$called): array {
            $called = true;
            return ['booking_id' => 3];
        };

        self::assertNull($this->repo->findByIdWithOwnership(3, 0, ''));
        self::assertFalse($called, 'must not query without an ownership context');
    }

    public function testGuestQueryNeverBindsUserIdZero(): void
    {
        $captured = [];
        DbStub::$getRow = static function (string $query, ...$params) use (&$captured): array {
            $captured = [$query, $params];
            return [];
        };

        $this->repo->findByIdWithOwnership(1, 0, 'attacker-sess');

        self::assertSame(
            'SELECT * FROM ?:sphinx_bookings WHERE booking_id = ?i AND order_id = 0 AND session_id = ?s',
            $captured[0],
        );
        self::assertSame([1, 'attacker-sess'], $captured[1]);
    }

    public function testLinkToUserBySessionRefusesAnEmptySessionOrAnonymousUser(): void
    {
        $called = false;
        DbStub::$query = static function () use (&$called): int {
            $called = true;
            return 5;
        };

        self::assertSame(0, $this->repo->linkToUserBySession(42, ''));
        self::assertSame(0, $this->repo->linkToUserBySession(0, 'sess-abc'));
        self::assertFalse($called, 'an empty session must not claim every session-less guest booking');
    }
}
