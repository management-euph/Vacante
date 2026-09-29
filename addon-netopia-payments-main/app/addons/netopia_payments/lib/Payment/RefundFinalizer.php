<?php

declare(strict_types=1);

namespace Netopia\CsCart\Payment;

use Closure;
use Netopia\CsCart\Ipn\IpnHandler;
use Netopia\CsCart\Status\StatusMapper;
use Netopia\CsCart\Support\Arr;
use Netopia\CsCart\Support\ClockInterface;
use Netopia\CsCart\Support\SystemClock;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Encapsulates the side effects of a NETOPIA refund: cumulative refund
 * arithmetic, payment_info update, CS-Cart status transition, and the
 * customer-facing refund email.
 *
 * Sole caller: the admin-triggered refund controller. After
 * `operation/credit` returns 200, the controller invokes this finalizer
 * synchronously with the admin-form amount and the credit-response ntpID.
 *
 * IpnHandler does NOT call this class. Refund IPNs from NETOPIA carry the
 * original payment's ntpID and `payment.amount = original payment amount`,
 * neither of which lets the IPN driver know which credit it confirms or
 * what the credit delta was — so the IPN-side path is unsupported and
 * trailing IPNs are acknowledged with no DB writes.
 *
 * Idempotency: the finalizer itself does not re-check ntpID equality —
 * the controller gates re-entry via `RefundAttemptStore` and
 * `RefundReplay::needsLocalReapply`. Once apply() runs, it always writes.
 *
 * @phpstan-type PaymentInfoUpdater \Closure(int, array<string, mixed>): void
 * @phpstan-type OrderStatusChanger \Closure(int, string, string, bool): void
 * @phpstan-type RefundEmailDispatcher \Closure(array<string, mixed>, float, float, float, string, string, bool): bool
 */
final class RefundFinalizer
{
    /**
     * @param StatusMapper          $statusMapper       NETOPIA status to CS-Cart order status
     * @param PaymentInfoUpdater    $paymentInfoUpdater
     * @param OrderStatusChanger    $orderStatusChanger
     * @param RefundEmailDispatcher $refundEmailSender
     * @param ClockInterface        $clock              stamps the refund log entry
     * @param LoggerInterface       $logger             receives the refund audit trail
     */
    public function __construct(
        private readonly StatusMapper $statusMapper,
        private readonly Closure $paymentInfoUpdater,
        private readonly Closure $orderStatusChanger,
        private readonly Closure $refundEmailSender,
        private readonly ClockInterface $clock = new SystemClock(),
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Apply a refund event to a CS-Cart order.
     *
     * @param array<string, mixed> $orderInfo       Loaded via fn_get_order_info
     * @param array<string, mixed> $processorParams Loaded via fn_get_payment_method_data
     * @param string               $netopiaOrderId  The verified `order.orderID`
     *                                               (NETOPIA-side suffixed id)
     * @param string               $origin          Free-text source label
     *                                               appended to the refund-log
     *                                               line so admins can tell
     *                                               first-attempt admin
     *                                               refunds from self-heal
     *                                               replays.
     */
    public function apply(
        int $orderId,
        array $orderInfo,
        array $processorParams,
        float $amount,
        string $currency,
        string $ntpId,
        string $netopiaOrderId,
        string $customerReason,
        string $adminReason,
        string $origin = 'admin',
    ): RefundResult {
        $paymentInfoPrev = Arr::array($orderInfo, 'payment_info');
        $previousRefundedStr = Arr::string($paymentInfoPrev, 'netopia_refunded_amount');
        $previousRefunded = $previousRefundedStr === '' ? 0.0 : IpnHandler::parseAmount($previousRefundedStr);
        $cumulativeRefunded = $previousRefunded + $amount;
        // Compare against the START-REQUEST charge (`netopia_start_amount`,
        // e.g. "68,70 EUR"), the same basis the refund controller uses for
        // its cap-check — so cumulative_refunded and originalAmount are
        // always denominated in the same currency. Falling back to
        // `netopia_amount` (the post-FX IPN amount, e.g. "361,78 RON")
        // keeps legacy orders that pre-date `netopia_start_amount`
        // working: same-currency orders agree on both values, and FX
        // orders use the controller's matching fallback so cumulative
        // and original stay in the same currency within one order.
        // Final fallback to the CS-Cart order total handles orders with
        // no NETOPIA payment record at all (avoids labelling every
        // refund as "full" when both fields are absent).
        $netopiaStartStr = Arr::string($paymentInfoPrev, 'netopia_start_amount');
        $netopiaPaidStr = $netopiaStartStr !== ''
            ? $netopiaStartStr
            : Arr::string($paymentInfoPrev, 'netopia_amount');
        $netopiaPaid = $netopiaPaidStr === '' ? 0.0 : IpnHandler::parseAmount($netopiaPaidStr);
        $originalAmount = $netopiaPaid > 0.0 ? $netopiaPaid : Arr::float($orderInfo, 'total');
        // Tolerate float rounding — half a cent is well below any
        // currency's smallest unit and prevents 499.995 < 500.0 from
        // being mis-labelled partial. Also guard against
        // originalAmount=0 (no NETOPIA payment record AND no order
        // total) so we don't mis-label every refund as full.
        $isFullRefund = $originalAmount > 0.0 && $cumulativeRefunded + 0.005 >= $originalAmount;
        $csStatus = $this->statusMapper->mapRefund($processorParams, !$isFullRefund);
        $refundLog = $this->appendRefundLogEntry(
            previous:    Arr::string($paymentInfoPrev, 'netopia_refund_log'),
            amount:      $amount,
            currency:    $currency,
            ntpId:       $ntpId,
            isFull:      $isFullRefund,
            origin:      $origin,
        );

        $this->logger->info('NETOPIA refund finalised', [
            'order_id' => $orderId,
            'ntp_id' => $ntpId,
            'refund_amount' => $amount,
            'cumulative_refunded' => $cumulativeRefunded,
            'is_full_refund' => $isFullRefund,
            'cs_status' => $csStatus,
            'origin' => $origin,
        ]);

        $payload = [
            'netopia_refunded_amount' => IpnHandler::formatAmount($cumulativeRefunded, $currency),
            'netopia_refund_log' => $refundLog,
            'order_status' => $csStatus,
            'reason_text' => $customerReason,
        ];
        if ($netopiaOrderId !== '') {
            $payload['netopia_order_id'] = $netopiaOrderId;
        }
        // Intentionally do NOT write `transaction_id`. That field carries
        // the original payment's ntpID — it's read by the admin-triggered
        // refund controller as the `paymentId` for subsequent /payment/card/
        // credit calls. Overwriting it with a refund's ntpID would break
        // every refund after the first one.

        ($this->paymentInfoUpdater)($orderId, $payload);
        // notify=false: RefundEmailSender supersedes CS-Cart's generic
        // status-change email so the customer receives one refund-specific
        // message with full vs partial framing.
        ($this->orderStatusChanger)($orderId, $csStatus, $adminReason, false);

        ($this->refundEmailSender)(
            $orderInfo,
            $amount,
            $cumulativeRefunded,
            $originalAmount,
            $currency,
            $ntpId,
            $isFullRefund,
        );

        return RefundResult::applied($amount, $cumulativeRefunded, $isFullRefund, $csStatus);
    }

    /**
     * Build a chronological refund-log entry to display in the admin order
     * details Payment Information panel. Each refund event appends one line;
     * older lines are preserved verbatim so admins see the full history.
     *
     * Format: `[YYYY-MM-DD HH:MM] {amount} — full|partial refund (ntpID) [origin]`
     *
     * Stored in `payment_info.netopia_refund_log`. The `netopia_refund_log`
     * label is seeded by `Install\Seeder` so CS-Cart renders it with a
     * human-friendly heading.
     */
    private function appendRefundLogEntry(
        string $previous,
        float $amount,
        string $currency,
        string $ntpId,
        bool $isFull,
        string $origin,
    ): string {
        $entry = sprintf(
            '[%s] %s — %s (%s) [%s]',
            $this->clock->now()->format('Y-m-d H:i'),
            IpnHandler::formatAmount($amount, $currency),
            $isFull ? 'full refund' : 'partial refund',
            $ntpId,
            $origin,
        );
        return $previous === '' ? $entry : $previous . "\n" . $entry;
    }
}
