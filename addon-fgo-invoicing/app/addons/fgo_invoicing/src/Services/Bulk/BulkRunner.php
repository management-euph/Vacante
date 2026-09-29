<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Services\Bulk;

use Tygh\Addons\FgoInvoicing\Constants;
use Tygh\Addons\FgoInvoicing\Helpers\TypeCoerce;
use Tygh\Addons\FgoInvoicing\Repository\InvoiceRepository;
use Tygh\Addons\FgoInvoicing\Services\BillingExtrasResolver;
use Tygh\Addons\FgoInvoicing\Services\InvoiceCanceler;
use Tygh\Addons\FgoInvoicing\Services\InvoiceIssuer;
use Tygh\Addons\FgoInvoicing\Services\InvoiceMailer;
use Tygh\Addons\FgoInvoicing\Services\OrderInfoSource;

/**
 * Carries out one order of a bulk action (the bulk_run request the page
 * sends per order).
 *
 * It never trusts the page: the pre-check runs again here, on the order as
 * it is NOW, and decides. An order issued since the page was drawn (by the
 * status hook, another admin, a double click) comes back "skipped", a
 * blocked one "failed" with the reason, and only an actionable one reaches
 * InvoiceIssuer / InvoiceMailer / InvoiceCanceler. Whether the admin ticked
 * a row the pre-check left unticked (a warning) is the admin's call; a block
 * is not.
 *
 * Never throws: whatever goes wrong becomes a "failed" result, so the page
 * always gets an answer and moves on to the next order.
 */
final class BulkRunner
{
    /** @var \Closure(int): (array<string, mixed>|null) */
    private readonly \Closure $orderLoader;

    /**
     * @param (\Closure(int): (array<string, mixed>|null))|null $orderLoader null = fn_get_order_info()
     */
    public function __construct(
        private readonly BulkPrecheck $precheck,
        private readonly BillingExtrasResolver $resolver,
        private readonly InvoiceRepository $repo,
        private readonly InvoiceIssuer $issuer,
        private readonly InvoiceCanceler $canceler,
        private readonly InvoiceMailer $mailer,
        ?\Closure $orderLoader = null,
    ) {
        $this->orderLoader = $orderLoader ?? OrderInfoSource::core();
    }

    /**
     * @param bool $sendEmail Issue / Retry: e-mail the PDF link afterwards
     */
    public function run(BulkAction $action, int $orderId, bool $sendEmail = false): BulkRunResult
    {
        if ($orderId <= 0) {
            return self::failedWith($orderId, 'order_not_found');
        }

        try {
            $orderInfo = ($this->orderLoader)($orderId);
            if ($orderInfo === null) {
                return self::failedWith($orderId, 'order_not_found');
            }
            $orderInfo['order_id'] = $orderId;

            $check = $this->precheck->check(
                $action,
                $this->resolver->resolve($orderInfo),
                $this->repo->findByOrderId($orderId),
            );

            if ($check->verdict === PrecheckVerdict::Skip) {
                return new BulkRunResult(
                    orderId:       $orderId,
                    outcome:       BulkRunResult::OUTCOME_SKIPPED,
                    reasons:       $check->reasons,
                    invoiceSeries: $check->invoiceSeries,
                    invoiceNumber: $check->invoiceNumber,
                    pdfLink:       $check->pdfLink,
                );
            }
            if ($check->verdict === PrecheckVerdict::Block) {
                return new BulkRunResult($orderId, BulkRunResult::OUTCOME_FAILED, '', $check->reasons);
            }

            return match (true) {
                $action->issues() => $this->issue($orderId, $orderInfo, $sendEmail),
                $action === BulkAction::Email => $this->email($orderId, $check),
                default => $this->actOnInvoice($action, $orderId, $check),
            };
        } catch (\Throwable $e) {
            return new BulkRunResult($orderId, BulkRunResult::OUTCOME_FAILED, '[' . $e::class . '] ' . $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $orderInfo the order as loaded (the issuer resolves it itself)
     */
    private function issue(int $orderId, array $orderInfo, bool $sendEmail): BulkRunResult
    {
        $result = $this->issuer->issueForOrder($orderId, $orderInfo, $sendEmail);
        $row = $this->repo->findByOrderId($orderId) ?? [];
        $series = TypeCoerce::toString($row['invoice_series'] ?? '');
        $number = TypeCoerce::toString($row['invoice_number'] ?? '');
        $pdf = TypeCoerce::toString($row['pdf_link'] ?? '');

        if ($result['status'] !== Constants::STATUS_ISSUED) {
            $error = $result['error'] ?? '';

            return new BulkRunResult(
                $orderId,
                BulkRunResult::OUTCOME_FAILED,
                $error !== '' ? $error : 'FGO did not issue the invoice',
            );
        }

        // Issued by someone else between the pre-check above and the
        // issuer's own look at the row: not this run's invoice.
        if (($result['invoice_id'] ?? '') === 'already-issued') {
            return new BulkRunResult(
                orderId:       $orderId,
                outcome:       BulkRunResult::OUTCOME_SKIPPED,
                reasons:       [new PrecheckReason('already_invoiced', PrecheckReason::LEVEL_INFO, ['[invoice]' => trim($series . ' ' . $number)])],
                invoiceSeries: $series,
                invoiceNumber: $number,
                pdfLink:       $pdf,
            );
        }

        return new BulkRunResult(
            orderId:       $orderId,
            outcome:       BulkRunResult::OUTCOME_ISSUED,
            invoiceSeries: $series,
            invoiceNumber: $number,
            pdfLink:       $pdf,
            emailStatus:   $result['email_status'] ?? '',
        );
    }

    private function email(int $orderId, PrecheckRow $check): BulkRunResult
    {
        $result = $this->mailer->sendForOrder($orderId);
        if ($result['status'] !== InvoiceMailer::STATUS_SENT) {
            return new BulkRunResult($orderId, BulkRunResult::OUTCOME_FAILED, $result['error'] ?? 'The e-mail was not sent');
        }

        return new BulkRunResult(
            orderId:       $orderId,
            outcome:       BulkRunResult::OUTCOME_DONE,
            invoiceSeries: $check->invoiceSeries,
            invoiceNumber: $check->invoiceNumber,
            pdfLink:       $check->pdfLink,
            emailStatus:   InvoiceMailer::STATUS_SENT,
        );
    }

    private function actOnInvoice(BulkAction $action, int $orderId, PrecheckRow $check): BulkRunResult
    {
        $result = match ($action) {
            BulkAction::Cancel => $this->canceler->cancel($orderId),
            BulkAction::Storno => $this->canceler->storno($orderId),
            default => $this->canceler->delete($orderId),
        };
        if ($result['status'] !== 'ok') {
            return new BulkRunResult($orderId, BulkRunResult::OUTCOME_FAILED, $result['error'] ?? 'FGO refused the request');
        }

        return new BulkRunResult(
            orderId:       $orderId,
            outcome:       BulkRunResult::OUTCOME_DONE,
            invoiceSeries: $check->invoiceSeries,
            invoiceNumber: $check->invoiceNumber,
        );
    }

    private static function failedWith(int $orderId, string $code): BulkRunResult
    {
        return new BulkRunResult($orderId, BulkRunResult::OUTCOME_FAILED, '', [new PrecheckReason($code, PrecheckReason::LEVEL_BLOCK)]);
    }
}
