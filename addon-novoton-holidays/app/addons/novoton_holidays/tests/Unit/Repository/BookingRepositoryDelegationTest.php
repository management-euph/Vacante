<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Repository;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Repository\BookingRepository;
use Tygh\Addons\NovotonHolidays\Tests\Support\DbStub;
use Tygh\Addons\NovotonHolidays\Tests\Support\RecordingBookingSyncRepository;

/**
 * Verifies that BookingRepository keeps orchestrating the booking writes — the
 * surrounding transaction and the novoton_bookings statements — while delegating
 * the travel_bookings half to its injected BookingSyncRepository collaborator.
 *
 * This is the safety net for the extraction: the SQL moved out, but the
 * transaction boundaries, delegation, and rollback behaviour must not change.
 */
#[CoversClass(BookingRepository::class)]
class BookingRepositoryDelegationTest extends TestCase
{
    private RecordingBookingSyncRepository $sync;
    private BookingRepository $repo;
    /** @var list<string> */
    private array $queries = [];

    protected function setUp(): void
    {
        DbStub::reset();
        $this->queries = [];
        $this->sync = new RecordingBookingSyncRepository();
        $this->repo = new BookingRepository($this->sync);
    }

    protected function tearDown(): void
    {
        DbStub::reset();
    }

    /**
     * Route db_query: record every statement, return $insertId for the
     * novoton_bookings INSERT and $affected for everything else.
     */
    private function routeQueries(int $insertId = 0, int $affected = 0): void
    {
        DbStub::$query = function (string $query, ...$params) use ($insertId, $affected): int {
            $this->queries[] = $query;
            if (str_contains($query, 'INSERT INTO ?:novoton_bookings')) {
                return $insertId;
            }
            return $affected;
        };
    }

    private function assertRan(string $needle): void
    {
        $hit = array_filter($this->queries, static fn (string $q): bool => str_contains($q, $needle));
        $this->assertNotEmpty($hit, "expected a query containing: {$needle}");
    }

    private function assertNotRan(string $needle): void
    {
        $hit = array_filter($this->queries, static fn (string $q): bool => str_contains($q, $needle));
        $this->assertEmpty($hit, "did not expect a query containing: {$needle}");
    }

    public function testCreateDelegatesUpsertWithinTransaction(): void
    {
        $this->routeQueries(insertId: 77);

        $id = $this->repo->create(['hotel_id' => 'H1', 'status' => 'pending']);

        $this->assertSame(77, $id);
        $this->assertSame('START TRANSACTION', $this->queries[0]);
        $this->assertSame('COMMIT', $this->queries[array_key_last($this->queries)]);
        $this->assertRan('INSERT INTO ?:novoton_bookings');
        $this->assertSame(
            [['upsertFromBooking', 77, ['hotel_id' => 'H1', 'status' => 'pending']]],
            $this->sync->calls,
        );
    }

    public function testCreateRollsBackAndRethrowsWhenSyncFails(): void
    {
        $this->routeQueries(insertId: 77);
        $this->sync->throwOnUpsert = true;

        try {
            $this->repo->create(['hotel_id' => 'H1']);
            $this->fail('expected the sync failure to propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('sync failed', $e->getMessage());
        }

        $this->assertRan('ROLLBACK');
        $this->assertNotRan('COMMIT');
    }

    public function testUpdateDelegatesApplyUpdateWithinTransaction(): void
    {
        $this->routeQueries(affected: 1);

        $ok = $this->repo->update(42, ['status' => 'cancelled']);

        $this->assertTrue($ok);
        $this->assertSame('START TRANSACTION', $this->queries[0]);
        $this->assertSame('COMMIT', $this->queries[array_key_last($this->queries)]);
        $this->assertRan('UPDATE ?:novoton_bookings');
        $this->assertSame(
            [['applyBookingUpdate', 42, ['status' => 'cancelled']]],
            $this->sync->calls,
        );
    }

    public function testDeleteDelegatesWithinTransaction(): void
    {
        $this->routeQueries(affected: 1);

        $ok = $this->repo->delete(55);

        $this->assertTrue($ok);
        $this->assertSame('START TRANSACTION', $this->queries[0]);
        $this->assertSame('COMMIT', $this->queries[array_key_last($this->queries)]);
        $this->assertRan('DELETE FROM ?:novoton_bookings');
        $this->assertSame([['deleteByBookingId', 55]], $this->sync->calls);
    }

    public function testDeleteOrphansDelegatesBeforeDeletingBookings(): void
    {
        $this->routeQueries(affected: 3);

        $removed = $this->repo->deleteOrphans(48);

        $this->assertSame(3, $removed);
        $this->assertRan('DELETE FROM ?:novoton_bookings');
        $this->assertSame([['deleteOrphansOlderThan', 48]], $this->sync->calls);
    }

    public function testDeleteByProductIdDelegatesFetchedBookingIds(): void
    {
        DbStub::$getFields = static fn (string $q, ...$p): array => ['10', '11'];
        $this->routeQueries(affected: 2);

        $removed = $this->repo->deleteByProductId(900);

        $this->assertSame(2, $removed);
        $this->assertSame([['deleteByBookingIds', ['10', '11']]], $this->sync->calls);
    }

    public function testLinkToUserBySessionDelegatesAssignUser(): void
    {
        DbStub::$getFields = static fn (string $q, ...$p): array => ['10', '11'];
        $this->routeQueries(affected: 2);

        $linked = $this->repo->linkToUserBySession(42, 'sess-1');

        $this->assertSame(2, $linked);
        $this->assertSame([['assignUser', 42, ['10', '11']]], $this->sync->calls);
    }

    /**
     * C1: user_id 0 is every guest, and an empty session id is every
     * session-less guest booking - neither may claim anything.
     */
    public function testLinkToUserBySessionClaimsNothingWithoutAnAccountOrASession(): void
    {
        DbStub::$getFields = static fn (string $q, ...$p): array => ['10', '11'];
        $this->routeQueries(affected: 2);

        $this->assertSame(0, $this->repo->linkToUserBySession(0, 'sess-1'));
        $this->assertSame(0, $this->repo->linkToUserBySession(-1, 'sess-1'));
        $this->assertSame(0, $this->repo->linkToUserBySession(42, ''));
        $this->assertSame(0, $this->repo->linkToUserBySession(42, "  \t"));

        $this->assertSame([], $this->queries, 'no UPDATE is run');
        $this->assertSame([], $this->sync->calls);
    }

    /**
     * Every package group of one booking shares order, hotel and dates: the
     * submission's dedup must look for its own group's row only.
     */
    public function testOrderHotelDatesLookupIsScopedToTheGroup(): void
    {
        $seen = [];
        DbStub::$getField = static function (string $q, mixed ...$p) use (&$seen): int {
            $seen[] = [$q, $p];

            return 7;
        };

        $this->assertSame(7, $this->repo->findIdByOrderAndHotelDates(900, 'H1', '2026-07-01', '2026-07-08', 2));
        $this->assertSame(7, $this->repo->findIdByOrderAndHotelDates(900, 'H1', '2026-07-01', '2026-07-08'));

        $this->assertStringContainsString('?p', $seen[0][0]);
        $this->assertSame([900, 'H1', '2026-07-01', '2026-07-08', 'AND room_number = 2'], $seen[0][1]);
        $this->assertSame([900, 'H1', '2026-07-01', '2026-07-08', ''], $seen[1][1], 'no group: any row of the stay');
    }

    public function testLinkToUserBySessionSkipsDelegationWhenNothingClaimed(): void
    {
        $this->routeQueries(affected: 0);

        $linked = $this->repo->linkToUserBySession(42, 'sess-1');

        $this->assertSame(0, $linked);
        $this->assertSame([], $this->sync->calls, 'no claim → no travel_bookings sync');
    }
}
