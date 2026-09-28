<?php

declare(strict_types=1);

namespace Netopia\CsCart\ThreeDs;

use Closure;
use Netopia\CsCart\Http\ApiClient;
use Netopia\CsCart\Payment\PayloadBuilder;
use Netopia\CsCart\Session\ThreeDsSessionStore;
use Netopia\CsCart\Status\StatusMapper;
use Netopia\CsCart\Status\StatusMessage;
use Netopia\CsCart\Support\Arr;
use Netopia\Payment2\Enum\PaymentMode;
use Netopia\Payment2\Enum\PaymentStatus;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Orchestrates the customer's return from the 3D Secure bank page.
 *
 * CS-Cart side-effects (status changes, notifications, redirects) are injected
 * as closures so the orchestration is framework-agnostic and unit-testable.
 *
 * @phpstan-type OrderLookup          \Closure(int): (array<string, mixed>|null)
 * @phpstan-type ProcessorDataLookup  \Closure(int): (array<string, mixed>|null)
 * @phpstan-type PaymentInfoUpdater   \Closure(int, array<string, mixed>): void
 * @phpstan-type PaymentFinalizer     \Closure(int, array<string, mixed>): void
 * @phpstan-type PlacementRouter      \Closure(int): void
 * @phpstan-type CheckoutRedirect     \Closure(string): void
 * @phpstan-type ApiClientFactory     \Closure(string, PaymentMode): ApiClient
 */
final class ThreeDsReturnHandler
{
    /**
     * @param OrderLookup         $orderLookup
     * @param ProcessorDataLookup $processorDataLookup
     * @param PaymentInfoUpdater  $paymentInfoUpdater
     * @param PaymentFinalizer    $paymentFinalizer
     * @param PlacementRouter     $placementRouter
     * @param CheckoutRedirect    $checkoutRedirect
     * @param ApiClientFactory    $apiClientFactory
     */
    public function __construct(
        private readonly ThreeDsSessionStore $session,
        private readonly PayloadBuilder $payloadBuilder,
        private readonly StatusMapper $statusMapper,
        private readonly StatusMessage $statusMessage,
        private readonly Closure $orderLookup,
        private readonly Closure $processorDataLookup,
        private readonly Closure $paymentInfoUpdater,
        private readonly Closure $paymentFinalizer,
        private readonly Closure $placementRouter,
        private readonly Closure $checkoutRedirect,
        private readonly Closure $apiClientFactory,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Handle the POSTed paRes from the bank.
     *
     * @param array<string, mixed> $post Typically $_POST
     */
    public function handle(array $post): void
    {
        $orderId = $this->session->orderId();
        if ($orderId <= 0) {
            ($this->checkoutRedirect)('netopia_3ds_session_expired');
            return;
        }

        $orderInfo = ($this->orderLookup)($orderId);
        if (!is_array($orderInfo) || $orderInfo === []) {
            ($this->checkoutRedirect)('netopia_order_not_found');
            return;
        }

        $processorData = ($this->processorDataLookup)(Arr::int($orderInfo, 'payment_id'));
        $params = is_array($processorData) ? Arr::array($processorData, 'processor_params') : [];
        if ($params === [] || Arr::string($params, 'api_key') === '') {
            $this->finalize($orderId, 'F', 'NETOPIA processor not configured (missing API key).', '');
            return;
        }

        $paRes = Arr::string($post, 'paRes');
        if ($paRes === '') {
            $this->finalize($orderId, 'F', '3D Secure authentication was not completed.', '');
            return;
        }

        $paymentInfo = Arr::array($orderInfo, 'payment_info');
        $authToken = Arr::string($paymentInfo, 'netopia_auth_token');
        $ntpId = Arr::string($paymentInfo, 'transaction_id');

        if ($authToken === '' || $ntpId === '') {
            $this->finalize($orderId, 'F', '3D Secure session data missing.', '');
            return;
        }

        $mode = PaymentMode::fromMixed($params['mode'] ?? null);
        $apiClient = ($this->apiClientFactory)(Arr::string($params, 'api_key'), $mode);

        try {
            $verifyJson = $this->payloadBuilder->buildVerifyAuthRequest($authToken, $ntpId, $paRes);
            $response = $apiClient->post('payment/card/verify-auth', $verifyJson);
        } catch (Throwable $e) {
            $this->logger->warning('NETOPIA VerifyAuth request failed', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
            $this->finalize($orderId, 'F', 'NETOPIA 3DS: connection error.', $ntpId);
            return;
        }

        if (!$response->isSuccess() || $response->data === null) {
            $this->finalize($orderId, 'F', 'NETOPIA 3DS: ' . $response->message, $ntpId);
            return;
        }

        $data = $response->data;
        $inner = Arr::array($data, 'data') !== [] ? Arr::array($data, 'data') : $data;
        $errorBlock = Arr::array($inner, 'error');
        $payment = Arr::array($inner, 'payment');

        $ntpStatus = Arr::int($payment, 'status');
        $verifyNtp = Arr::string($payment, 'ntpID', $ntpId);

        $status = PaymentStatus::tryFrom($ntpStatus);
        $netopiaMessage = Arr::string($errorBlock, 'message');

        ($this->paymentInfoUpdater)($orderId, [
            'transaction_id' => $verifyNtp,
        ]);

        $csStatus = $this->statusMapper->map($ntpStatus, $params);
        $reason = $this->statusMessage->forCustomer($status, $netopiaMessage);

        ($this->paymentFinalizer)($orderId, [
            'order_status' => $csStatus,
            'reason_text' => $reason,
            'transaction_id' => $verifyNtp,
        ]);

        $this->session->clear();
        ($this->placementRouter)($orderId);
    }

    private function finalize(int $orderId, string $csStatus, string $reason, string $transactionId): void
    {
        ($this->paymentFinalizer)($orderId, [
            'order_status' => $csStatus,
            'reason_text' => $reason,
            'transaction_id' => $transactionId,
        ]);
        ($this->placementRouter)($orderId);
    }
}
