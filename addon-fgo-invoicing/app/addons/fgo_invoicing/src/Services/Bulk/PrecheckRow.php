<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Services\Bulk;

/**
 * The pre-check of one order: what the page lists and what bulk_run decides
 * on when it re-checks the order server-side.
 *
 * `clientType` and `customerName` are what WOULD reach FGO (BillingMapper on
 * the resolved order), not a guess from the order form, so a company whose
 * CIF sits in a custom profile field shows as PJ here exactly as it will be
 * invoiced. `clientType` is '' when the order could not be mapped.
 */
final readonly class PrecheckRow
{
    /**
     * @param list<PrecheckReason> $reasons
     */
    public function __construct(
        public int $orderId,
        public string $customerName,
        public string $clientType,
        public float $total,
        public string $orderStatus,
        public PrecheckVerdict $verdict,
        public array $reasons,
        public bool $selected,
        public string $invoiceStatus,
        public string $invoiceSeries,
        public string $invoiceNumber,
        public string $pdfLink,
        public string $email,
    ) {
    }

    public function actionable(): bool
    {
        return $this->verdict->actionable();
    }

    /** Whether it can go but carries something the admin should read first. */
    public function hasWarnings(): bool
    {
        if (!$this->actionable()) {
            return false;
        }
        foreach ($this->reasons as $reason) {
            if ($reason->level === PrecheckReason::LEVEL_WARN) {
                return true;
            }
        }

        return false;
    }

    /** "F 0002", or '' when no invoice number is known. */
    public function invoiceLabel(): string
    {
        return trim($this->invoiceSeries . ' ' . $this->invoiceNumber);
    }

    /**
     * @return array{
     *     order_id: int,
     *     customer_name: string,
     *     client_type: string,
     *     total: float,
     *     order_status: string,
     *     verdict: string,
     *     actionable: bool,
     *     selected: bool,
     *     has_warnings: bool,
     *     reasons: list<array{code: string, level: string, params: array<string, string>}>,
     *     invoice_status: string,
     *     invoice_label: string,
     *     pdf_link: string,
     *     email: string
     * }
     */
    public function toArray(): array
    {
        return [
            'order_id' => $this->orderId,
            'customer_name' => $this->customerName,
            'client_type' => $this->clientType,
            'total' => $this->total,
            'order_status' => $this->orderStatus,
            'verdict' => $this->verdict->value,
            'actionable' => $this->actionable(),
            'selected' => $this->selected,
            'has_warnings' => $this->hasWarnings(),
            'reasons' => array_map(static fn (PrecheckReason $r): array => $r->toArray(), $this->reasons),
            'invoice_status' => $this->invoiceStatus,
            'invoice_label' => $this->invoiceLabel(),
            'pdf_link' => $this->pdfLink,
            'email' => $this->email,
        ];
    }
}
