<?php

declare(strict_types=1);

namespace Netopia\CsCart\Payment;

/**
 * Outcome of applying a refund to a CS-Cart order.
 *
 * Carries enough context for the admin-triggered refund controller to render a
 * meaningful notification ("Refunded 150 RON; order is Partially Refunded")
 * and for the IPN handler to log a structured trace of what happened.
 *
 * `applied` is false when RefundFinalizer short-circuits because the same
 * refund (by ntpID) was already applied — the controller treats this as
 * success because the desired end-state is reached.
 */
final readonly class RefundResult
{
    public function __construct(
        public bool $applied,
        public float $refundAmount,
        public float $cumulativeRefunded,
        public bool $isFullRefund,
        public string $csStatus,
        public string $reasonForSkip = '',
    ) {
    }

    public static function applied(
        float $refundAmount,
        float $cumulativeRefunded,
        bool $isFullRefund,
        string $csStatus,
    ): self {
        return new self(true, $refundAmount, $cumulativeRefunded, $isFullRefund, $csStatus);
    }

    public static function skipped(string $reason, float $cumulativeRefunded, string $csStatus): self
    {
        return new self(false, 0.0, $cumulativeRefunded, false, $csStatus, $reason);
    }
}
