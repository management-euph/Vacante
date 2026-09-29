<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Services;

use Tygh\Addons\FgoInvoicing\Constants;
use Tygh\Addons\FgoInvoicing\Helpers\TypeCoerce;
use Tygh\Addons\FgoInvoicing\Repository\InvoiceRepository;

/**
 * Sends the customer the "your invoice is ready" e-mail with the FGO PDF
 * link: once automatically after issuing (InvoiceIssuer, when auto_email_pdf
 * is on or the bulk page asks for it) and on demand for orders invoiced
 * earlier (the bulk "Email invoice to customer" action).
 *
 * The transport is a Closure: production wraps
 * fn_fgo_invoicing_send_invoice_email() (functions/email.php), which talks to
 * CS-Cart's mailer. A src/ class may not reach Tygh::$app itself, and tests
 * record the payload instead of sending anything.
 *
 * Never throws: an e-mail that cannot be sent is reported, it must not undo
 * an invoice FGO has already issued.
 *
 * @phpstan-type InvoiceEmailPayload array{
 *     to: string,
 *     order_id: int,
 *     invoice_series: string,
 *     invoice_number: string,
 *     pdf_link: string,
 *     payment_link: string,
 *     customer_name: string,
 *     lang_code: string,
 *     company_id: int
 * }
 */
final class InvoiceMailer
{
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    /**
     * @param \Closure(InvoiceEmailPayload): bool $sender
     * @param \Closure(int): (array<string, mixed>|null) $orderLoader
     */
    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly \Closure $sender,
        private readonly \Closure $orderLoader,
    ) {
    }

    /**
     * The production transport.
     *
     * @return \Closure(InvoiceEmailPayload): bool
     */
    public static function productionSender(): \Closure
    {
        return self::sendThroughCore(...);
    }

    /**
     * E-mail the invoice already issued for $orderId.
     *
     * @return array{status: string, error?: string}
     */
    public function sendForOrder(int $orderId): array
    {
        $row = $this->repo->findByOrderId($orderId);
        if ($row === null || TypeCoerce::toString($row['status'] ?? '') !== Constants::STATUS_ISSUED) {
            return ['status' => self::STATUS_SKIPPED, 'error' => 'No issued FGO invoice for order ' . $orderId];
        }
        $orderInfo = ($this->orderLoader)($orderId);
        if ($orderInfo === null) {
            return ['status' => self::STATUS_FAILED, 'error' => 'Order ' . $orderId . ' was not found'];
        }

        return $this->send(
            $orderInfo,
            TypeCoerce::toString($row['invoice_series'] ?? ''),
            TypeCoerce::toString($row['invoice_number'] ?? ''),
            TypeCoerce::toString($row['pdf_link'] ?? ''),
            TypeCoerce::toString($row['payment_link'] ?? ''),
        );
    }

    /**
     * @param array<string, mixed> $orderInfo
     *
     * @return array{status: string, error?: string}
     */
    public function send(
        array $orderInfo,
        string $invoiceSeries,
        string $invoiceNumber,
        string $pdfLink,
        string $paymentLink = '',
    ): array {
        $orderId = TypeCoerce::toInt($orderInfo['order_id'] ?? 0);
        $pdfLink = trim($pdfLink);
        if ($pdfLink === '') {
            return ['status' => self::STATUS_SKIPPED, 'error' => 'FGO returned no PDF link for order ' . $orderId];
        }
        $to = trim(TypeCoerce::toString($orderInfo['email'] ?? ''));
        if ($to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            return ['status' => self::STATUS_SKIPPED, 'error' => 'Order ' . $orderId . ' has no valid customer e-mail'];
        }

        $payload = [
            'to' => $to,
            'order_id' => $orderId,
            'invoice_series' => $invoiceSeries,
            'invoice_number' => $invoiceNumber,
            'pdf_link' => $pdfLink,
            'payment_link' => trim($paymentLink),
            'customer_name' => self::customerName($orderInfo),
            'lang_code' => strtolower(trim(TypeCoerce::toString($orderInfo['lang_code'] ?? ''))),
            'company_id' => TypeCoerce::toInt($orderInfo['company_id'] ?? 0),
        ];

        try {
            $sent = ($this->sender)($payload);
        } catch (\Throwable $e) {
            return ['status' => self::STATUS_FAILED, 'error' => '[' . $e::class . '] ' . $e->getMessage()];
        }

        return $sent
            ? ['status' => self::STATUS_SENT]
            : ['status' => self::STATUS_FAILED, 'error' => 'The store mailer did not send the e-mail for order ' . $orderId];
    }

    /**
     * @param InvoiceEmailPayload $payload
     */
    private static function sendThroughCore(array $payload): bool
    {
        if (!function_exists('fn_fgo_invoicing_send_invoice_email')) {
            return false;
        }

        return fn_fgo_invoicing_send_invoice_email($payload);
    }

    /**
     * Who the greeting addresses: the company for a company order, else the
     * person. NOT under the key `company_name`: CS-Cart's mailer overwrites
     * that template variable with the STORE's name.
     *
     * @param array<string, mixed> $orderInfo
     */
    private static function customerName(array $orderInfo): string
    {
        foreach (['fgo_billing_company', 'b_company', 'company'] as $key) {
            $name = trim(TypeCoerce::toString($orderInfo[$key] ?? ''));
            if ($name !== '') {
                return $name;
            }
        }
        $first = trim(TypeCoerce::toString($orderInfo['b_firstname'] ?? $orderInfo['firstname'] ?? ''));
        $last = trim(TypeCoerce::toString($orderInfo['b_lastname'] ?? $orderInfo['lastname'] ?? ''));

        return trim($first . ' ' . $last);
    }
}
