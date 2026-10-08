<?php

declare(strict_types=1);

namespace Netopia\CsCart\Payment;

use Netopia\CsCart\Ipn\IpnHandler;
use Netopia\CsCart\Support\Arr;

/**
 * The amount and currency an order's refunds are counted in.
 *
 * NETOPIA's `operation/credit` caps a refund against the charge we sent to
 * `payment/card/start`, recorded as `payment_info.netopia_start_amount`
 * (e.g. "68,70 EUR"). The IPN's `netopia_amount` is the amount after
 * NETOPIA's currency conversion (e.g. "361,78 RON") and is NOT the refund
 * basis. Orders placed before `netopia_start_amount` existed fall back to
 * `netopia_amount`; for same-currency orders the two agree.
 *
 * `RefundFinalizer` stores `netopia_refunded_amount` on the same basis, so
 * paid, refunded and remaining are always in one currency. The refund
 * controller (cap + currency sent to NETOPIA) and the order panel
 * (orders.post.php: amounts, "Refund all", bar) both read it from here so
 * the admin types the amount in the currency that is actually refunded.
 */
final readonly class RefundBasis
{
    public function __construct(
        public float $paid,
        public string $currency,
        public float $alreadyRefunded,
    ) {
    }

    /**
     * @param array<array-key, mixed> $paymentInfo the order's payment_info
     */
    public static function fromPaymentInfo(array $paymentInfo): self
    {
        $startStr = Arr::string($paymentInfo, 'netopia_start_amount');
        $paidStr = $startStr !== '' ? $startStr : Arr::string($paymentInfo, 'netopia_amount');
        $paid = IpnHandler::parseFormattedAmount($paidStr);

        $refundedStr = Arr::string($paymentInfo, 'netopia_refunded_amount');
        $alreadyRefunded = $refundedStr === '' ? 0.0 : IpnHandler::parseAmount($refundedStr);

        return new self($paid['value'], $paid['currency'], $alreadyRefunded);
    }

    /**
     * True when the order records a paid amount with its currency, so a
     * refund can be offered and sent.
     */
    public function isKnown(): bool
    {
        return $this->currency !== '' && $this->paid > 0.0;
    }

    public function remaining(): float
    {
        return max(0.0, $this->paid - $this->alreadyRefunded);
    }

    /**
     * The refunded share of the paid amount, 0–100, one decimal.
     */
    public function refundedPercent(): float
    {
        return $this->paid > 0.0 ? min(100.0, round($this->alreadyRefunded / $this->paid * 100, 1)) : 0.0;
    }
}
