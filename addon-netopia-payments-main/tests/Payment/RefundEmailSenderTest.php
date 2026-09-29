<?php

declare(strict_types=1);

namespace Netopia\CsCart\Tests\Payment;

use Netopia\CsCart\Payment\RefundEmailSender;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RefundEmailSender::class)]
final class RefundEmailSenderTest extends TestCase
{
    public function testSkipsWhenOrderHasNoCustomerEmail(): void
    {
        $calls = [];
        $sender = $this->buildSender($calls);

        $sent = $sender->send(
            orderInfo:          ['order_id' => 42],
            refundAmount:       150.0,
            cumulativeRefunded: 150.0,
            originalAmount:     500.0,
            currency:           'RON',
            ntpId:              'ntp-1',
            isFullRefund:       false,
        );

        self::assertFalse($sent);
        self::assertSame([], $calls, 'mailSender must not be invoked when the order has no email');
    }

    public function testPartialRefundSendsWithExpectedDataAndTemplateCode(): void
    {
        $calls = [];
        $sender = $this->buildSender($calls);

        $orderInfo = [
            'order_id' => 42,
            'email' => 'customer@example.com',
            'b_firstname' => 'Ada',
            'b_lastname' => 'Lovelace',
            'lang_code' => 'ro',
        ];

        $sent = $sender->send(
            orderInfo:          $orderInfo,
            refundAmount:       150.0,
            cumulativeRefunded: 150.0,
            originalAmount:     500.0,
            currency:           'RON',
            ntpId:              'ntp-partial',
            isFullRefund:       false,
        );

        self::assertTrue($sent);
        self::assertCount(1, $calls);
        $call = $calls[0];

        self::assertSame('ro', $call['langCode'], 'customer lang_code must be forwarded so Twig renders in their language');

        $config = $call['config'];
        self::assertSame('customer@example.com', $config['to']);
        self::assertSame('netopia_refund_notification', $config['template_code']);

        $data = $config['data'];
        self::assertSame('Ada Lovelace', $data['customer_name']);
        self::assertSame(42, $data['order_id']);
        self::assertSame('150,00 RON', $data['refund_amount']);
        self::assertSame('150,00 RON', $data['cumulative_refunded']);
        self::assertSame('500,00 RON', $data['original_amount']);
        self::assertSame('350,00 RON', $data['remaining_paid']);
        self::assertSame('ntp-partial', $data['ntp_id']);
        self::assertFalse($data['is_full_refund']);
        self::assertSame('Acme Widgets', $data['company_name']);
        self::assertArrayHasKey('refund_date', $data);
    }

    public function testFullRefundSetsIsFullRefundFlagAndZeroRemaining(): void
    {
        $calls = [];
        $sender = $this->buildSender($calls);

        $sender->send(
            orderInfo: [
                'order_id' => 43,
                'email' => 'customer@example.com',
                'b_firstname' => 'Grace',
                'b_lastname' => 'Hopper',
            ],
            refundAmount:       500.0,
            cumulativeRefunded: 500.0,
            originalAmount:     500.0,
            currency:           'RON',
            ntpId:              'ntp-full',
            isFullRefund:       true,
        );

        self::assertCount(1, $calls);
        $data = $calls[0]['config']['data'];
        self::assertTrue($data['is_full_refund']);
        self::assertSame('0,00 RON', $data['remaining_paid'], 'remaining must clamp at zero for full refund');
    }

    public function testFallsBackToEmailAddressWhenCustomerNameMissing(): void
    {
        $calls = [];
        $sender = $this->buildSender($calls);

        $sender->send(
            orderInfo: [
                'order_id' => 44,
                'email' => 'nameless@example.com',
            ],
            refundAmount:       100.0,
            cumulativeRefunded: 100.0,
            originalAmount:     100.0,
            currency:           'EUR',
            ntpId:              'ntp-anon',
            isFullRefund:       true,
        );

        self::assertSame('nameless@example.com', $calls[0]['config']['data']['customer_name']);
    }

    public function testFallsBackToPrimaryCurrencyWhenNetopiaCurrencyEmpty(): void
    {
        // Guards against a NETOPIA IPN that omits payment.currency — the
        // formatter would otherwise emit a bare number with no currency
        // code, confusing customers. Fallback chain: order secondary →
        // primary.
        $calls = [];
        $sender = $this->buildSender($calls);

        $sender->send(
            orderInfo: [
                'order_id' => 45,
                'email' => 'customer@example.com',
                'secondary_currency' => 'EUR',
            ],
            refundAmount:       50.0,
            cumulativeRefunded: 50.0,
            originalAmount:     50.0,
            currency:           '',
            ntpId:              'ntp-no-ccy',
            isFullRefund:       true,
        );

        self::assertSame('50,00 EUR', $calls[0]['config']['data']['refund_amount']);
    }

    /**
     * @param array<int, array{config: array<string, mixed>, langCode: string}> $calls
     */
    private function buildSender(array &$calls): RefundEmailSender
    {
        return new RefundEmailSender(
            mailSender: static function (array $config, string $langCode) use (&$calls): bool {
                $calls[] = ['config' => $config, 'langCode' => $langCode];
                return true;
            },
            companyName: 'Acme Widgets',
            primaryCurrency: 'RON',
            fallbackLangCode: 'en',
        );
    }
}
