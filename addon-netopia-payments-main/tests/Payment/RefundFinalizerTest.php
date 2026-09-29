<?php

declare(strict_types=1);

namespace Netopia\CsCart\Tests\Payment;

use Netopia\CsCart\Payment\RefundFinalizer;
use Netopia\CsCart\Payment\RefundResult;
use Netopia\CsCart\Status\StatusMapper;
use Netopia\CsCart\Tests\Support\FakeClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RefundFinalizer::class)]
#[CoversClass(RefundResult::class)]
final class RefundFinalizerTest extends TestCase
{
    public function testPartialRefundUsesPartialMappingAndRecordsHistory(): void
    {
        $paymentInfoCalls = [];
        $statusChangerCalls = [];
        $emailCalls = [];

        $finalizer = $this->buildFinalizer(
            paymentInfoCalls: $paymentInfoCalls,
            statusChangerCalls: $statusChangerCalls,
            emailCalls: $emailCalls,
        );

        $orderInfo = ['order_id' => 42, 'total' => 500.0, 'payment_info' => []];

        $result = $finalizer->apply(
            orderId:         42,
            orderInfo:       $orderInfo,
            processorParams: [],
            amount:          150.0,
            currency:        'RON',
            ntpId:           'ntp-refund-1',
            netopiaOrderId:  '42-1713600000123456',
            customerReason:  'Payment refunded.',
            adminReason:     'Admin reason text',
        );

        self::assertTrue($result->applied);
        self::assertSame(150.0, $result->cumulativeRefunded);
        self::assertFalse($result->isFullRefund);
        self::assertSame('P', $result->csStatus, 'partial refund defaults to P (Decision 1)');

        self::assertCount(1, $paymentInfoCalls);
        $info = $paymentInfoCalls[0]['info'];
        self::assertArrayNotHasKey(
            'transaction_id',
            $info,
            'transaction_id holds the payment ntpID for subsequent refund API calls — RefundFinalizer must not overwrite it with a refund event id',
        );
        self::assertSame('150,00 RON', $info['netopia_refunded_amount']);
        self::assertSame('P', $info['order_status']);
        self::assertSame('Payment refunded.', $info['reason_text']);
        self::assertSame('42-1713600000123456', $info['netopia_order_id']);
        self::assertStringContainsString('150,00 RON', $info['netopia_refund_log']);
        self::assertStringContainsString('partial refund', $info['netopia_refund_log']);
        self::assertStringContainsString('ntp-refund-1', $info['netopia_refund_log']);
        self::assertStringContainsString('[admin]', $info['netopia_refund_log']);

        self::assertCount(1, $statusChangerCalls);
        self::assertSame(42, $statusChangerCalls[0]['order_id']);
        self::assertSame('P', $statusChangerCalls[0]['status']);
        self::assertSame('Admin reason text', $statusChangerCalls[0]['reason']);
        self::assertFalse(
            $statusChangerCalls[0]['notify'],
            'notify=false: RefundEmailSender supersedes CS-Cart generic copy',
        );

        self::assertCount(1, $emailCalls);
        self::assertSame(150.0, $emailCalls[0]['refund_amount']);
        self::assertSame(150.0, $emailCalls[0]['cumulative_refunded']);
        self::assertSame(500.0, $emailCalls[0]['original_amount']);
        self::assertFalse($emailCalls[0]['is_full_refund']);
    }

    public function testFullRefundUsesFullMappingAndAccumulatesPriorPartial(): void
    {
        $paymentInfoCalls = [];
        $statusChangerCalls = [];
        $emailCalls = [];

        $finalizer = $this->buildFinalizer(
            paymentInfoCalls: $paymentInfoCalls,
            statusChangerCalls: $statusChangerCalls,
            emailCalls: $emailCalls,
        );

        $orderInfo = [
            'order_id' => 42,
            'total' => 500.0,
            'payment_info' => [
                'netopia_refunded_amount' => '350,00 RON',
                'transaction_id' => 'ntp-refund-1',
                'netopia_refund_log' => '[2026-01-01 10:00] 350,00 RON — partial refund (ntp-refund-1) [admin]',
            ],
        ];

        $result = $finalizer->apply(
            orderId:         42,
            orderInfo:       $orderInfo,
            processorParams: [],
            amount:          150.0,
            currency:        'RON',
            ntpId:           'ntp-refund-2',
            netopiaOrderId:  '42-1713600000234567',
            customerReason:  'Payment refunded.',
            adminReason:     'Admin reason text',
            origin:          'admin',
        );

        self::assertTrue($result->isFullRefund);
        self::assertSame(500.0, $result->cumulativeRefunded);
        self::assertSame('B', $result->csStatus);

        $info = $paymentInfoCalls[0]['info'];
        self::assertSame('500,00 RON', $info['netopia_refunded_amount']);
        self::assertSame('B', $info['order_status']);
        self::assertArrayNotHasKey(
            'transaction_id',
            $info,
            'payment ntpID must survive across refunds so the admin controller can issue further refunds against it',
        );
        // History preserves prior entry and appends the new one in chronological order.
        self::assertStringContainsString('350,00 RON', $info['netopia_refund_log']);
        self::assertStringContainsString('150,00 RON', $info['netopia_refund_log']);
        self::assertStringContainsString('full refund', $info['netopia_refund_log']);
        self::assertStringContainsString('[admin]', $info['netopia_refund_log']);
    }

    public function testCustomPartialMappingIsRespected(): void
    {
        $paymentInfoCalls = [];
        $statusChangerCalls = [];
        $emailCalls = [];

        $finalizer = $this->buildFinalizer(
            paymentInfoCalls: $paymentInfoCalls,
            statusChangerCalls: $statusChangerCalls,
            emailCalls: $emailCalls,
        );

        $result = $finalizer->apply(
            orderId:         42,
            orderInfo:       ['order_id' => 42, 'total' => 500.0, 'payment_info' => []],
            processorParams: ['status_map_8_partial' => 'Pa', 'status_map_8_full' => 'Re'],
            amount:          50.0,
            currency:        'RON',
            ntpId:           'ntp-1',
            netopiaOrderId:  '',
            customerReason:  '',
            adminReason:     '',
        );

        self::assertSame('Pa', $result->csStatus);
        self::assertSame('Pa', $statusChangerCalls[0]['status']);
        self::assertSame('Pa', $paymentInfoCalls[0]['info']['order_status']);
    }

    public function testCustomFullMappingIsRespectedOnCumulativeReachingTotal(): void
    {
        $paymentInfoCalls = [];
        $statusChangerCalls = [];
        $emailCalls = [];

        $finalizer = $this->buildFinalizer(
            paymentInfoCalls: $paymentInfoCalls,
            statusChangerCalls: $statusChangerCalls,
            emailCalls: $emailCalls,
        );

        $result = $finalizer->apply(
            orderId:         42,
            orderInfo:       ['order_id' => 42, 'total' => 500.0, 'payment_info' => []],
            processorParams: ['status_map_8_full' => 'Re'],
            amount:          500.0,
            currency:        'RON',
            ntpId:           'ntp-1',
            netopiaOrderId:  '',
            customerReason:  '',
            adminReason:     '',
        );

        self::assertSame('Re', $result->csStatus);
    }

    public function testOriginalAmountIsTakenFromNetopiaPaymentInfoWhenAvailable(): void
    {
        // Regression: previously the full-vs-partial decision compared
        // against $orderInfo['total'] (CS-Cart's display-currency total),
        // which can diverge from what NETOPIA actually charged when the
        // store sells in a non-RON currency or when the order total has
        // been edited post-payment. Result: a 100 RON refund of a 330 RON
        // payment got mis-labelled "full refund" because total was 1250 LEI
        // (or 0 in some flows). The fix is to source the original amount
        // from `payment_info.netopia_amount`, the round-trippable display
        // string IpnHandler wrote when the payment IPN landed.
        $paymentInfoCalls = [];
        $statusChangerCalls = [];
        $emailCalls = [];

        $finalizer = $this->buildFinalizer(
            paymentInfoCalls: $paymentInfoCalls,
            statusChangerCalls: $statusChangerCalls,
            emailCalls: $emailCalls,
        );

        $orderInfo = [
            'order_id' => 42,
            // CS-Cart total is in LEI display currency and disagrees with
            // the actually-charged 330,58 RON. Must NOT drive full-vs-partial.
            'total' => 1250.0,
            'payment_info' => [
                'netopia_amount' => '330,58 RON',
            ],
        ];

        $result = $finalizer->apply(
            orderId:         42,
            orderInfo:       $orderInfo,
            processorParams: [],
            amount:          100.0,
            currency:        'RON',
            ntpId:           'ntp-refund-1',
            netopiaOrderId:  '',
            customerReason:  '',
            adminReason:     '',
        );

        self::assertFalse(
            $result->isFullRefund,
            '100 RON refund of a 330,58 RON payment is partial; total=1250 LEI must not enter the comparison',
        );
        self::assertSame(330.58, $emailCalls[0]['original_amount']);
    }

    public function testOriginalAmountPrefersStartAmountWhenBothFieldsPresent(): void
    {
        // Cap source switched from `netopia_amount` (post-FX IPN amount) to
        // `netopia_start_amount` (pre-FX original charge sent to
        // /payment/card/start) — same basis NETOPIA's /operation/credit
        // enforces server-side. When both fields are present, start_amount
        // wins so the full-vs-partial comparison agrees with the gateway's
        // own cap and the admin can refund up to (but not past) the EUR
        // amount actually charged.
        $paymentInfoCalls = [];
        $statusChangerCalls = [];
        $emailCalls = [];

        $finalizer = $this->buildFinalizer(
            paymentInfoCalls: $paymentInfoCalls,
            statusChangerCalls: $statusChangerCalls,
            emailCalls: $emailCalls,
        );

        $orderInfo = [
            'order_id' => 71,
            'total' => 68.70,
            'payment_info' => [
                // 68.70 EUR was sent to /payment/card/start; NETOPIA
                // converted to RON at their FX rate and echoed 361.78 in
                // the IPN. Cap is the EUR figure, not the RON one.
                'netopia_start_amount' => '68,70 EUR',
                'netopia_amount' => '361,78 RON',
            ],
        ];

        $result = $finalizer->apply(
            orderId:         71,
            orderInfo:       $orderInfo,
            processorParams: [],
            amount:          68.70,
            currency:        'EUR',
            ntpId:           'ntp-refund-1',
            netopiaOrderId:  '',
            customerReason:  '',
            adminReason:     '',
        );

        self::assertTrue(
            $result->isFullRefund,
            '68.70 EUR refund must label as full because original is sourced from netopia_start_amount (EUR), not netopia_amount (RON)',
        );
        self::assertSame(68.70, $emailCalls[0]['original_amount']);
    }

    public function testHalfCentToleranceFlipsToFullEvenWhenCumulativeUndershootsByFloatRounding(): void
    {
        $paymentInfoCalls = [];
        $statusChangerCalls = [];
        $emailCalls = [];

        $finalizer = $this->buildFinalizer(
            paymentInfoCalls: $paymentInfoCalls,
            statusChangerCalls: $statusChangerCalls,
            emailCalls: $emailCalls,
        );

        $result = $finalizer->apply(
            orderId:         42,
            orderInfo:       ['order_id' => 42, 'total' => 500.0, 'payment_info' => []],
            processorParams: [],
            amount:          499.997,
            currency:        'RON',
            ntpId:           'ntp-1',
            netopiaOrderId:  '',
            customerReason:  '',
            adminReason:     '',
        );

        self::assertTrue(
            $result->isFullRefund,
            '499.997 vs 500.0 must round to full so float artefacts cannot leave an order in partial-refund limbo',
        );
    }

    /**
     * @param array<int, array<string, mixed>> $paymentInfoCalls
     * @param array<int, array<string, mixed>> $statusChangerCalls
     * @param array<int, array<string, mixed>> $emailCalls
     */
    private function buildFinalizer(
        array &$paymentInfoCalls,
        array &$statusChangerCalls,
        array &$emailCalls,
    ): RefundFinalizer {
        $clock = new FakeClock(strtotime('2026-04-26 12:00:00') ?: 0);

        return new RefundFinalizer(
            statusMapper:       new StatusMapper(),
            paymentInfoUpdater: static function (int $id, array $info) use (&$paymentInfoCalls): void {
                $paymentInfoCalls[] = ['order_id' => $id, 'info' => $info];
            },
            orderStatusChanger: static function (int $id, string $status, string $reason, bool $notify) use (&$statusChangerCalls): void {
                $statusChangerCalls[] = ['order_id' => $id, 'status' => $status, 'reason' => $reason, 'notify' => $notify];
            },
            refundEmailSender:  static function (
                array $orderInfo,
                float $refund,
                float $cumulative,
                float $original,
                string $currency,
                string $ntpId,
                bool $isFullRefund,
            ) use (&$emailCalls): bool {
                $emailCalls[] = compact('refund', 'cumulative', 'original', 'currency', 'ntpId', 'isFullRefund') + [
                    'refund_amount' => $refund,
                    'cumulative_refunded' => $cumulative,
                    'original_amount' => $original,
                    'is_full_refund' => $isFullRefund,
                ];
                return true;
            },
            clock: $clock,
        );
    }
}
