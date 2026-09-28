<?php

declare(strict_types=1);

namespace Netopia\CsCart\Payment;

use Closure;
use Netopia\CsCart\Support\Arr;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Sends the NETOPIA payment-link email to the customer via CS-Cart's
 * template_emails system. The `netopia_payment_retry` row is imported
 * by CS-Cart's `Tygh\Template\Mail\Exim` from the <email_templates>
 * block in addon.xml on install; once it exists, admins can edit
 * subject and body from Administration → Notifications without
 * touching code.
 */
final class PaymentLinkEmailSender
{
    /**
     * @param Closure(array<string, mixed> $config, string $langCode): bool $mailSender
     *   Dispatches a single mail via CS-Cart's mailer. The `netopia_payment_retry`
     *   template is registered in `?:template_emails` with area 'C', so the
     *   wrapper must call `$mailer->send($config, 'C', $langCode)` — passing
     *   area 'A' makes CS-Cart's template lookup miss the row and the mailer
     *   returns false. The customer's lang_code is forwarded so Twig renders
     *   in their language rather than the session default.
     */
    public function __construct(
        private readonly Closure $mailSender,
        private readonly string $companyName,
        private readonly string $fallbackLangCode,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @param array<string, mixed> $orderInfo
     */
    public function send(array $orderInfo, string $paymentUrl): bool
    {
        $orderId = Arr::int($orderInfo, 'order_id');

        $email = Arr::string($orderInfo, 'email');
        if ($email === '') {
            $this->logger->warning('NETOPIA payment link email skipped: order has no customer email', [
                'order_id' => $orderId,
            ]);
            return false;
        }

        $customerName = trim(Arr::string($orderInfo, 'b_firstname') . ' ' . Arr::string($orderInfo, 'b_lastname'));
        if ($customerName === '') {
            $customerName = $email;
        }
        $langCode = Arr::string($orderInfo, 'lang_code', $this->fallbackLangCode);

        $config = [
            'to' => $email,
            'from' => 'default_company_orders_department',
            'data' => [
                'customer_name' => $customerName,
                'order_id' => $orderId,
                'payment_url' => $paymentUrl,
                'company_name' => $this->companyName,
                'order_info' => $orderInfo,
            ],
            'template_code' => 'netopia_payment_retry',
        ];

        try {
            $sent = (bool) ($this->mailSender)($config, $langCode);
        } catch (Throwable $e) {
            $this->logger->error('NETOPIA payment link email failed: ' . $e->getMessage(), [
                'order_id' => $orderId,
                'to' => $email,
                'exception_class' => $e::class,
            ]);
            return false;
        }

        if (!$sent) {
            $this->logger->warning('NETOPIA payment link email dispatch returned false', [
                'order_id' => $orderId,
                'to' => $email,
            ]);
        }

        return $sent;
    }
}
