<?php

declare(strict_types=1);

namespace Netopia\CsCart\Tests\Ipn;

use Netopia\CsCart\Ipn\IpnHandler;
use Netopia\CsCart\Ipn\IpnVerifier;
use Netopia\CsCart\Key\KeyStorage;
use Netopia\CsCart\Status\StatusMapper;
use Netopia\CsCart\Status\StatusMessage;
use Netopia\CsCart\Tests\Support\RsaTestFixtures;
use Netopia\Payment2\Enum\PaymentStatus;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Confirms the architectural fix for the F → P retry case: IpnHandler
 * dispatches to fn_finish_payment for first-time finalisation (O/N) and
 * to fn_change_order_status for retries against a terminal state (F/I/C),
 * never both. CS-Cart's fn_finish_payment short-circuits on terminal
 * orders, so the retry path must bypass it.
 */
#[CoversClass(IpnHandler::class)]
final class IpnHandlerTransitionTest extends TestCase
{
    private const string POS_SIGNATURE = 'TEST-POS';

    private string $publicKeyPem;

    private string $privateKeyPem;

    private string $tmpKeysDir;

    #[Override]
    protected function setUp(): void
    {
        [$this->publicKeyPem, $this->privateKeyPem] = RsaTestFixtures::generateKeyPair();
        $this->tmpKeysDir = sys_get_temp_dir() . '/netopia_handler_' . bin2hex(random_bytes(6));
    }

    public function testFirstTimeFinalisationCallsFinalizerNotStatusChanger(): void
    {
        $finalizerCalls = [];
        $statusChangerCalls = [];
        $paymentInfoCalls = [];

        $handler = $this->buildHandler(
            currentOrderStatus: 'O',
            finalizerCalls:     $finalizerCalls,
            statusChangerCalls: $statusChangerCalls,
            paymentInfoCalls:   $paymentInfoCalls,
        );

        $body = $this->ipnBody(orderId: 42, status: 3);
        $token = $this->signJwt($body);

        $handler->handle($body, $token);

        self::assertCount(1, $finalizerCalls, 'paymentFinalizer must run exactly once for O → P');
        self::assertSame('P', $finalizerCalls[0]['response']['order_status']);
        self::assertSame([], $statusChangerCalls, 'orderStatusChanger must not run on the happy first-time path');
        self::assertCount(1, $paymentInfoCalls);
        self::assertSame(
            '42-1713600000123456',
            $paymentInfoCalls[0]['info']['netopia_order_id'] ?? null,
            'first-time IPN must persist the verified orderID so admin Payment ID reflects the attempt NETOPIA reported on',
        );
    }

    public function testRetryAgainstFailedOrderUsesStatusChangerWithReasonAndNotify(): void
    {
        $finalizerCalls = [];
        $statusChangerCalls = [];
        $paymentInfoCalls = [];

        $handler = $this->buildHandler(
            currentOrderStatus: 'F',
            finalizerCalls:     $finalizerCalls,
            statusChangerCalls: $statusChangerCalls,
            paymentInfoCalls:   $paymentInfoCalls,
        );

        $body = $this->ipnBody(orderId: 42, status: 3);
        $token = $this->signJwt($body);

        $handler->handle($body, $token);

        self::assertSame([], $finalizerCalls, 'paymentFinalizer must NOT run on retry — fn_finish_payment short-circuits on F');
        self::assertCount(1, $statusChangerCalls, 'orderStatusChanger must run exactly once on retry');
        self::assertSame(42, $statusChangerCalls[0]['order_id']);
        self::assertSame('P', $statusChangerCalls[0]['status']);
        self::assertStringContainsString('approved', strtolower($statusChangerCalls[0]['reason']));
        self::assertStringContainsString('status: 3', $statusChangerCalls[0]['reason']);
        self::assertTrue($statusChangerCalls[0]['notify'], 'customer must be notified about the payment finally clearing');
    }

    public function testRetryAgainstFailedOrderRefreshesPaymentInfoWithFreshOrderStatusAndReason(): void
    {
        // fn_change_order_status doesn't refresh payment_info on its own —
        // without these writes the admin panel keeps the previous attempt's
        // failure text after a successful retry.
        $finalizerCalls = [];
        $statusChangerCalls = [];
        $paymentInfoCalls = [];

        $handler = $this->buildHandler(
            currentOrderStatus: 'F',
            finalizerCalls:     $finalizerCalls,
            statusChangerCalls: $statusChangerCalls,
            paymentInfoCalls:   $paymentInfoCalls,
        );

        $body = $this->ipnBody(orderId: 42, status: 3);
        $token = $this->signJwt($body);

        $handler->handle($body, $token);

        self::assertCount(1, $paymentInfoCalls, 'paymentInfoUpdater must run once on retry');
        $info = $paymentInfoCalls[0]['info'];
        self::assertSame('P', $info['order_status'] ?? null, 'payment_info.order_status must be refreshed to the new CS-Cart status');
        self::assertNotEmpty($info['reason_text'] ?? '', 'payment_info.reason_text must be refreshed so admin no longer sees the previous failure');
        self::assertArrayNotHasKey('netopia_status', $info, 'legacy netopia_status field must not be written anymore');
        self::assertSame(
            '42-1713600000123456',
            $info['netopia_order_id'] ?? null,
            'retry IPN must overwrite stale netopia_order_id — otherwise admin Payment ID stays frozen at the last-generated (failed) attempt',
        );
    }

    public function testIpnNeverWritesLegacyNetopiaStatusField(): void
    {
        $finalizerCalls = [];
        $statusChangerCalls = [];
        $paymentInfoCalls = [];

        $handler = $this->buildHandler(
            currentOrderStatus: 'O',
            finalizerCalls:     $finalizerCalls,
            statusChangerCalls: $statusChangerCalls,
            paymentInfoCalls:   $paymentInfoCalls,
        );

        $body = $this->ipnBody(orderId: 42, status: 3);
        $token = $this->signJwt($body);

        $handler->handle($body, $token);

        self::assertCount(1, $paymentInfoCalls);
        self::assertArrayNotHasKey('netopia_status', $paymentInfoCalls[0]['info']);
    }

    public function testDeclinedFirstTimeWithInsufficientFundsMessageProducesFriendlyCustomerCopy(): void
    {
        // O → F path; an F → F retry would be skipped by the idempotency guard.
        $finalizerCalls = [];
        $statusChangerCalls = [];
        $paymentInfoCalls = [];

        $handler = $this->buildHandler(
            currentOrderStatus: 'O',
            finalizerCalls:     $finalizerCalls,
            statusChangerCalls: $statusChangerCalls,
            paymentInfoCalls:   $paymentInfoCalls,
        );

        $body = (string) json_encode([
            'order' => ['orderID' => '42-1713600000123456', 'currency' => 'RON'],
            'payment' => [
                'ntpID' => 'ntp-42',
                'status' => 12,
                'amount' => 330.91,
                'currency' => 'RON',
                'message' => 'Insufficient funds',
            ],
        ]);
        $token = $this->signJwt($body);

        $handler->handle($body, $token);

        self::assertCount(1, $finalizerCalls, 'first-time decline must finalise via fn_finish_payment');
        self::assertSame(
            'netopia_customer_msg_declined_insufficient_funds',
            $finalizerCalls[0]['response']['reason_text'] ?? null,
            'reason_text passed to fn_finish_payment must be the friendly insufficient-funds copy, not legacy NETOPIA jargon',
        );
        self::assertSame('F', $finalizerCalls[0]['response']['order_status']);
    }

    public function testIpnResolvesCsCartOrderIdFromSuffixedNetopiaOrderId(): void
    {
        // NETOPIA rejects re-use of orderID, so PayloadBuilder appends a
        // microsecond-precision suffix on every /payment/card/start call.
        // Verify the IpnHandler strips the suffix and still routes to the
        // CS-Cart order lookup correctly — regression guard against a
        // future "use Arr::int on orderID" refactor that would re-break it.
        $finalizerCalls = [];
        $statusChangerCalls = [];
        $paymentInfoCalls = [];

        $handler = $this->buildHandler(
            currentOrderStatus: 'O',
            finalizerCalls:     $finalizerCalls,
            statusChangerCalls: $statusChangerCalls,
            paymentInfoCalls:   $paymentInfoCalls,
        );

        $body = (string) json_encode([
            'order' => ['orderID' => '42-1713600000987654', 'currency' => 'RON'],
            'payment' => ['ntpID' => 'ntp-retry', 'status' => 3, 'amount' => 10.0, 'currency' => 'RON'],
        ]);
        $token = $this->signJwt($body);

        $handler->handle($body, $token);

        self::assertCount(1, $finalizerCalls, 'IpnHandler must have located CS-Cart order 42 from suffixed "42-..." id');
        self::assertSame(42, $finalizerCalls[0]['order_id']);
    }

    public function testSuccessfulRetryAfterFailedAttemptPersistsThePaidOrderIdNotTheStaleOne(): void
    {
        // Regression for devx.autoakt.ro order #45: attempt 1 generated
        // orderID "45-1776939722321635" (paid), attempt 2 generated
        // "45-1776939911165320" (failed). PaymentLinkService had overwritten
        // netopia_order_id with the second, failed id; when the IPN arrived
        // confirming the first id as PAID, the admin panel kept showing the
        // second, failed id because IpnHandler wasn't writing the verified
        // orderID back. This test locks in the fix.
        $finalizerCalls = [];
        $statusChangerCalls = [];
        $paymentInfoCalls = [];

        $handler = $this->buildHandler(
            currentOrderStatus: 'F',
            finalizerCalls:     $finalizerCalls,
            statusChangerCalls: $statusChangerCalls,
            paymentInfoCalls:   $paymentInfoCalls,
        );

        $paidOrderId = '42-1776939722321635';
        $body = (string) json_encode([
            'order' => ['orderID' => $paidOrderId, 'currency' => 'RON'],
            'payment' => ['ntpID' => 'ntp-paid', 'status' => 3, 'amount' => 330.91, 'currency' => 'RON'],
        ]);
        $token = $this->signJwt($body);

        $handler->handle($body, $token);

        self::assertCount(1, $paymentInfoCalls);
        self::assertSame(
            $paidOrderId,
            $paymentInfoCalls[0]['info']['netopia_order_id'] ?? null,
            'admin Payment ID must reflect the paid attempt, not the superseded failed one',
        );
    }

    public function testRefundIpnsAreAcknowledgedWithoutDbWrites(): void
    {
        // Refund IPNs are handled entirely by the synchronous admin path
        // (controllers/backend/netopia_refund.php) the moment
        // operation/credit returns 200. The trailing IPN that arrives
        // afterwards carries the original payment's ntpID (NETOPIA reuses
        // it for every credit) and `payment.amount = original payment
        // amount` — neither uniquely identifies a specific credit nor
        // exposes the credit delta — so any IPN-driven write would be
        // either a duplicate or an over-credit. The handler must
        // acknowledge with HTTP 200 and produce no side effects.
        $finalizerCalls = [];
        $statusChangerCalls = [];
        $paymentInfoCalls = [];

        $handler = $this->buildHandler(
            currentOrderStatus: 'P',
            finalizerCalls:     $finalizerCalls,
            statusChangerCalls: $statusChangerCalls,
            paymentInfoCalls:   $paymentInfoCalls,
            orderTotal:         500.0,
            paymentInfo:        [
                'transaction_id' => 'ntp-paid',
                'netopia_amount' => '500,00 RON',
            ],
        );

        $body = (string) json_encode([
            'order' => ['orderID' => '42-1713600000123456', 'currency' => 'RON'],
            'payment' => [
                'ntpID' => 'ntp-paid',
                'status' => PaymentStatus::Credit->value,
                'amount' => 500.0,
                'currency' => 'RON',
            ],
        ]);
        $token = $this->signJwt($body);

        $handler->handle($body, $token);

        self::assertSame([], $paymentInfoCalls, 'refund IPN must not rewrite payment_info — synchronous admin path is authoritative');
        self::assertSame([], $statusChangerCalls, 'refund IPN must not transition CS-Cart status — synchronous admin path already did');
        self::assertSame([], $finalizerCalls, 'refund IPN must never call fn_finish_payment');
    }

    public function testRefundIpnIsAcknowledgedEvenWhenOrderIsAlreadyBackordered(): void
    {
        // Regression guard for the original bug: previously, after the
        // first refund landed and `(ntpID)` was written into
        // `netopia_refund_log`, every subsequent refund IPN was skipped as
        // "duplicate refund ntpID" because NETOPIA reuses the original
        // payment's ntpID for every credit IPN. Under the new contract
        // the IPN is unconditionally acknowledged regardless of refund
        // history — refund recording is the synchronous admin path's
        // responsibility, not the IPN handler's.
        $finalizerCalls = [];
        $statusChangerCalls = [];
        $paymentInfoCalls = [];

        $handler = $this->buildHandler(
            currentOrderStatus: 'B',
            finalizerCalls:     $finalizerCalls,
            statusChangerCalls: $statusChangerCalls,
            paymentInfoCalls:   $paymentInfoCalls,
            paymentInfo:        [
                'transaction_id' => 'ntp-42',
                'netopia_refund_log' => '[2026-04-26 09:00] 100,00 RON — partial refund (ntp-42) [admin]',
            ],
        );

        $body = $this->ipnBody(orderId: 42, status: PaymentStatus::Credit->value);
        $token = $this->signJwt($body);

        $handler->handle($body, $token);

        self::assertSame([], $statusChangerCalls);
        self::assertSame([], $paymentInfoCalls);
        self::assertSame([], $finalizerCalls);
    }

    public function testIdempotentSkipWhenAlreadyInMatchingTerminalState(): void
    {
        $finalizerCalls = [];
        $statusChangerCalls = [];
        $paymentInfoCalls = [];

        $handler = $this->buildHandler(
            currentOrderStatus: 'P',
            finalizerCalls:     $finalizerCalls,
            statusChangerCalls: $statusChangerCalls,
            paymentInfoCalls:   $paymentInfoCalls,
        );

        $body = $this->ipnBody(orderId: 42, status: 3);
        $token = $this->signJwt($body);

        $handler->handle($body, $token);

        self::assertSame([], $finalizerCalls, 'duplicate IPN must not re-finalise');
        self::assertSame([], $statusChangerCalls, 'duplicate IPN must not re-transition');
    }

    /**
     * @param array<int, array<string, mixed>> $finalizerCalls
     * @param array<int, array<string, mixed>> $statusChangerCalls
     * @param array<int, array<string, mixed>> $paymentInfoCalls
     * @param array<string, mixed>             $paymentInfo  Pre-existing payment_info on the order (used for cumulative refund arithmetic).
     */
    private function buildHandler(
        string $currentOrderStatus,
        array &$finalizerCalls,
        array &$statusChangerCalls,
        array &$paymentInfoCalls,
        float $orderTotal = 330.91,
        array $paymentInfo = [],
    ): IpnHandler {
        $orderInfo = [
            'order_id' => 42,
            'payment_id' => 7,
            'status' => $currentOrderStatus,
            'total' => $orderTotal,
            'payment_info' => $paymentInfo,
        ];
        $processorData = [
            'processor_params' => [
                'pos_signature' => self::POS_SIGNATURE,
                'public_key' => $this->publicKeyPem,
                'mode' => 'sandbox',
            ],
        ];

        $statusMapper = new StatusMapper();
        $paymentInfoUpdater = static function (int $id, array $info) use (&$paymentInfoCalls): void {
            $paymentInfoCalls[] = ['order_id' => $id, 'info' => $info];
        };
        $orderStatusChanger = static function (int $id, string $status, string $reason, bool $notify) use (&$statusChangerCalls): void {
            $statusChangerCalls[] = ['order_id' => $id, 'status' => $status, 'reason' => $reason, 'notify' => $notify];
        };

        return new IpnHandler(
            verifier:            new IpnVerifier(),
            keyStorage:          new KeyStorage($this->tmpKeysDir),
            statusMapper:        $statusMapper,
            statusMessage:       new StatusMessage(
                translator: static fn (string $key): string => $key,
            ),
            orderLookup:         static fn (int $id): ?array => $id === 42 ? $orderInfo : null,
            processorDataLookup: static fn (int $id): ?array => $id === 7 ? $processorData : null,
            paymentInfoUpdater:  $paymentInfoUpdater,
            paymentFinalizer:    static function (int $id, array $response) use (&$finalizerCalls): void {
                $finalizerCalls[] = ['order_id' => $id, 'response' => $response];
            },
            orderStatusChanger:  $orderStatusChanger,
            responder:           static function (int $errorType, int $errorCode, string $message): void {
                // no-op
            },
        );
    }

    private function ipnBody(int $orderId, int $status): string
    {
        // Mirror the real wire format: NETOPIA echoes back the suffixed
        // orderID we sent on /payment/card/start.
        return (string) json_encode([
            'order' => ['orderID' => $orderId . '-1713600000123456', 'currency' => 'RON'],
            'payment' => ['ntpID' => 'ntp-' . $orderId, 'status' => $status, 'amount' => 330.91, 'currency' => 'RON'],
        ]);
    }

    private function signJwt(string $body): string
    {
        $header = RsaTestFixtures::base64UrlEncode((string) json_encode(['typ' => 'JWT', 'alg' => 'RS512']));
        $payload = RsaTestFixtures::base64UrlEncode((string) json_encode([
            'iss' => 'NETOPIA Payments',
            'aud' => self::POS_SIGNATURE,
            'sub' => base64_encode(hash('sha512', $body, true)),
        ]));

        $key = openssl_pkey_get_private($this->privateKeyPem);
        self::assertNotFalse($key);

        $signature = '';
        openssl_sign($header . '.' . $payload, $signature, $key, OPENSSL_ALGO_SHA512);

        return $header . '.' . $payload . '.' . RsaTestFixtures::base64UrlEncode($signature);
    }
}
