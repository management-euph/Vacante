<?php

declare(strict_types=1);

namespace Netopia\CsCart\Tests\Payment;

use Netopia\CsCart\Payment\PaymentLinkEmailSender;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PaymentLinkEmailSender::class)]
final class PaymentLinkEmailSenderTest extends TestCase
{
    public function testSkipsWhenOrderHasNoCustomerEmail(): void
    {
        $calls = [];
        $sender = $this->buildSender($calls);

        $sent = $sender->send(
            orderInfo:  ['order_id' => 42],
            paymentUrl: 'https://example/pay',
        );

        self::assertFalse($sent);
        self::assertSame([], $calls, 'mailSender must not be invoked when the order has no email');
    }

    public function testDoesNotPassPreFormattedAmountAndForwardsOrderInfoVerbatim(): void
    {
        // Regression for the "66.8 RON" bug: the email body used to combine a
        // CS-Cart-converted price with $orderInfo['secondary_currency'],
        // producing a value labelled with the wrong code. The retry email
        // now embeds CS-Cart's order.summary document via
        // {{ include_doc("order.summary", order_info.order_id) }}, so the
        // sender must NOT pre-format any amount and must forward order_info
        // verbatim (including order_id, which the doc resolves into the
        // full order context).
        $calls = [];
        $sender = $this->buildSender($calls);

        $orderInfo = [
            'order_id' => 67,
            'email' => 'customer@example.com',
            'b_firstname' => 'Ada',
            'b_lastname' => 'Lovelace',
            'lang_code' => 'ro',
            'secondary_currency' => 'EUR',
        ];

        $sent = $sender->send(
            orderInfo:  $orderInfo,
            paymentUrl: 'https://secure-sandbox.netopia-payments.com/ui/card?p=abc',
        );

        self::assertTrue($sent);
        self::assertCount(1, $calls);
        $call = $calls[0];

        self::assertSame('ro', $call['langCode'], 'customer lang_code must be forwarded so Twig renders in their language');
        self::assertSame('netopia_payment_retry', $call['config']['template_code']);
        self::assertSame('customer@example.com', $call['config']['to']);
        self::assertArrayNotHasKey(
            'amount',
            $call['config']['data'],
            'sender must not pre-format any amount — the Twig template embeds the order.summary doc',
        );
        self::assertSame(
            $orderInfo,
            $call['config']['data']['order_info'],
            'order_info must be forwarded verbatim so the Twig template can read its fields',
        );
        self::assertSame('Ada Lovelace', $call['config']['data']['customer_name']);
        self::assertSame(67, $call['config']['data']['order_id']);
        self::assertSame(
            'https://secure-sandbox.netopia-payments.com/ui/card?p=abc',
            $call['config']['data']['payment_url'],
        );
    }

    public function testFallsBackToEmailWhenCustomerNameIsBlank(): void
    {
        $calls = [];
        $sender = $this->buildSender($calls);

        $sent = $sender->send(
            orderInfo:  [
                'order_id' => 1,
                'email' => 'noname@example.com',
                'b_firstname' => '',
                'b_lastname' => '',
                'lang_code' => 'ro',
            ],
            paymentUrl: 'https://example/pay',
        );

        self::assertTrue($sent);
        self::assertSame('noname@example.com', $calls[0]['config']['data']['customer_name']);
    }

    public function testFallsBackToConstructorLangCodeWhenOrderHasNone(): void
    {
        $calls = [];
        $sender = $this->buildSender($calls, fallbackLangCode: 'en');

        $sender->send(
            orderInfo:  [
                'order_id' => 1,
                'email' => 'customer@example.com',
                'b_firstname' => 'A',
                'b_lastname' => 'B',
            ],
            paymentUrl: 'https://example/pay',
        );

        self::assertSame('en', $calls[0]['langCode']);
    }

    /**
     * @param array<int, array<string, mixed>> $calls
     */
    private function buildSender(
        array &$calls,
        string $companyName = 'TestCo',
        string $fallbackLangCode = 'ro',
    ): PaymentLinkEmailSender {
        $mailSender = static function (array $config, string $langCode) use (&$calls): bool {
            $calls[] = ['config' => $config, 'langCode' => $langCode];
            return true;
        };

        return new PaymentLinkEmailSender(
            mailSender:       $mailSender,
            companyName:      $companyName,
            fallbackLangCode: $fallbackLangCode,
        );
    }
}
