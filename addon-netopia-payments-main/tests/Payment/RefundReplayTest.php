<?php

declare(strict_types=1);

namespace Netopia\CsCart\Tests\Payment;

use Netopia\CsCart\Payment\RefundReplay;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RefundReplay::class)]
final class RefundReplayTest extends TestCase
{
    public function testNeedsLocalReapplyWhenNtpIdMissingFromLog(): void
    {
        // Prior attempt's markSucceeded landed in refund_attempts, but the
        // local apply DB write never happened — refund_log is empty.
        // Self-heal must fire.
        self::assertTrue(RefundReplay::needsLocalReapply('ntp-X', ''));
    }

    public function testNoReapplyWhenNtpIdAlreadyInLog(): void
    {
        // Both refund_attempts and refund_log already record the ntpID.
        // Idempotent replay — no re-apply needed.
        $log = '[2026-04-26 12:00] 100,00 RON — partial refund (ntp-X) [admin]';
        self::assertFalse(RefundReplay::needsLocalReapply('ntp-X', $log));
    }

    public function testNoReapplyWhenStoredNtpIdIsEmpty(): void
    {
        // Defensive: without an ntp_id we have no key to dedup against,
        // so refusing to replay is safer than guessing.
        self::assertFalse(RefundReplay::needsLocalReapply('', ''));
        self::assertFalse(RefundReplay::needsLocalReapply('', 'some prior log'));
    }

    public function testSubstringMatchIsScopedToParenthesisedToken(): void
    {
        // The ntpID `ntp-X` must NOT match `ntp-X-suffix` written as a
        // separate refund — `(ntp-X)` substring wouldn't appear in
        // `(ntp-X-suffix)`. This guards against false positives where two
        // ntpIDs share a prefix.
        $log = '[2026-04-26 12:00] 100,00 RON — partial refund (ntp-X-suffix) [admin]';
        self::assertTrue(RefundReplay::needsLocalReapply('ntp-X', $log));
    }
}
