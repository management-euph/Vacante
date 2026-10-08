<?php

declare(strict_types=1);

namespace Netopia\CsCart\Tests\Payment;

use Netopia\CsCart\Payment\RefundBasis;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RefundBasis::class)]
final class RefundBasisTest extends TestCase
{
    public function testFxOrderUsesTheStartRequestChargeNotTheIpnAmount(): void
    {
        // Charged 68,70 EUR at payment/card/start; NETOPIA settled it as
        // 361,78 RON in the IPN. Refunds are capped and sent in EUR.
        $basis = RefundBasis::fromPaymentInfo([
            'netopia_start_amount' => '68,70 EUR',
            'netopia_amount' => '361,78 RON',
        ]);

        self::assertSame(68.70, $basis->paid);
        self::assertSame('EUR', $basis->currency);
        self::assertSame(0.0, $basis->alreadyRefunded);
        self::assertSame(68.70, $basis->remaining());
        self::assertTrue($basis->isKnown());
    }

    public function testRefundedAmountIsCountedOnTheSameBasis(): void
    {
        // RefundFinalizer writes netopia_refunded_amount in the start currency.
        $basis = RefundBasis::fromPaymentInfo([
            'netopia_start_amount' => '68,70 EUR',
            'netopia_amount' => '361,78 RON',
            'netopia_refunded_amount' => '20,00 EUR',
        ]);

        self::assertSame(20.0, $basis->alreadyRefunded);
        self::assertEqualsWithDelta(48.70, $basis->remaining(), 0.0001);
        self::assertSame(29.1, $basis->refundedPercent());
    }

    public function testLegacyOrderWithoutStartAmountFallsBackToIpnAmount(): void
    {
        $basis = RefundBasis::fromPaymentInfo([
            'netopia_amount' => '1.850,50 RON',
            'netopia_refunded_amount' => '850,50 RON',
        ]);

        self::assertSame(1850.50, $basis->paid);
        self::assertSame('RON', $basis->currency);
        self::assertSame(1000.0, $basis->remaining());
    }

    public function testEmptyStartAmountFallsBackToIpnAmount(): void
    {
        $basis = RefundBasis::fromPaymentInfo([
            'netopia_start_amount' => '',
            'netopia_amount' => '100,00 RON',
        ]);

        self::assertSame('RON', $basis->currency);
        self::assertSame(100.0, $basis->paid);
    }

    public function testOverRefundedOrderHasNothingRemainingAndAFullBar(): void
    {
        $basis = RefundBasis::fromPaymentInfo([
            'netopia_start_amount' => '50,00 EUR',
            'netopia_refunded_amount' => '50,01 EUR',
        ]);

        self::assertSame(0.0, $basis->remaining());
        self::assertSame(100.0, $basis->refundedPercent());
    }

    public function testNoRecordedAmountOrCurrencyIsUnknown(): void
    {
        self::assertFalse(RefundBasis::fromPaymentInfo([])->isKnown());
        self::assertFalse(RefundBasis::fromPaymentInfo(['netopia_amount' => '100,00'])->isKnown());
        self::assertFalse(RefundBasis::fromPaymentInfo(['netopia_amount' => '0,00 RON'])->isKnown());
        self::assertSame(0.0, RefundBasis::fromPaymentInfo([])->refundedPercent());
    }
}
