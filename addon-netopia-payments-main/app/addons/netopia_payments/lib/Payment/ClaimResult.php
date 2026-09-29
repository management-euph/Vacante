<?php

declare(strict_types=1);

namespace Netopia\CsCart\Payment;

/**
 * Outcome of `RefundAttemptStore::claim()`. Tagged-union for the three
 * mutually-exclusive states of a refund attempt's claim:
 *
 *   - `granted`            this caller acquired the attempt — proceed to
 *                          NETOPIA API call + local apply.
 *   - `already_completed`  another session already finished this same
 *                          attempt (same deterministic request_id, same
 *                          intent) — caller replays the cached outcome.
 *                          For the success branch the controller also
 *                          self-heals by re-running the local apply if
 *                          the order's refund_log doesn't yet show the
 *                          stored ntpID.
 *   - `in_flight`          another session is currently processing the
 *                          same attempt and the row is within its TTL —
 *                          caller surfaces a "wait, refresh" notice.
 *
 * `existing` carries the row data (request_id / order_id / amount / ntp_id /
 * status / error / created_at / completed_at) for the non-granted branches
 * so the caller can render meaningful messages without an extra query.
 */
final readonly class ClaimResult
{
    public const string KIND_GRANTED = 'granted';
    public const string KIND_ALREADY_COMPLETED = 'already_completed';
    public const string KIND_IN_FLIGHT = 'in_flight';

    /**
     * @param array<string, mixed>|null $existing
     */
    public function __construct(
        public string $kind,
        public ?array $existing = null,
    ) {
    }

    public static function granted(): self
    {
        return new self(self::KIND_GRANTED);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function alreadyCompleted(array $row): self
    {
        return new self(self::KIND_ALREADY_COMPLETED, $row);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function inFlight(array $row): self
    {
        return new self(self::KIND_IN_FLIGHT, $row);
    }
}
