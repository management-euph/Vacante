<?php

declare(strict_types=1);

namespace Netopia\CsCart\Ipn;

use Closure;
use Netopia\CsCart\Key\KeyStorage;
use Netopia\CsCart\Status\StatusMapper;
use Netopia\CsCart\Status\StatusMessage;
use Netopia\CsCart\Support\Arr;
use Netopia\Payment2\Enum\PaymentMode;
use Netopia\Payment2\Enum\PaymentStatus;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Orchestrates IPN callback handling.
 *
 * Reads the raw POST body, resolves the public key, verifies the JWT, and
 * updates CS-Cart order state through closures supplied by the bootstrap layer.
 * This keeps CS-Cart globals out of the class while preserving the hook contract.
 *
 * Closures are typed to match CS-Cart signatures but the handler itself is pure:
 * all state changes flow through the injected callables.
 *
 * @phpstan-type OrderLookup          \Closure(int): (array<string, mixed>|null)
 * @phpstan-type ProcessorDataLookup  \Closure(int): (array<string, mixed>|null)
 * @phpstan-type PaymentInfoUpdater   \Closure(int, array<string, mixed>): void
 * @phpstan-type PaymentFinalizer     \Closure(int, array<string, mixed>): void
 * @phpstan-type OrderStatusChanger   \Closure(int, string, string, bool): void
 * @phpstan-type Responder            \Closure(int, int, string): void
 */
final class IpnHandler
{
    /**
     * @param OrderLookup         $orderLookup
     * @param ProcessorDataLookup $processorDataLookup
     * @param PaymentInfoUpdater  $paymentInfoUpdater
     * @param PaymentFinalizer    $paymentFinalizer
     * @param OrderStatusChanger  $orderStatusChanger
     * @param Responder           $responder
     */
    public function __construct(
        private readonly IpnVerifier $verifier,
        private readonly KeyStorage $keyStorage,
        private readonly StatusMapper $statusMapper,
        private readonly StatusMessage $statusMessage,
        private readonly Closure $orderLookup,
        private readonly Closure $processorDataLookup,
        private readonly Closure $paymentInfoUpdater,
        private readonly Closure $paymentFinalizer,
        private readonly Closure $orderStatusChanger,
        private readonly Closure $responder,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Entry point. Reads raw POST body, resolves the public key, verifies the
     * JWT, and updates the CS-Cart order.
     */
    public function handle(string $rawPostBody, ?string $verificationToken): void
    {
        if ($rawPostBody === '' || !json_validate($rawPostBody)) {
            $this->respond(2, 1, 'Empty or invalid IPN payload');
            return;
        }

        /** @var array<string, mixed> $ipnRaw */
        $ipnRaw = json_decode($rawPostBody, true, flags: JSON_THROW_ON_ERROR);
        $orderRaw = Arr::array($ipnRaw, 'order');
        // NETOPIA sees `<cs_cart_id>-<retry_suffix>` — see PayloadBuilder for
        // why. (int) cast in PHP truncates at the first non-digit, so
        // "42-1713600000123456" → 42. Bare-integer payloads from legacy
        // stored transactions still decode correctly.
        $orderIdRaw = $orderRaw['orderID'] ?? null;
        $orderId = is_scalar($orderIdRaw) ? (int) $orderIdRaw : 0;

        if ($orderId <= 0) {
            $this->respond(2, 2, 'Missing order ID in IPN');
            return;
        }

        $orderInfo = ($this->orderLookup)($orderId);
        if (!is_array($orderInfo) || $orderInfo === []) {
            $this->respond(2, 3, 'Order not found');
            return;
        }

        $paymentId = Arr::int($orderInfo, 'payment_id');
        $processorData = ($this->processorDataLookup)($paymentId);
        $params = is_array($processorData) ? Arr::array($processorData, 'processor_params') : [];
        if ($params === []) {
            $this->respond(2, 4, 'Processor not configured');
            return;
        }

        $mode = PaymentMode::fromMixed($params['mode'] ?? null);
        $publicKey = $this->keyStorage->load($params, 'public_key', $paymentId, $mode);
        if ($publicKey === '') {
            // NETOPIA will keep retrying the IPN as long as this returns a
            // non-OK response, which means the order stays in its pre-IPN
            // status (typically "Open") for the merchant. Escalate to `error`
            // so the admin's log panel surfaces the misconfiguration instead
            // of just a warning buried in the log stream.
            $this->logger->error(
                'NETOPIA IPN rejected: public key not configured — order status will not advance until a '
                . $mode->value . '_public_key is uploaded or pasted in the payment method settings',
                [
                    'order_id' => $orderId,
                    'payment_id' => $paymentId,
                    'mode' => $mode->value,
                ],
            );
            $this->respond(2, 5, 'Public key not configured for ' . $mode->value . ' mode');
            return;
        }

        $result = $this->verifier->verify(
            publicKeyPem:        $publicKey,
            posSignature:        Arr::string($params, 'pos_signature'),
            rawPostBody:         $rawPostBody,
            verificationToken:   $verificationToken,
        );

        if (!$result['verified']) {
            $this->logger->warning('NETOPIA IPN verification failed', [
                'order_id' => $orderId,
                'error' => $result['error'],
            ]);
            $this->respond(2, 6, 'IPN verification failed: ' . $result['error']);
            return;
        }

        $payload = Arr::array($result, 'payload');
        $payment = Arr::array($payload, 'payment');
        $orderSection = Arr::array($payload, 'order');
        $ntpStatus = Arr::int($payment, 'status');
        $ntpId = Arr::string($payment, 'ntpID');
        $amount = Arr::float($payment, 'amount');
        $currency = Arr::string($payment, 'currency');
        // Debug-level dump of the full verified IPN payload (with PII /
        // card-data redacted) for forensic visibility — NETOPIA's
        // documented IPN shape changes between operation types and
        // sandbox vs prod, and the only way to know what fields a
        // credit IPN actually carries (vs what we read into $amount /
        // $currency above) is to log it once and inspect. Cheap: one
        // line per IPN, gated to debug to keep info-level logs clean.
        $this->logger->debug('NETOPIA IPN payload (verified)', [
            'order_id' => $orderId,
            'payload' => self::redactPayloadForLog($payload),
        ]);
        $netopiaMessage = Arr::string($payment, 'message');
        // Pulled from the JWT-verified payload (not the pre-verification
        // $orderRaw used for routing). Persisted below so the admin
        // "Payment ID" field reflects whichever attempt NETOPIA just
        // reported on — otherwise a successful retry after a failed
        // attempt would keep displaying the last-generated orderID
        // (written by PaymentLinkService at each /payment/card/start),
        // not the one that actually paid.
        $netopiaOrderId = Arr::string($orderSection, 'orderID');
        if ($currency === '') {
            $currency = Arr::string($orderSection, 'currency');
        }
        $statusEnum = PaymentStatus::tryFrom($ntpStatus);
        $isRefundIpn = $statusEnum?->group() === 'refund';
        $csStatus = $this->statusMapper->map($ntpStatus, $params);

        // Refund IPNs are acknowledged without DB writes. The synchronous
        // admin path in `controllers/backend/netopia_refund.php` records
        // refunds the moment `operation/credit` returns 200 (with the
        // admin-form amount and the credit-response ntpID); the trailing
        // IPN that arrives later carries no per-credit identifier and its
        // `payment.amount` is the original payment amount — not the credit
        // delta — so an IPN-driven `RefundFinalizer::apply` would record
        // the wrong amount. NETOPIA also reuses the original payment's
        // ntpID for every credit IPN against the same payment, so there
        // is no payload-side signal to distinguish trailing-of-refund-1
        // from trailing-of-refund-2 from delivery-retry. The branch below
        // logs an ack and falls through to the existing OK responder.
        //
        // Non-refund IPNs: a same-status IPN is always a true duplicate retry
        // for P/F/I terminal states.
        $currentStatus = Arr::string($orderInfo, 'status');
        // Every verified IPN leaves a trace. Short-circuit paths below
        // respond OK to NETOPIA without any further log entry; without
        // this info line the merchant cannot tell whether a "missing
        // transition" is caused by network loss, signature rejection,
        // or silent idempotency skip.
        $this->logger->info('NETOPIA IPN received', [
            'order_id' => $orderId,
            'ntp_id' => $ntpId,
            'ntp_status' => $ntpStatus,
            'mapped_cs_status' => $csStatus,
            'current_cs_status' => $currentStatus,
            'amount' => $amount,
        ]);

        if (!$isRefundIpn && $currentStatus === $csStatus && in_array($currentStatus, ['P', 'F', 'I'], true)) {
            $this->logger->info('NETOPIA IPN skipped: order already in matching terminal state', [
                'order_id' => $orderId,
                'current_cs_status' => $currentStatus,
            ]);
            $this->respond(1, 0, 'OK (already processed)');
            return;
        }

        if ($isRefundIpn) {
            // Synchronous admin refund path is authoritative; this IPN
            // arrives after RefundFinalizer has already run with the
            // correct credit delta. Acknowledge and respond OK.
            $this->logger->info('NETOPIA refund IPN acknowledged', [
                'order_id' => $orderId,
                'ntp_id' => $ntpId,
            ]);
            $this->respond(1, 0, 'OK');
            return;
        }

        $customerReason = $this->statusMessage->forCustomer($statusEnum, $netopiaMessage);
        $adminReason = $this->statusMessage->forAdmin($statusEnum, $ntpStatus, $netopiaMessage);

        // First-time finalisation goes through fn_finish_payment so CS-Cart
        // runs its full post-checkout pipeline (profit, point credit, etc.).
        // For a retry IPN against an already-terminal order (F/I/C) we cannot
        // reuse fn_finish_payment — it short-circuits — so issue a single
        // direct status transition with an audit reason. This produces one
        // F → P row in the order log instead of a confusing F → O → P.
        if (in_array($currentStatus, ['O', 'N'], true)) {
            ($this->paymentInfoUpdater)($orderId, [
                'transaction_id' => $ntpId,
                'netopia_amount' => self::formatAmount($amount, $currency),
                'netopia_order_id' => $netopiaOrderId,
            ]);
            ($this->paymentFinalizer)($orderId, [
                'order_status' => $csStatus,
                'reason_text' => $customerReason,
                'transaction_id' => $ntpId,
            ]);
        } else {
            // fn_change_order_status does NOT refresh payment_info beyond what
            // we explicitly write here — without `order_status` and
            // `reason_text` the admin Payment Information panel keeps
            // displaying the previous attempt's failure text after a
            // successful retry.
            ($this->paymentInfoUpdater)($orderId, [
                'transaction_id' => $ntpId,
                'netopia_amount' => self::formatAmount($amount, $currency),
                'order_status' => $csStatus,
                'reason_text' => $customerReason,
                'netopia_order_id' => $netopiaOrderId,
            ]);
            ($this->orderStatusChanger)($orderId, $csStatus, $adminReason, true);
        }

        $this->respond(1, 0, 'OK');
    }

    private function respond(int $errorType, int $errorCode, string $message): void
    {
        ($this->responder)($errorType, $errorCode, $message);
    }

    /**
     * Compact, redacted JSON of an IPN payload for debug-level logging.
     * Strips card data and customer PII while preserving the
     * amount/currency/status/ntpID fields the support flow relies on.
     * Mirrors ApiClient::redactResponseBody so prod logs don't leak
     * sensitive blobs even when a junior operator turns on debug.
     *
     * @param array<array-key, mixed> $payload
     */
    private static function redactPayloadForLog(array $payload): string
    {
        if (isset($payload['payment']) && is_array($payload['payment'])) {
            if (isset($payload['payment']['instrument'])) {
                $payload['payment']['instrument'] = '[redacted]';
            }
            if (isset($payload['payment']['data'])) {
                $payload['payment']['data'] = '[redacted]';
            }
        }
        if (isset($payload['order']) && is_array($payload['order'])) {
            foreach (['billing', 'shipping'] as $addressKey) {
                if (isset($payload['order'][$addressKey])) {
                    $payload['order'][$addressKey] = '[redacted]';
                }
            }
        }
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $encoded === false ? '[non-encodable]' : $encoded;
    }

    /**
     * Format the IPN amount using Romanian locale conventions — comma decimal,
     * dot thousands — to match NETOPIA's merchant dashboard. Leaves out the
     * currency code when NETOPIA did not send one so we never produce
     * misleading bare-number output.
     */
    public static function formatAmount(float $amount, string $currency): string
    {
        $formatted = number_format($amount, 2, ',', '.');
        return $currency === '' ? $formatted : $formatted . ' ' . $currency;
    }

    /**
     * Parse a display string produced by self::formatAmount back to a float.
     * Tolerates the European convention formatAmount emits (dot thousands,
     * comma decimal, optional " CURRENCY" suffix) so the cumulative refund
     * arithmetic can round-trip through payment_info without losing precision.
     */
    public static function parseAmount(string $formatted): float
    {
        // Keep only digits, dots, and commas (drops currency + whitespace).
        $numeric = preg_replace('/[^0-9.,]/', '', $formatted) ?? '';
        // Dots are thousands separators — remove. Comma is the decimal
        // separator — normalise to dot for PHP's float cast.
        $numeric = str_replace(['.', ','], ['', '.'], $numeric);
        return (float) $numeric;
    }

    /**
     * Round-trip self::formatAmount back into its components: the float
     * value and the (optional) trailing 3-letter currency code. Used by the
     * admin refund flow to drive the UI in NETOPIA's actual processing
     * currency rather than CS-Cart's display currency, which can differ.
     *
     * @return array{value: float, currency: string}
     */
    public static function parseFormattedAmount(string $formatted): array
    {
        $trimmed = trim($formatted);
        if (preg_match('/^(.+?)\s+([A-Z]{3})$/', $trimmed, $m)) {
            return ['value' => self::parseAmount($m[1]), 'currency' => $m[2]];
        }
        return ['value' => self::parseAmount($trimmed), 'currency' => ''];
    }
}
