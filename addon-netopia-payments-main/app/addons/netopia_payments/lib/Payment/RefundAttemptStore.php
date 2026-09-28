<?php

declare(strict_types=1);

namespace Netopia\CsCart\Payment;

use Closure;
use Netopia\CsCart\Support\Arr;
use Netopia\CsCart\Support\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * DB-backed claim store for refund attempts. Uses a UNIQUE PRIMARY KEY on
 * `request_id` as the synchronization primitive: two admin sessions racing
 * for the same `(order, amount, sequence)` intent both call `claim()`, the
 * first INSERT wins, the second sees the existing row and short-circuits.
 *
 * **Atomicity contract: this class NEVER does a SELECT-before-INSERT.**
 * Two admins beating each other to the millisecond would both pass that
 * check. The DB is the only synchronization primitive — PHP only reads
 * state to disambiguate AFTER an INSERT/UPDATE has already settled the
 * race.
 */
final class RefundAttemptStore
{
    public const string STATUS_PENDING = 'pending';
    public const string STATUS_SUCCEEDED = 'succeeded';
    public const string STATUS_FAILED = 'failed';

    public const int DEFAULT_PENDING_TTL_SECONDS = 120;

    /**
     * @param Closure(string, mixed...): mixed                        $dbQuery
     *      For our INSERT/UPDATE statements against a table with no
     *      AUTO_INCREMENT column, CS-Cart's `db_query` returns the
     *      affected-rows count directly (its INSERT branch falls through
     *      to `mysqli_affected_rows` when `mysqli_insert_id` is 0).
     *      That's the synchronization signal `claim()` relies on.
     * @param Closure(string, mixed...): (array<string, mixed>|false) $dbGetRow
     */
    public function __construct(
        private readonly Closure $dbQuery,
        private readonly Closure $dbGetRow,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Atomically claim a refund attempt. Returns one of:
     *   - {@see ClaimResult::granted()}            caller proceeds with the API call.
     *   - {@see ClaimResult::alreadyCompleted()}   prior attempt already finished
     *                                              (succeeded or failed) — caller
     *                                              replays the cached outcome.
     *   - {@see ClaimResult::inFlight()}           prior attempt is pending and within
     *                                              TTL — caller surfaces a "wait" notice.
     *
     * Implementation:
     *  1. `INSERT IGNORE` — atomic insert-or-skip. MySQL's affected_rows
     *     is exactly 1 if we inserted a fresh row, 0 on PK collision
     *     regardless of any connection flag (including `CLIENT_FOUND_ROWS`,
     *     which under `INSERT … ON DUPLICATE KEY UPDATE` would conflate
     *     "matched-no-change" with "inserted"). Because our PK is VARCHAR
     *     (no AUTO_INCREMENT), CS-Cart's `db_query` returns that
     *     affected-rows count as its own return value.
     *  2. On 0 affected, SELECT the existing row to disambiguate
     *     succeeded / failed / pending / abandoned-past-TTL.
     *  3. Abandoned-pending: try a compare-and-swap UPDATE on
     *     `(request_id, status, created_at)` to reset created_at to now.
     *     Only one of two simultaneous reclaim attempts sees affected = 1;
     *     the other gets 0 and falls back to in_flight.
     */
    public function claim(
        string $requestId,
        int $orderId,
        float $amount,
        int $pendingTtlSeconds = self::DEFAULT_PENDING_TTL_SECONDS,
    ): ClaimResult {
        $now = $this->clock->now()->getTimestamp();

        $insertResult = ($this->dbQuery)(
            'INSERT IGNORE INTO ?:netopia_refund_attempts '
            . '(request_id, order_id, amount, status, created_at) '
            . 'VALUES (?s, ?i, ?d, ?s, ?i)',
            $requestId,
            $orderId,
            $amount,
            self::STATUS_PENDING,
            $now,
        );

        if (self::asAffectedRows($insertResult) === 1) {
            $this->logger->info('NETOPIA refund attempt claimed', [
                'request_id' => $requestId,
                'order_id' => $orderId,
            ]);
            return ClaimResult::granted();
        }

        $existing = ($this->dbGetRow)(
            'SELECT * FROM ?:netopia_refund_attempts WHERE request_id = ?s',
            $requestId,
        );
        if (!is_array($existing)) {
            // Should be unreachable — the ON DUPLICATE KEY branch implies
            // the row is there. Treat as in_flight to be safe.
            $this->logger->warning('NETOPIA refund claim: existing row not found after duplicate-key INSERT', [
                'request_id' => $requestId,
            ]);
            return ClaimResult::inFlight([]);
        }

        if (Arr::string($existing, 'status') !== self::STATUS_PENDING) {
            return ClaimResult::alreadyCompleted($existing);
        }

        $createdAt = Arr::int($existing, 'created_at');
        if ($now - $createdAt < $pendingTtlSeconds) {
            return ClaimResult::inFlight($existing);
        }

        // Compare-and-swap: take ownership of the abandoned row only if it's
        // still on the same created_at we just read. A concurrent reclaim
        // that beats us mutates created_at out from under our WHERE.
        $casResult = ($this->dbQuery)(
            'UPDATE ?:netopia_refund_attempts '
            . 'SET created_at = ?i '
            . 'WHERE request_id = ?s AND status = ?s AND created_at = ?i',
            $now,
            $requestId,
            self::STATUS_PENDING,
            $createdAt,
        );

        if (self::asAffectedRows($casResult) === 1) {
            $this->logger->info('NETOPIA refund attempt reclaimed (prior was abandoned past TTL)', [
                'request_id' => $requestId,
                'abandoned_age_seconds' => $now - $createdAt,
            ]);
            return ClaimResult::granted();
        }

        return ClaimResult::inFlight($existing);
    }

    /**
     * Coerce CS-Cart `db_query` return values into an affected-rows int.
     * For INSERT/UPDATE/DELETE the return is numeric; for anything else
     * (or driver-level failure) we get false/null/string and fall back
     * to 0 so the caller treats it as "no rows changed."
     */
    private static function asAffectedRows(mixed $dbQueryResult): int
    {
        return is_numeric($dbQueryResult) ? (int) $dbQueryResult : 0;
    }

    /**
     * Mark a granted attempt as successfully processed at NETOPIA's side.
     * Persists the refund event's ntpID so a self-heal replay (in another
     * session, after a local-apply crash) can compare against the order's
     * `netopia_refund_log` and decide whether to re-run apply.
     */
    public function markSucceeded(string $requestId, string $ntpId): void
    {
        ($this->dbQuery)(
            'UPDATE ?:netopia_refund_attempts '
            . 'SET status = ?s, ntp_id = ?s, completed_at = ?i '
            . 'WHERE request_id = ?s',
            self::STATUS_SUCCEEDED,
            $ntpId,
            $this->clock->now()->getTimestamp(),
            $requestId,
        );
    }

    public function markFailed(string $requestId, string $error): void
    {
        ($this->dbQuery)(
            'UPDATE ?:netopia_refund_attempts '
            . 'SET status = ?s, error = ?s, completed_at = ?i '
            . 'WHERE request_id = ?s',
            self::STATUS_FAILED,
            mb_substr($error, 0, 500),
            $this->clock->now()->getTimestamp(),
            $requestId,
        );
    }
}
