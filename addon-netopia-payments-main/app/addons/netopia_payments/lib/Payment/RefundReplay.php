<?php

declare(strict_types=1);

namespace Netopia\CsCart\Payment;

/**
 * Pure decision logic for the refund-replay self-heal path.
 *
 * Context: when `RefundAttemptStore::claim()` returns `already_completed`
 * with `status = 'succeeded'`, NETOPIA processed the refund — but the
 * original attempt may have crashed between `markSucceeded` and the local
 * `RefundFinalizer::apply()` write (server kill, fatal error, ...).
 *
 * This helper takes the stored `ntp_id` and the order's current
 * `netopia_refund_log` and answers: "is local state already in sync, or
 * should the controller re-run apply with the stored ntp_id to reconcile?"
 *
 * Kept as a separate class with a single static decision method so the
 * controller branch is unit-testable without booting CS-Cart globals.
 */
final class RefundReplay
{
    /**
     * Returns true when the order's refund_log does NOT yet record the
     * stored ntp_id, meaning the prior local apply did not land. Returns
     * false when the ntp_id is already in the log (apply succeeded — no
     * action needed) or when the stored ntp_id is empty (we have nothing
     * to dedup against — caller should refuse to replay).
     */
    public static function needsLocalReapply(string $storedNtpId, string $existingRefundLog): bool
    {
        if ($storedNtpId === '') {
            return false;
        }
        return !str_contains($existingRefundLog, '(' . $storedNtpId . ')');
    }
}
