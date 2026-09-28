<?php

declare(strict_types=1);

namespace Netopia\CsCart\Payment;

use Closure;
use Netopia\CsCart\Http\ApiClient;
use Netopia\CsCart\Ipn\IpnHandler;
use Netopia\CsCart\Support\Arr;
use Netopia\CsCart\Support\Sanitizer;
use Netopia\CsCart\ThreeDs\ThreeDsDataFactory;
use Netopia\Payment2\Enum\ErrorCode;
use Netopia\Payment2\Enum\PaymentMode;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Generates NETOPIA hosted payment links for existing orders.
 *
 * @phpstan-type LinkResult array{success: bool, payment_url: string, error: string}
 */
final class PaymentLinkService
{
    /**
     * @param Closure(string $apiKey, PaymentMode $mode): ApiClient $apiClientFactory
     * @param Closure(int $orderId, array<string, mixed> $info): void $paymentInfoUpdater
     */
    public function __construct(
        private readonly PayloadBuilder $payloadBuilder,
        private readonly ThreeDsDataFactory $threeDsFactory,
        private readonly Closure $apiClientFactory,
        private readonly Closure $paymentInfoUpdater,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Generate a payment link for an already-placed order.
     *
     * @param array<string, mixed> $orderInfo       CS-Cart order data
     * @param array<string, mixed> $processorParams Payment processor params
     * @param array<string, mixed> $server          Typically $_SERVER (for 3DS fingerprint)
     * @return LinkResult
     */
    public function generate(array $orderInfo, array $processorParams, array $server): array
    {
        if (empty($processorParams['pos_signature']) || empty($processorParams['api_key'])) {
            return $this->failure('NETOPIA POS Signature or API Key missing.');
        }

        $orderId = Arr::int($orderInfo, 'order_id');
        if ($orderId <= 0) {
            return $this->failure('Invalid order ID.');
        }

        $mode = PaymentMode::fromMixed($processorParams['mode'] ?? null);
        $installments = $this->resolveInstallments($processorParams);
        $threeDs = $this->threeDsFactory->forServerContext($server);

        try {
            $json = $this->payloadBuilder->buildStartRequest($processorParams, $orderInfo, $threeDs, $installments, null);
            $apiClient = ($this->apiClientFactory)(Arr::string($processorParams, 'api_key'), $mode);
            $response = $apiClient->post('payment/card/start', $json);
        } catch (Throwable $e) {
            $this->logger->warning('NETOPIA payment link request failed', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
            return $this->failure('NETOPIA API error: ' . $e->getMessage());
        }

        if (!$response->isSuccess() || $response->data === null) {
            return $this->failure('NETOPIA API error: ' . $response->message);
        }

        // Recover the `orderID` we just put on the wire (CS-Cart id + retry
        // suffix) so we can persist it. This is what NETOPIA's merchant
        // dashboard shows as "ID tranzacție". Also recover the start-request
        // `amount` + `currency` so we can persist the original charge as the
        // refund cap basis — NETOPIA's /operation/credit cap is enforced
        // against this value, not against the post-FX amount returned in the
        // IPN.
        /** @var array{order?: array{orderID?: string, amount?: float|int|string, currency?: string}} $decodedRequest */
        $decodedRequest = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $decodedOrder = Arr::array($decodedRequest, 'order');
        $netopiaOrderId = Arr::string($decodedOrder, 'orderID');
        $startAmount = Arr::float($decodedOrder, 'amount');
        $startCurrency = Arr::string($decodedOrder, 'currency');

        $data = $response->data;
        $inner = Arr::array($data, 'data') ?: $data;
        $errorBlock = Arr::array($inner, 'error');
        $payment = Arr::array($inner, 'payment');
        $errorCode = Arr::string($errorBlock, 'code');
        $paymentUrl = Arr::string($payment, 'paymentURL');
        $ntpId = Arr::string($payment, 'ntpID');

        if (ErrorCode::isHostedPage($errorCode) && Sanitizer::isSafeHttpsUrl($paymentUrl)) {
            ($this->paymentInfoUpdater)($orderId, [
                'netopia_payment_link' => $paymentUrl,
                // European format matches NETOPIA's merchant dashboard so
                // merchants can align CS-Cart's payment_info with NETOPIA's
                // "Data inițierii" / "Data modificării" at a glance.
                'netopia_payment_link_at' => date('d.m.Y H:i:s'),
                'transaction_id' => $ntpId,
                'netopia_order_id' => $netopiaOrderId,
                'netopia_start_amount' => IpnHandler::formatAmount($startAmount, $startCurrency),
            ]);

            return ['success' => true, 'payment_url' => $paymentUrl, 'error' => ''];
        }

        $errorMsg = Arr::string($errorBlock, 'message', 'Unexpected response (code: ' . $errorCode . ')');
        return $this->failure($errorMsg);
    }

    /**
     * @param array<string, mixed> $processorParams
     */
    private function resolveInstallments(array $processorParams): int
    {
        if (($processorParams['allow_installments'] ?? '') !== 'Y') {
            return 1;
        }
        return max(1, Arr::int($processorParams, 'max_installments', 1));
    }

    /**
     * @return LinkResult
     */
    private function failure(string $message): array
    {
        return ['success' => false, 'payment_url' => '', 'error' => $message];
    }
}
