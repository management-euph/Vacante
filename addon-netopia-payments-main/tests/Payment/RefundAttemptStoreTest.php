<?php

declare(strict_types=1);

namespace Netopia\CsCart\Tests\Payment;

use Netopia\CsCart\Payment\ClaimResult;
use Netopia\CsCart\Payment\RefundAttemptStore;
use Netopia\CsCart\Tests\Support\FakeClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Drives RefundAttemptStore through an in-memory fake that simulates
 * MySQL's `INSERT ... ON DUPLICATE KEY UPDATE` and `affected_rows`
 * semantics on a UNIQUE PRIMARY KEY. Independent of MySQL itself.
 */
#[CoversClass(RefundAttemptStore::class)]
#[CoversClass(ClaimResult::class)]
final class RefundAttemptStoreTest extends TestCase
{
    public function testFreshClaimOnEmptyTableIsGranted(): void
    {
        $store = $this->buildStore($db, $clock);

        $result = $store->claim('refund-1', 42, 100.0);

        self::assertSame(ClaimResult::KIND_GRANTED, $result->kind);
        self::assertCount(1, $db);
        self::assertSame(RefundAttemptStore::STATUS_PENDING, $db['refund-1']['status']);
    }

    public function testSecondClaimWithinTtlReturnsInFlight(): void
    {
        $store = $this->buildStore($db, $clock);

        $first = $store->claim('refund-1', 42, 100.0);
        $second = $store->claim('refund-1', 42, 100.0);

        self::assertSame(ClaimResult::KIND_GRANTED, $first->kind);
        self::assertSame(ClaimResult::KIND_IN_FLIGHT, $second->kind);
        self::assertSame('refund-1', $second->existing['request_id'] ?? null);
        self::assertSame(RefundAttemptStore::STATUS_PENDING, $second->existing['status'] ?? null);
    }

    public function testSecondClaimAfterTtlReclaimsViaCompareAndSwap(): void
    {
        $store = $this->buildStore($db, $clock);

        $store->claim('refund-1', 42, 100.0);

        // Time passes — push the clock past the default TTL (120s).
        $clock->advance(200);

        $reclaim = $store->claim('refund-1', 42, 100.0);

        self::assertSame(ClaimResult::KIND_GRANTED, $reclaim->kind);
        self::assertSame(
            $clock->now()->getTimestamp(),
            $db['refund-1']['created_at'],
            'created_at must be reset to "now" so a third reclaim within the new TTL is in_flight',
        );
    }

    public function testTwoSimultaneousReclaimsOfAbandonedRowProduceExactlyOneGranted(): void
    {
        $store = $this->buildStore($db, $clock);

        $store->claim('refund-1', 42, 100.0);
        $clock->advance(200);

        // Race: simulate two reclaims with the SAME observed created_at.
        // The fake DB's compare-and-swap behaves like MySQL — only the
        // first UPDATE finds rows matching `created_at = $expected`.
        $a = $store->claim('refund-1', 42, 100.0);
        $b = $store->claim('refund-1', 42, 100.0);

        $kinds = [$a->kind, $b->kind];
        sort($kinds);
        self::assertSame(
            [ClaimResult::KIND_GRANTED, ClaimResult::KIND_IN_FLIGHT],
            $kinds,
            'exactly one reclaim wins; the loser falls back to in_flight',
        );
    }

    public function testClaimAfterMarkSucceededReturnsAlreadyCompletedWithNtpId(): void
    {
        $store = $this->buildStore($db, $clock);

        $store->claim('refund-1', 42, 100.0);
        $store->markSucceeded('refund-1', 'ntp-credit-X');

        $replay = $store->claim('refund-1', 42, 100.0);

        self::assertSame(ClaimResult::KIND_ALREADY_COMPLETED, $replay->kind);
        self::assertSame(RefundAttemptStore::STATUS_SUCCEEDED, $replay->existing['status'] ?? null);
        self::assertSame('ntp-credit-X', $replay->existing['ntp_id'] ?? null);
    }

    public function testClaimAfterMarkFailedReturnsAlreadyCompletedWithError(): void
    {
        $store = $this->buildStore($db, $clock);

        $store->claim('refund-1', 42, 100.0);
        $store->markFailed('refund-1', 'Refund window expired');

        $replay = $store->claim('refund-1', 42, 100.0);

        self::assertSame(ClaimResult::KIND_ALREADY_COMPLETED, $replay->kind);
        self::assertSame(RefundAttemptStore::STATUS_FAILED, $replay->existing['status'] ?? null);
        self::assertSame('Refund window expired', $replay->existing['error'] ?? null);
    }

    public function testMarkFailedTruncatesErrorTo500Chars(): void
    {
        $store = $this->buildStore($db, $clock);

        $store->claim('refund-1', 42, 100.0);
        $store->markFailed('refund-1', str_repeat('x', 800));

        self::assertSame(500, mb_strlen((string) $db['refund-1']['error']));
    }

    public function testClaimUsesInsertIgnoreNotOnDuplicateKeyUpdate(): void
    {
        // Locks in the SQL shape: `INSERT IGNORE` rather than
        // `INSERT … ON DUPLICATE KEY UPDATE`. The two patterns return
        // different affected_rows under MySQL's CLIENT_FOUND_ROWS flag —
        // INSERT IGNORE is unconditionally 0 on PK collision, the
        // ON DUPLICATE KEY UPDATE no-op can return 1 if the flag is set.
        // CS-Cart's default doesn't set it, but a third-party DB-config
        // addon could; INSERT IGNORE makes the dedup contract independent
        // of any connection flag.
        $capturedSql = '';
        $store = new RefundAttemptStore(
            dbQuery:  function (string $sql) use (&$capturedSql): int {
                $capturedSql = $sql;
                return 1;
            },
            dbGetRow: static fn (): array|false => false,
            clock:    new FakeClock(1_777_226_000),
        );

        $store->claim('refund-1', 42, 100.0);

        self::assertStringContainsString('INSERT IGNORE INTO', $capturedSql);
        self::assertStringNotContainsString('ON DUPLICATE KEY UPDATE', $capturedSql);
    }

    public function testDistinctRequestIdsBothClaimable(): void
    {
        $store = $this->buildStore($db, $clock);

        $a = $store->claim('refund-1', 42, 100.0);
        $b = $store->claim('refund-2', 42, 50.0);

        self::assertSame(ClaimResult::KIND_GRANTED, $a->kind);
        self::assertSame(ClaimResult::KIND_GRANTED, $b->kind);
        self::assertCount(2, $db);
    }

    /**
     * @param array<string, array<string, mixed>> $db
     * @param-out array<string, array<string, mixed>> $db
     * @param-out FakeClock $clock
     */
    private function buildStore(?array &$db = null, ?FakeClock &$clock = null): RefundAttemptStore
    {
        $db ??= [];
        $clock ??= new FakeClock(1_777_226_000);

        // The fake mirrors CS-Cart's `db_query` contract for INSERT/UPDATE
        // on a no-AUTO_INCREMENT table: return the affected-rows count
        // (1 = wrote a row, 0 = no-op). RefundAttemptStore reads that
        // return value as its sole synchronization signal.
        $dbQuery = function (string $sql, mixed ...$params) use (&$db): int {
            if (str_contains($sql, 'INSERT IGNORE INTO ?:netopia_refund_attempts')) {
                [$requestId, $orderId, $amount, $status, $createdAt] = $params;
                if (isset($db[$requestId])) {
                    // INSERT IGNORE on PK collision — no row touched, 0 affected
                    // regardless of CLIENT_FOUND_ROWS or any other flag.
                    return 0;
                }
                $db[$requestId] = [
                    'request_id'   => $requestId,
                    'order_id'     => $orderId,
                    'amount'       => $amount,
                    'ntp_id'       => null,
                    'status'       => $status,
                    'error'        => null,
                    'created_at'   => $createdAt,
                    'completed_at' => null,
                ];
                return 1;
            }
            if (str_contains($sql, 'SET created_at = ?i')) {
                [$newCreatedAt, $requestId, $expectedStatus, $expectedCreatedAt] = $params;
                $row = $db[$requestId] ?? null;
                if (
                    $row !== null
                    && $row['status'] === $expectedStatus
                    && $row['created_at'] === $expectedCreatedAt
                ) {
                    $db[$requestId]['created_at'] = $newCreatedAt;
                    return 1;
                }
                return 0;
            }
            if (str_contains($sql, "SET status = ?s, ntp_id = ?s")) {
                [$status, $ntpId, $completedAt, $requestId] = $params;
                if (isset($db[$requestId])) {
                    $db[$requestId]['status']       = $status;
                    $db[$requestId]['ntp_id']       = $ntpId;
                    $db[$requestId]['completed_at'] = $completedAt;
                    return 1;
                }
            }
            if (str_contains($sql, "SET status = ?s, error = ?s")) {
                [$status, $error, $completedAt, $requestId] = $params;
                if (isset($db[$requestId])) {
                    $db[$requestId]['status']       = $status;
                    $db[$requestId]['error']        = $error;
                    $db[$requestId]['completed_at'] = $completedAt;
                    return 1;
                }
            }
            return 0;
        };

        $dbGetRow = function (string $sql, mixed ...$params) use (&$db): array|false {
            if (str_contains($sql, 'SELECT * FROM ?:netopia_refund_attempts')) {
                $requestId = $params[0];
                return $db[$requestId] ?? false;
            }
            return false;
        };

        return new RefundAttemptStore(
            dbQuery:  $dbQuery,
            dbGetRow: $dbGetRow,
            clock:    $clock,
        );
    }
}
