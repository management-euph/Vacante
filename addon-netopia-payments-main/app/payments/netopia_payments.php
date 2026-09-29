<?php

/**
 * NETOPIA Payments processor for CS-Cart 4.19.x
 *
 * Thin controller: delegates IPN/3DS callbacks to fn_netopia_handle_* wrappers
 * (which in turn delegate to Netopia\CsCart classes) and drives the initial
 * checkout flow using PayloadBuilder + ApiClient + ErrorCode/PaymentStatus enums.
 *
 * @package NetopiaPayments
 */

use Netopia\CsCart\Bootstrap;
use Netopia\CsCart\Ipn\IpnHandler;
use Netopia\CsCart\Session\ThreeDsSessionStore;
use Netopia\CsCart\Support\Arr;
use Netopia\CsCart\Support\Sanitizer;
use Netopia\Payment2\Enum\ErrorCode;
use Netopia\Payment2\Enum\PaymentMode;
use Netopia\Payment2\Enum\PaymentStatus;
use Tygh\Tygh;

if (!defined('BOOTSTRAP')) {
    die('Access denied');
}

// ---------------------------------------------------------------------------
// CALLBACK HANDLING (IPN, 3DS Return & Hosted-Page Return)
// ---------------------------------------------------------------------------
if (defined('PAYMENT_NOTIFICATION')) {
    if ($mode === 'notify') {
        fn_netopia_handle_ipn();
    } elseif ($mode === 'return') {
        if (!empty($_POST['paRes'])) {
            fn_netopia_handle_3ds_return();
        } else {
            fn_netopia_handle_hosted_return();
        }
    }
    exit;
}

// ---------------------------------------------------------------------------
// INITIAL PAYMENT PROCESSING (Checkout)
// ---------------------------------------------------------------------------

// Type-narrow CS-Cart scope-injected variables for PHPStan level 10
$processor_data = is_array($processor_data ?? null) ? $processor_data : [];
$order_info = Arr::stringKeys(is_array($order_info ?? null) ? $order_info : []);
$order_id = isset($order_id) && is_numeric($order_id) ? (int) $order_id : 0;

if (empty($processor_data) || empty($order_info)) {
    fn_set_notification('E', __('error'), __('netopia_configuration_error'));
    $pp_response = [
        'order_status' => 'F',
        'reason_text' => 'Missing processor or order data.',
    ];
    return;
}

$params = Arr::array($processor_data, 'processor_params');

if (empty($params['pos_signature']) || empty($params['api_key'])) {
    $pp_response = [
        'order_status' => 'F',
        'reason_text' => 'NETOPIA Payments is not configured. POS Signature and API Key are required.',
    ];
    return;
}

$paymentMode = PaymentMode::fromMixed($params['mode'] ?? null);

// Pre-flight: NETOPIA signs every IPN with JWT and we verify using the
// merchant's public key. If the key for the selected mode hasn't been
// uploaded (or pasted), the IPN handler will reject every callback with
// "Public key not configured" — the customer pays but the order stays stuck
// in "Open" because no IPN can finalise it. Fail fast here so the admin
// sees the problem on the first test order instead of after customers
// complain about stuck orders.
$paymentId = Arr::int($order_info, 'payment_id');
$publicKeyForMode = Bootstrap::instance()->keyStorage->load($params, 'public_key', $paymentId, $paymentMode);
if ($publicKeyForMode === '') {
    Bootstrap::instance()->logger->error(
        'NETOPIA checkout aborted: public key not configured for ' . $paymentMode->value
        . ' mode — upload or paste the ' . $paymentMode->value . '_public_key in the payment method settings',
        ['order_id' => $order_id, 'payment_id' => $paymentId, 'mode' => $paymentMode->value],
    );
    $pp_response = [
        'order_status' => 'F',
        'reason_text' => 'NETOPIA Payments is not fully configured: the ' . $paymentMode->value
            . ' public key is missing. IPN callbacks cannot be verified without it, so orders '
            . 'would otherwise remain stuck in "Open" after payment.',
    ];
    return;
}

$installments = 1;
if (!empty($params['allow_installments']) && $params['allow_installments'] === 'Y') {
    $maxInstallments = Arr::int($params, 'max_installments', 1);
    $rawInstallments = Arr::int(Arr::stringKeys($_POST), 'netopia_installments', 1);
    $selected = $rawInstallments;
    $installments = ($selected > 1 && $selected <= $maxInstallments) ? $selected : 1;
}

$bootstrap = Bootstrap::instance();

// PHPStan level 10 types superglobals as array<mixed> in included files;
// Arr::stringKeys narrows to array<string, mixed>.
$threeDs = $bootstrap->threeDsFactory->fromRequest(
    Arr::stringKeys($_SERVER),
    Arr::stringKeys($_POST),
);

$card = null; // Hosted-page flow: card details are entered on NETOPIA's website

try {
    $jsonRequest = $bootstrap->payloadBuilder->buildStartRequest(
        processorParams: $params,
        orderInfo:       $order_info,
        threeDs:         $threeDs,
        installments:    $installments,
        card:            $card,
    );
} catch (\Throwable $e) {
    $bootstrap->logger->error('NETOPIA payload build failed', ['error' => $e->getMessage()]);
    $pp_response = [
        'order_status' => 'F',
        'reason_text' => 'NETOPIA: failed to build payment request.',
    ];
    return;
}

// Extract the `orderID` we just put on the wire (CS-Cart id + retry suffix)
// so we can persist it alongside the transaction. This is the value NETOPIA's
// merchant dashboard shows as "ID tranzacție" — merchants match payments by it.
// Also extract `amount` + `currency` so we can persist the start-request charge
// (e.g. "68,70 EUR") for use as the refund cap basis. NETOPIA's
// /operation/credit cap is enforced against the original charge we sent to
// /payment/card/start, NOT against the post-FX amount echoed in the IPN
// (e.g. "361,78 RON" after EUR→RON conversion).
/** @var array{order?: array{orderID?: string, amount?: float|int|string, currency?: string}} $decodedRequest */
$decodedRequest = json_decode($jsonRequest, true, flags: JSON_THROW_ON_ERROR);
$decodedOrder = Arr::array($decodedRequest, 'order');
$netopiaOrderId = Arr::string($decodedOrder, 'orderID');
$startAmount = Arr::float($decodedOrder, 'amount');
$startCurrency = Arr::string($decodedOrder, 'currency');

$apiClient = $bootstrap->apiClientFor(Arr::string($params, 'api_key'), $paymentMode);

try {
    $response = $apiClient->post('payment/card/start', $jsonRequest);
} catch (\Throwable $e) {
    $bootstrap->logger->error('NETOPIA API request failed', ['error' => $e->getMessage()]);
    $pp_response = [
        'order_status' => 'F',
        'reason_text' => 'NETOPIA API error: ' . $e->getMessage(),
    ];
    return;
}

// Development logging with redacted card data
if (defined('DEVELOPMENT') && DEVELOPMENT) {
    $logData = $response->data ?? [];
    $logPayment = Arr::array($logData, 'payment');
    if (isset($logPayment['instrument'])) {
        $logPayment['instrument'] = '[hosted-page – no card data]';
        $logData['payment'] = $logPayment;
    }
    $bootstrap->logger->debug('NETOPIA start response', ['response' => $logData]);
}

if (!$response->isSuccess() || $response->data === null) {
    $pp_response = [
        'order_status' => 'F',
        'reason_text' => 'NETOPIA API error: ' . $response->message,
    ];
    return;
}

$errorBlock = $response->errorBlock();
$paymentData = $response->paymentData();
$customerAction = $response->customerAction();

$errorCode = Arr::string($errorBlock, 'code');
$ntpStatus = Arr::int($paymentData, 'status');
$ntpId = Arr::string($paymentData, 'ntpID');
$status = PaymentStatus::tryFrom($ntpStatus);

/** @var \ArrayAccess<string, mixed> $sessionContainer */
$sessionContainer = Tygh::$app['session'];
$threeDsSession = new ThreeDsSessionStore($sessionContainer);

$paymentInfoUpdate = [
    'transaction_id' => $ntpId,
    'netopia_order_id' => $netopiaOrderId,
    'netopia_start_amount' => IpnHandler::formatAmount($startAmount, $startCurrency),
];

// Dispatch based on (errorCode, status) combination
$flow = match (true) {
    ErrorCode::isThreeDsRequired($errorCode) && $ntpStatus === 15 => 'three_ds',
    ErrorCode::isHostedPage($errorCode) => 'hosted_page',
    ErrorCode::isApproved($errorCode) && $status?->isSuccessful() === true => 'approved',
    default => 'failed',
};

switch ($flow) {
    case 'three_ds':
        $authToken = Arr::string($customerAction, 'authenticationToken');
        $formData = Arr::array($customerAction, 'formData');
        $paReq = Arr::string($formData, 'paReq');
        $bankUrl = Arr::string($customerAction, 'url');

        if ($bankUrl === '' || $paReq === '' || !Sanitizer::isSafeHttpsUrl($bankUrl)) {
            $pp_response = [
                'order_status' => 'F',
                'reason_text' => 'NETOPIA: 3D Secure data incomplete or invalid bank URL.',
            ];
            return;
        }

        $paymentInfoUpdate['netopia_auth_token'] = $authToken;
        fn_update_order_payment_info($order_id, $paymentInfoUpdate);

        $threeDsSession->rememberOrder($order_id, Arr::int($order_info, 'payment_id'));

        fn_change_order_status($order_id, 'O', '', false);

        $returnUrl = fn_url('payment_notification.return?payment=netopia_payments', AREA, 'current');
        fn_create_payment_form($bankUrl, [
            'paReq' => $paReq,
            'MD' => '',
            'TermUrl' => $returnUrl,
        ], 'NETOPIA 3D Secure', false);
        exit;

    case 'hosted_page':
        $paymentUrl = Arr::string($paymentData, 'paymentURL');
        if (!Sanitizer::isSafeHttpsUrl($paymentUrl)) {
            $pp_response = [
                'order_status' => 'F',
                'reason_text' => 'NETOPIA: hosted page URL missing or insecure.',
            ];
            return;
        }

        $paymentInfoUpdate['netopia_payment_link'] = $paymentUrl;
        // European format matches NETOPIA's merchant dashboard (e.g. "23.04.2026 12:20:29")
        // so merchants can compare CS-Cart's payment info and NETOPIA's order details side by side.
        $paymentInfoUpdate['netopia_payment_link_at'] = $bootstrap->clock->now()->format('d.m.Y H:i:s');
        fn_update_order_payment_info($order_id, $paymentInfoUpdate);

        $threeDsSession->rememberOrder($order_id, Arr::int($order_info, 'payment_id'));

        fn_change_order_status($order_id, 'O', '', false);
        fn_redirect($paymentUrl, true);
        exit;

    case 'approved':
    case 'failed':
    default:
        // Approved and failed payments are recorded the same way: the status
        // mapper turns NETOPIA's status into the order status for both.
        fn_update_order_payment_info($order_id, $paymentInfoUpdate);
        $pp_response = [
            'order_status' => $bootstrap->statusMapper->map($ntpStatus, $params),
            'reason_text' => $bootstrap->statusMessage->forCustomer($status, Arr::string($errorBlock, 'message')),
            'transaction_id' => $ntpId,
        ];
        break;
}
