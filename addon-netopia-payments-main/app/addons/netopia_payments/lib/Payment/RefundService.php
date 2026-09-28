<?php

declare(strict_types=1);

namespace Netopia\CsCart\Payment;

use Netopia\CsCart\Dto\ApiResponse;
use Netopia\CsCart\Http\ApiPoster;
use Netopia\CsCart\Support\Arr;
use Netopia\Payment2\Enum\ErrorCode;
use Netopia\Payment2\Enum\PaymentStatus;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Calls the NETOPIA `operation/credit` endpoint to refund (credit) a
 * previously paid order. The actual order-side state changes (payment_info,
 * status transition, customer email) are handled by RefundFinalizer — this
 * service is only responsible for the outbound API call.
 *
 * Endpoint shape (per NETOPIA Payment v2 docs,
 * https://netopia-system.stoplight.io/docs/payments-api/7f3539148b5b7-create-a-operation-credit):
 *
 * POST /operation/credit
 * Authorization: <api_key>
 * Content-Type: application/json
 *
 * {
 *   "ntpID":  "<original payment ntpID>",
 *   "amount": 150.00,                      // JSON number, original payment's currency
 *   "split":  []                           // single-POS merchant — no split
 * }
 *
 * NETOPIA's `operation/credit` accepts no client-side idempotency key —
 * dedup against accidental duplicate POSTs is therefore enforced locally
 * by RefundAttemptStore's PRIMARY KEY on `request_id`. A transparent
 * network retry that nonetheless reached NETOPIA twice would result in
 * two refunds; the controller does not retry on its own, so real-world
 * exposure is small.
 *
 * Response on success: HTTP 200 with `error.code` of `0`/`00` (Approved)
 * and a `payment` block carrying `status = 8` (Credit). NETOPIA reuses
 * the original payment's ntpID across the credit operation — i.e. the
 * response and the trailing IPN both arrive with the SAME ntpID as the
 * original payment record. See `validateRefundResponse()` for the
 * approved-and-status-Credit guard against silent-success responses
 * (e.g. status 5/Confirmed echoed back, or non-Approved error codes).
 */
final class RefundService
{
    public const string ENDPOINT = 'operation/credit';

    public function __construct(
        private readonly ApiPoster $apiClient,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Issue a refund. Returns the parsed ApiResponse so callers can inspect
     * `isSuccess()` and `message`. The refund event's ntpID (needed by
     * RefundFinalizer) is read from the response data and exposed via
     * `refundNtpId()`.
     *
     * @param int    $orderId   CS-Cart order id (logged for support)
     * @param string $paymentId The original payment's ntpID (from
     *                           `payment_info.transaction_id`)
     * @param float  $amount    Refund amount in the original payment's currency
     */
    public function process(
        int $orderId,
        string $paymentId,
        float $amount,
    ): ApiResponse {
        if ($paymentId === '') {
            return ApiResponse::failure('Order has no NETOPIA payment id to refund against.');
        }
        if ($amount <= 0.0) {
            return ApiResponse::failure('Refund amount must be greater than zero.');
        }

        $body = [
            'ntpID' => $paymentId,
            'amount' => round($amount, 2),
            'split' => [],
        ];

        // PRESERVE_ZERO_FRACTION keeps `150.0` from collapsing to `150` on
        // the wire — the NETOPIA spec shows `amount` as a decimal number,
        // and a bare integer would round-trip back as `int` rather than
        // `float`. Mostly cosmetic, but cheap insurance against any
        // strict-typing on NETOPIA's side.
        $jsonBody = (string) json_encode(
            $body,
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION,
        );

        $this->logger->info('NETOPIA refund API call initiated', [
            'order_id' => $orderId,
            'payment_id' => $paymentId,
            'amount' => $amount,
        ]);

        try {
            $response = $this->apiClient->post(self::ENDPOINT, $jsonBody);
        } catch (Throwable $e) {
            $this->logger->warning('NETOPIA refund API call threw: ' . $e->getMessage(), [
                'order_id' => $orderId,
                'exception_class' => $e::class,
            ]);
            return ApiResponse::failure($e->getMessage());
        }

        if (!$response->isSuccess()) {
            return $response;
        }

        return $this->validateRefundResponse($response, $orderId, $amount);
    }

    /**
     * Three-way guard against NETOPIA "silent success" responses (HTTP
     * 200 with no actual credit performed):
     *
     *   1. `error.code` must be `0` or `00` (Approved). Anything else
     *      means NETOPIA refused the operation.
     *   2. `payment.status` must be 8 (Credit). Status 5 (Confirmed) or
     *      3 (Paid) means the original payment was returned untouched.
     *   3. `payment.amount` must equal the requested `$requestAmount`.
     *      Verified against order #60 (sandbox, 2026-04-29): a genuine
     *      credit returns `payment.amount = request.amount` (200 EUR
     *      requested → 200 echoed back). Order #64's 2nd and 3rd
     *      refund attempts (sandbox, 2026-05-01) approved locally but
     *      did NOT credit at the gateway, suggesting NETOPIA echoes
     *      a different amount (likely 0 or the original payment) when
     *      it refuses to credit further. Tolerance 0.005 absorbs
     *      float-encode round-trips.
     *
     * NOTE: `payment.ntpID` is NOT a useful signal — NETOPIA reuses
     * the original payment's ntpID across credit operations. A
     * request for `ntpID=X` returns `payment.ntpID=X` on success,
     * and the trailing IPN echoes the same X. An earlier
     * ntpID-must-differ check rejected genuine approvals; it has
     * been removed.
     *
     * Any failure short-circuits to ApiResponse::failure with a
     * descriptive message and a redacted body log so operators can
     * see exactly what NETOPIA returned.
     */
    private function validateRefundResponse(
        ApiResponse $response,
        int $orderId,
        float $requestAmount,
    ): ApiResponse {
        $payment = $response->paymentData();
        $errorBlock = $response->errorBlock();
        $errorCode = Arr::string($errorBlock, 'code');
        $responseNtpId = Arr::string($payment, 'ntpID');
        $responseStatus = Arr::int($payment, 'status');
        $responseAmount = Arr::float($payment, 'amount');

        $reason = null;
        if ($errorCode !== '' && !ErrorCode::isApproved($errorCode)) {
            $reason = 'NETOPIA refused the refund (error.code=' . $errorCode . '): '
                . Arr::string($errorBlock, 'message', 'no message');
        } elseif ($responseStatus !== 0 && $responseStatus !== PaymentStatus::Credit->value) {
            $reason = 'NETOPIA returned payment.status=' . $responseStatus
                . ' (expected 8/Credit) — refund was not applied at the gateway.';
        } elseif ($responseAmount > 0.0 && abs($responseAmount - $requestAmount) > 0.005) {
            // Tolerance 0.005 absorbs float-encode round-trips
            // (e.g. 27.0 → "27" → 27.0). The `> 0` guard skips the
            // check when NETOPIA omits the amount field entirely
            // (treat as inconclusive rather than failing).
            $reason = 'NETOPIA returned payment.amount=' . (string) $responseAmount
                . ' but we requested ' . (string) $requestAmount
                . ' — silent success, refund was not applied at the gateway.'
                . ' This typically happens when NETOPIA already credited an'
                . ' earlier overlapping amount and refuses to credit further.';
        }

        if ($reason === null) {
            return $response;
        }

        $this->logger->warning('NETOPIA refund response failed validation', [
            'order_id' => $orderId,
            'reason' => $reason,
            'error_code' => $errorCode,
            'response_ntp_id' => $responseNtpId,
            'response_status' => $responseStatus,
            'request_amount' => $requestAmount,
            'response_amount' => $responseAmount,
            'response_body' => self::redactedResponseBody($response),
        ]);

        // Suffix appended to the user-visible message (rendered by the
        // refund controller via fn_set_notification('E', ...)) so the
        // admin can paste the full body without digging through CS-Cart's
        // admin Logs UI. Bootstrap's FileLogger writes the same context
        // array as a JSON line to <store>/var/log/netopia_payments-<date>.log.
        $userFacingReason = $reason
            . ' Full response body logged to var/log/netopia_payments-'
            . date('Y-m-d') . '.log.';

        return ApiResponse::failure($userFacingReason, $response->code);
    }

    /**
     * Compact the parsed response data back to JSON for log lines, so
     * operators can see exactly what NETOPIA returned when validation
     * refuses a "successful" HTTP 200. Capped at 2000 chars to avoid
     * flooding the log on edge-case payloads.
     */
    private static function redactedResponseBody(ApiResponse $response): string
    {
        if ($response->data === null) {
            return '[no body]';
        }
        $encoded = json_encode($response->data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $encoded === false ? '[non-encodable]' : mb_substr($encoded, 0, 2000);
    }

    /**
     * Extract the refund event's ntpID from a successful refund response.
     * Returns an empty string when the response shape did not carry one
     * (caller should fall back to the original paymentId for audit trail).
     */
    public static function refundNtpId(ApiResponse $response): string
    {
        $payment = $response->paymentData();
        return Arr::string($payment, 'ntpID');
    }

    /**
     * Derive a stable refund-attempt id from per-attempt facts so accidental
     * duplicate clicks (admin double-click, transparent reload) collapse on
     * the local PRIMARY KEY in `refund_attempts`. Two genuinely-distinct
     * refunds against the same order differ on either `$refundIndex`
     * (sequencing) or `$amount`, so they always get distinct ids.
     *
     * Note: the id is NOT transmitted to NETOPIA — `operation/credit` does
     * not accept a client-side idempotency key. The id's only role is the
     * RefundAttemptStore PK that gates a second admin session.
     *
     * Format: `refund-<orderId>-<refundIndex>-<amountCents>` (≈25 chars,
     * human-readable, deterministic).
     */
    public static function deriveRequestId(int $orderId, int $refundIndex, float $amount): string
    {
        $cents = (int) round($amount * 100.0);
        return sprintf('refund-%d-%d-%d', $orderId, $refundIndex, $cents);
    }
}
