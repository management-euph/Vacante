<?php

declare(strict_types=1);

namespace Netopia\CsCart\Payment;

use Closure;
use Netopia\CsCart\Ipn\IpnHandler;
use Netopia\CsCart\Support\Arr;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Sends the NETOPIA refund-notification email to the customer when a
 * refund IPN (status 8, Credit) is processed. Mirrors PaymentLinkEmailSender
 * structurally; the `netopia_refund_notification` template_emails row is
 * imported from addon.xml <email_templates> on install.
 *
 * Caller is responsible for suppressing CS-Cart's generic status-change
 * email (by passing notify=false to fn_change_order_status) so the
 * customer receives exactly one refund message.
 */
final class RefundEmailSender
{
    /**
     * @param Closure(array<string, mixed> $config, string $langCode): bool $mailSender
     *   Dispatches via CS-Cart's mailer with area 'C' (customer). See
     *   PaymentLinkEmailSender::__construct for the same contract.
     */
    public function __construct(
        private readonly Closure $mailSender,
        private readonly string $companyName,
        private readonly string $primaryCurrency,
        private readonly string $fallbackLangCode,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @param array<string, mixed> $orderInfo
     */
    public function send(
        array $orderInfo,
        float $refundAmount,
        float $cumulativeRefunded,
        float $originalAmount,
        string $currency,
        string $ntpId,
        bool $isFullRefund,
    ): bool {
        $orderId = Arr::int($orderInfo, 'order_id');

        $email = Arr::string($orderInfo, 'email');
        if ($email === '') {
            $this->logger->warning('NETOPIA refund email skipped: order has no customer email', [
                'order_id' => $orderId,
            ]);
            return false;
        }

        $customerName = trim(Arr::string($orderInfo, 'b_firstname') . ' ' . Arr::string($orderInfo, 'b_lastname'));
        if ($customerName === '') {
            $customerName = $email;
        }
        if ($currency === '') {
            $currency = Arr::string($orderInfo, 'secondary_currency', $this->primaryCurrency);
        }
        $langCode = Arr::string($orderInfo, 'lang_code', $this->fallbackLangCode);

        $remainingPaid = max(0.0, $originalAmount - $cumulativeRefunded);

        $config = [
            'to' => $email,
            'from' => 'default_company_orders_department',
            'data' => [
                'customer_name' => $customerName,
                'order_id' => $orderId,
                'refund_amount' => IpnHandler::formatAmount($refundAmount, $currency),
                'cumulative_refunded' => IpnHandler::formatAmount($cumulativeRefunded, $currency),
                'original_amount' => IpnHandler::formatAmount($originalAmount, $currency),
                'remaining_paid' => IpnHandler::formatAmount($remainingPaid, $currency),
                'ntp_id' => $ntpId,
                'refund_date' => date('d.m.Y H:i'),
                'is_full_refund' => $isFullRefund,
                'company_name' => $this->companyName,
                'order_info' => $orderInfo,
            ],
            'template_code' => 'netopia_refund_notification',
        ];

        try {
            $sent = (bool) ($this->mailSender)($config, $langCode);
        } catch (Throwable $e) {
            $this->logger->error('NETOPIA refund email failed: ' . $e->getMessage(), [
                'order_id' => $orderId,
                'to' => $email,
                'exception_class' => $e::class,
            ]);
            return false;
        }

        if (!$sent) {
            $this->logger->warning('NETOPIA refund email dispatch returned false', [
                'order_id' => $orderId,
                'to' => $email,
            ]);
        }

        return $sent;
    }
}
