<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Repository;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Repository\BookingOwnershipRepository;
use Tygh\Addons\NovotonHolidays\Tests\Support\BookingTableFake;
use Tygh\Addons\NovotonHolidays\Tests\Support\DbStub;

/**
 * Characterization coverage for BookingOwnershipRepository — the ownership /
 * security boundary extracted from BookingRepository. The tests pin the exact
 * SQL and parameters each method issues, with particular attention to the
 * ownership-scoping branches and the "no context → return nothing" guard that
 * prevents cross-customer booking leakage. DB access is routed through DbStub.
 */
#[CoversClass(BookingOwnershipRepository::class)]
class BookingOwnershipRepositoryTest extends TestCase
{
    private BookingOwnershipRepository $repo;

    protected function setUp(): void
    {
        DbStub::reset();
        $this->repo = new BookingOwnershipRepository();
    }

    protected function tearDown(): void
    {
        DbStub::reset();
    }

    // ── findByProductIds ─────────────────────────────────────────────────────

    public function testFindByProductIdsReturnsEmptyForNoProducts(): void
    {
        $called = false;
        DbStub::$getArray = static function () use (&$called): array {
            $called = true;
            return [];
        };

        $this->assertSame([], $this->repo->findByProductIds([], [], 'sess', 1));
        $this->assertFalse($called, 'no query should run without product IDs');
    }

    public function testFindByProductIdsReturnsNothingWithoutOwnershipContext(): void
    {
        $called = false;
        DbStub::$getArray = static function () use (&$called): array {
            $called = true;
            return [['booking_id' => 1]];
        };

        // No user_id and no session_id — must refuse to query rather than leak.
        $result = $this->repo->findByProductIds([10, 11], ['P'], '', 0);

        $this->assertSame([], $result);
        $this->assertFalse($called, 'must not query without an ownership context');
    }

    public function testFindByProductIdsScopesToUserAndSession(): void
    {
        $rows = [['booking_id' => 5, 'product_id' => 10]];
        $captured = [];
        DbStub::$getArray = static function (string $query, ...$params) use ($rows, &$captured): array {
            $captured = [$query, $params];
            return $rows;
        };

        $result = $this->repo->findByProductIds([10, 11], ['pending', 'confirmed'], 'sess-1', 42);

        $this->assertSame($rows, $result);
        $this->assertStringContainsString('WHERE product_id IN (?n) AND status IN (?a)', $captured[0]);
        $this->assertStringContainsString('AND (session_id = ?s OR user_id = ?i) ORDER BY booking_id DESC', $captured[0]);
        $this->assertSame([[10, 11], ['pending', 'confirmed'], 'sess-1', 42], $captured[1]);
    }

    public function testFindByProductIdsScopesToUserOnly(): void
    {
        $captured = [];
        DbStub::$getArray = static function (string $query, ...$params) use (&$captured): array {
            $captured = [$query, $params];
            return [];
        };

        $this->repo->findByProductIds([7], ['pending'], '', 42);

        $this->assertStringContainsString('AND user_id = ?i ORDER BY booking_id DESC', $captured[0]);
        $this->assertStringNotContainsString('session_id = ?s', $captured[0]);
        $this->assertSame([[7], ['pending'], 42], $captured[1]);
    }

    public function testFindByProductIdsScopesToSessionOnly(): void
    {
        $captured = [];
        DbStub::$getArray = static function (string $query, ...$params) use (&$captured): array {
            $captured = [$query, $params];
            return [];
        };

        $this->repo->findByProductIds([7], ['pending'], 'sess-9', 0);

        $this->assertStringContainsString('AND session_id = ?s ORDER BY booking_id DESC', $captured[0]);
        $this->assertStringNotContainsString('user_id = ?i', $captured[0]);
        $this->assertSame([[7], ['pending'], 'sess-9'], $captured[1]);
    }

    // ── findByIdWithOwnership ────────────────────────────────────────────────

    public function testFindByIdWithOwnershipReturnsOwnedRow(): void
    {
        $row = ['booking_id' => 3, 'user_id' => 42];
        $captured = [];
        DbStub::$getRow = static function (string $query, ...$params) use ($row, &$captured): array {
            $captured = [$query, $params];
            return $row;
        };

        $result = $this->repo->findByIdWithOwnership(3, 42, 'sess-1');

        $this->assertSame($row, $result);
        $this->assertStringContainsString(
            'WHERE booking_id = ?i AND order_id = 0 AND status = ?s AND (user_id = ?i OR session_id = ?s)',
            $captured[0],
        );
        $this->assertSame([3, 'pending', 42, 'sess-1'], $captured[1]);
    }

    public function testFindByIdWithOwnershipReturnsNullWhenNotOwned(): void
    {
        DbStub::$getRow = static fn (string $query, ...$params): array => [];

        $this->assertNull($this->repo->findByIdWithOwnership(3, 99, 'someone-else'));
    }

    // ── checkOwnership ───────────────────────────────────────────────────────

    public function testCheckOwnershipReturnsIdWhenOwned(): void
    {
        $captured = [];
        DbStub::$getField = static function (string $query, ...$params) use (&$captured): string {
            $captured = [$query, $params];
            return '3';
        };

        $this->assertSame(3, $this->repo->checkOwnership(3, 42, 'sess-1'));
        $this->assertStringContainsString(
            'SELECT booking_id FROM ?:novoton_bookings WHERE booking_id = ?i AND order_id = 0 AND status = ?s AND (user_id = ?i OR session_id = ?s)',
            $captured[0],
        );
        $this->assertSame([3, 'pending', 42, 'sess-1'], $captured[1]);
    }

    public function testCheckOwnershipReturnsNullWhenNotOwned(): void
    {
        DbStub::$getField = static fn (string $query, ...$params) => null;

        $this->assertNull($this->repo->checkOwnership(3, 99, 'someone-else'));
    }

    // ── Ownership semantics against a booking table (audit C1) ──────────────
    //
    // Guest bookings are stored with user_id = 0 and an anonymous visitor's
    // user_id is 0 too. These run the real query against fixture rows, so
    // they fail if user_id 0 or an empty session ever grants ownership.

    /**
     * Row ids: 1 stranger's guest booking, 2 user 42's booking, 3 session-less
     * guest booking, 4 our guest booking, 5 ours but already ordered, 6 ours
     * but no longer pending, 7 user 42's booking in our session.
     *
     * @return list<array<string, int|string>>
     */
    private static function bookingRows(): array
    {
        $row = static fn (int $id, int $user, string $session, int $order = 0, string $status = 'pending'): array => [
            'booking_id' => $id,
            'user_id' => $user,
            'session_id' => $session,
            'order_id' => $order,
            'status' => $status,
        ];

        return [
            $row(1, 0, 'victim-sess'),
            $row(2, 42, 'sess-42'),
            $row(3, 0, ''),
            $row(4, 0, 'my-sess'),
            $row(5, 0, 'my-sess', 77),
            $row(6, 0, 'my-sess', 0, 'confirmed'),
            $row(7, 42, 'my-sess'),
        ];
    }

    private function useBookingTable(): void
    {
        $table = new BookingTableFake(self::bookingRows());
        DbStub::$getRow = static fn (string $query, ...$params): array => $table->select($query, array_values($params))[0] ?? [];
        DbStub::$getField = static function (string $query, ...$params) use ($table) {
            $hit = $table->select($query, array_values($params))[0] ?? null;
            return $hit === null ? null : (string) $hit['booking_id'];
        };
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
            'whitespace session is treated as empty' => [3, 0, ' ', false],
            'guest with its own session reaches its booking' => [4, 0, 'my-sess', true],
            'guest cannot reach a logged-in user booking' => [2, 0, 'attacker-sess', false],
            'logged-in user reaches own booking from any session' => [2, 42, 'other-sess', true],
            'logged-in user reaches own booking without a session' => [2, 42, '', true],
            'logged-in user cannot reach another user booking' => [2, 99, 'sess-99', false],
            'logged-in user cannot reach a stranger guest booking' => [1, 42, 'sess-42', false],
            'logged-in user reaches the guest booking of its own session' => [4, 42, 'my-sess', true],
            'own session but another user booking matches by session' => [7, 0, 'my-sess', true],
            'own booking attached to an order is refused' => [5, 0, 'my-sess', false],
            'own booking no longer pending is refused' => [6, 0, 'my-sess', false],
        ];
    }

    #[DataProvider('ownershipCases')]
    public function testFindByIdWithOwnershipMatchesOnlyRealOwners(int $bookingId, int $userId, string $sessionId, bool $owned): void
    {
        $this->useBookingTable();

        $row = $this->repo->findByIdWithOwnership($bookingId, $userId, $sessionId);

        if ($owned) {
            $this->assertNotNull($row);
            $this->assertSame($bookingId, $row['booking_id']);
        } else {
            $this->assertNull($row);
        }
    }

    #[DataProvider('ownershipCases')]
    public function testCheckOwnershipMatchesOnlyRealOwners(int $bookingId, int $userId, string $sessionId, bool $owned): void
    {
        $this->useBookingTable();

        $this->assertSame($owned ? $bookingId : null, $this->repo->checkOwnership($bookingId, $userId, $sessionId));
    }

    public function testNoQueryRunsWithoutAnOwnershipContext(): void
    {
        $called = false;
        DbStub::$getRow = static function () use (&$called): array {
            $called = true;
            return ['booking_id' => 3];
        };
        DbStub::$getField = static function () use (&$called): string {
            $called = true;
            return '3';
        };

        // Anonymous visitor (user_id 0) with no session: nothing can be owned.
        $this->assertNull($this->repo->findByIdWithOwnership(3, 0, ''));
        $this->assertNull($this->repo->checkOwnership(3, 0, ''));
        $this->assertFalse($called, 'must not query without an ownership context');
    }

    public function testGuestQueryNeverBindsUserIdZero(): void
    {
        $captured = [];
        DbStub::$getRow = static function (string $query, ...$params) use (&$captured): array {
            $captured = [$query, $params];
            return [];
        };

        $this->repo->findByIdWithOwnership(1, 0, 'attacker-sess');

        $this->assertStringEndsWith('AND session_id = ?s', $captured[0]);
        $this->assertStringNotContainsString('user_id', $captured[0]);
        $this->assertSame([1, 'pending', 'attacker-sess'], $captured[1]);
    }
}
