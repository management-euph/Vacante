<?php

declare(strict_types=1);

namespace Netopia\CsCart\Tests\Payment;

use Netopia\CsCart\Dto\ApiResponse;
use Netopia\CsCart\Http\ApiPoster;
use Netopia\CsCart\Payment\RefundService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RefundService::class)]
final class RefundServiceTest extends TestCase
{
    public function testProcessSendsExpectedJsonShapeToTheRefundEndpoint(): void
    {
        $captured = [];
        $service = new RefundService($this->fakePoster($captured, ApiResponse::fromHttp(200, ['payment' => ['ntpID' => 'ntp-refund-1']])));

        $service->process(
            orderId:   42,
            paymentId: 'ntp-paid',
            amount:    150.0,
        );

        self::assertSame('operation/credit', $captured['endpoint']);
        self::assertSame(RefundService::ENDPOINT, $captured['endpoint']);
        $body = json_decode($captured['body'], true);
        self::assertIsArray($body);
        self::assertSame('ntp-paid', $body['ntpID']);
        self::assertSame(150.0, $body['amount'], 'amount must be a JSON number, not a fixed-2-decimals string');
        self::assertSame([], $body['split']);
        self::assertArrayNotHasKey('paymentId', $body, 'old body shape must not be sent — endpoint expects ntpID');
        self::assertArrayNotHasKey('refundRequestId', $body, 'operation/credit does not accept a client-side idempotency key');
        self::assertArrayNotHasKey('currency', $body, 'operation/credit refunds in the original payment currency — currency is NOT in the body');
        self::assertArrayNotHasKey('reason', $body);
    }

    public function testProcessRejectsMissingPaymentIdWithoutCallingTheApi(): void
    {
        $captured = [];
        $service = new RefundService($this->fakePoster($captured, ApiResponse::fromHttp(200, [])));

        $response = $service->process(
            orderId:   42,
            paymentId: '',
            amount:    150.0,
        );

        self::assertFalse($response->isSuccess());
        self::assertSame([], $captured, 'no API call must be made when paymentId is missing');
    }

    public function testProcessRejectsZeroOrNegativeAmount(): void
    {
        $captured = [];
        $service = new RefundService($this->fakePoster($captured, ApiResponse::fromHttp(200, [])));

        $response = $service->process(42, 'ntp-paid', 0.0);

        self::assertFalse($response->isSuccess());
        self::assertSame([], $captured);
    }

    public function testHttpErrorPropagatesAsApiResponseFailure(): void
    {
        $captured = [];
        $service = new RefundService($this->fakePoster($captured, ApiResponse::fromHttp(400, ['errorMessage' => 'Refund window expired'])));

        $response = $service->process(42, 'ntp-paid', 150.0);

        self::assertFalse($response->isSuccess());
        self::assertStringContainsString('Refund window expired', $response->message);
    }

    public function testProcessAcceptsApprovedCreditEvenWhenNtpIdMatchesRequest(): void
    {
        // NETOPIA reuses the original payment's ntpID across credit
        // operations: a request with `ntpID=X` returns `payment.ntpID=X`
        // on success, and the trailing IPN echoes the same X. Verified
        // against sandbox responses (sandbox log 2026-04-29 18:15:47).
        // Earlier code rejected this as "silent success"; that check
        // was wrong and is removed. error.code=00 + status=8 is the
        // authoritative success signal.
        $captured = [];
        $approvedSameNtpId = ApiResponse::fromHttp(200, [
            'error' => ['code' => '00', 'message' => '[TEST P] Approved'],
            'payment' => ['ntpID' => 'ntp-paid', 'status' => 8, 'amount' => 200],
        ]);
        $service = new RefundService($this->fakePoster($captured, $approvedSameNtpId));

        $response = $service->process(42, 'ntp-paid', 200.0);

        self::assertTrue(
            $response->isSuccess(),
            'response with matching ntpID + status=8 + error.code=00 is a real refund — must not be rejected',
        );
    }

    public function testProcessRefusesSilentSuccessWhenResponseAmountDoesNotMatchRequest(): void
    {
        // Order #64's 2nd and 3rd refund attempts (sandbox 2026-05-01)
        // returned error.code=00 + status=8 but did not actually credit
        // at the gateway. Genuine credits return payment.amount equal to
        // the requested amount (verified order #60: 200 EUR requested →
        // payment.amount=200). A mismatch (likely 0 or the original
        // payment amount) is the actually-correct silent-success signal.
        $captured = [];
        $silentSuccess = ApiResponse::fromHttp(200, [
            'error' => ['code' => '00', 'message' => '[TEST P] Approved'],
            // Requested 15, NETOPIA echoes the original 333.68 — refuses
            // to credit further because the prior refund already exhausted
            // whatever sandbox-side allowance applies.
            'payment' => ['ntpID' => 'ntp-paid', 'status' => 8, 'amount' => 333.68],
        ]);
        $service = new RefundService($this->fakePoster($captured, $silentSuccess));

        $response = $service->process(64, 'ntp-paid', 15.0);

        self::assertFalse($response->isSuccess());
        self::assertStringContainsString('payment.amount=333.68', $response->message);
        self::assertStringContainsString('we requested 15', $response->message);
        self::assertStringContainsString('logged to var/log/netopia_payments-', $response->message);
    }

    public function testProcessAcceptsResponseWithMissingAmountField(): void
    {
        // Some legacy/edge response shapes omit payment.amount entirely.
        // We treat that as inconclusive (skip the amount check) rather
        // than rejecting an otherwise-valid Approved + status=8 response.
        $captured = [];
        $noAmount = ApiResponse::fromHttp(200, [
            'error' => ['code' => '00'],
            'payment' => ['ntpID' => 'ntp-paid', 'status' => 8],
        ]);
        $service = new RefundService($this->fakePoster($captured, $noAmount));

        $response = $service->process(42, 'ntp-paid', 50.0);

        self::assertTrue($response->isSuccess());
    }

    public function testProcessRefusesResponseWithNonApprovedErrorCode(): void
    {
        $captured = [];
        $errored = ApiResponse::fromHttp(200, [
            'payment' => ['ntpID' => 'ntp-refund-1', 'status' => 8],
            'error' => ['code' => '56', 'message' => 'Generic error'],
        ]);
        $service = new RefundService($this->fakePoster($captured, $errored));

        $response = $service->process(42, 'ntp-paid', 150.0);

        self::assertFalse($response->isSuccess());
        self::assertStringContainsString('error.code=56', $response->message);
        self::assertStringContainsString('Generic error', $response->message);
        self::assertStringContainsString('logged to var/log/netopia_payments-', $response->message);
    }

    public function testProcessRefusesResponseWithNonCreditPaymentStatus(): void
    {
        // Even with a fresh ntpID and an approved error.code, a payment.status
        // other than 8 (Credit) means the refund didn't take effect — most
        // likely the original Confirmed (5) or Paid (3) record was returned.
        $captured = [];
        $wrongStatus = ApiResponse::fromHttp(200, [
            'payment' => ['ntpID' => 'ntp-refund-1', 'status' => 5],
            'error' => ['code' => '0'],
        ]);
        $service = new RefundService($this->fakePoster($captured, $wrongStatus));

        $response = $service->process(42, 'ntp-paid', 150.0);

        self::assertFalse($response->isSuccess());
        self::assertStringContainsString('payment.status=5', $response->message);
        self::assertStringContainsString('logged to var/log/netopia_payments-', $response->message);
    }

    public function testProcessAcceptsResponseWithCreditStatusAndDifferentNtpId(): void
    {
        $captured = [];
        $valid = ApiResponse::fromHttp(200, [
            'payment' => ['ntpID' => 'ntp-refund-1', 'status' => 8],
            'error' => ['code' => '0', 'message' => 'Approved'],
        ]);
        $service = new RefundService($this->fakePoster($captured, $valid));

        $response = $service->process(42, 'ntp-paid', 150.0);

        self::assertTrue($response->isSuccess());
    }

    public function testRefundNtpIdExtractsNtpIdFromPaymentBlock(): void
    {
        $response = ApiResponse::fromHttp(200, ['payment' => ['ntpID' => 'ntp-refund-2']]);

        self::assertSame('ntp-refund-2', RefundService::refundNtpId($response));
    }

    public function testRefundNtpIdHandlesNestedDataEnvelope(): void
    {
        $response = ApiResponse::fromHttp(200, ['data' => ['payment' => ['ntpID' => 'ntp-refund-3']]]);

        self::assertSame('ntp-refund-3', RefundService::refundNtpId($response));
    }

    public function testDeriveRequestIdIsStablePerOrderIndexAmount(): void
    {
        // Identical inputs must produce identical ids — that's the dedup
        // contract the local RefundAttemptStore PRIMARY KEY relies on.
        self::assertSame(
            RefundService::deriveRequestId(42, 0, 150.00),
            RefundService::deriveRequestId(42, 0, 150.00),
        );
        self::assertSame('refund-42-0-15000', RefundService::deriveRequestId(42, 0, 150.00));
    }

    public function testDeriveRequestIdRoundsAmountToCents(): void
    {
        // Float rounding: 150.001 vs 150.00 should land on the same id so a
        // double-click that re-encodes the float doesn't accidentally mint
        // a fresh id and bypass the local PK dedup.
        self::assertSame(
            'refund-42-0-15000',
            RefundService::deriveRequestId(42, 0, 150.001),
        );
        self::assertSame(
            'refund-42-0-15000',
            RefundService::deriveRequestId(42, 0, 149.999),
        );
    }

    public function testDeriveRequestIdDifferentiatesOnIndexAndAmount(): void
    {
        // Two genuinely-distinct refunds (different sequence position OR
        // different amount) must get distinct ids so each is processed
        // independently rather than collapsed by the dedup PK.
        $first  = RefundService::deriveRequestId(42, 0, 150.00);
        $second = RefundService::deriveRequestId(42, 1, 150.00);
        $thirdSameIndexDifferentAmount = RefundService::deriveRequestId(42, 0, 50.00);

        self::assertNotSame($first, $second);
        self::assertNotSame($first, $thirdSameIndexDifferentAmount);
        self::assertNotSame($second, $thirdSameIndexDifferentAmount);
    }

    /**
     * @param array<string, string> $captured
     */
    private function fakePoster(array &$captured, ApiResponse $response): ApiPoster
    {
        return new class ($captured, $response) implements ApiPoster {
            /**
             * @param array<string, string> $captured
             */
            public function __construct(
                private array &$captured,
                private readonly ApiResponse $response,
            ) {
            }

            #[\Override]
            public function post(string $endpoint, string $jsonBody): ApiResponse
            {
                $this->captured = ['endpoint' => $endpoint, 'body' => $jsonBody];
                return $this->response;
            }
        };
    }
}
