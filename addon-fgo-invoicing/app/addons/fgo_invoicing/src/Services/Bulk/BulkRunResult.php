<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Services\Bulk;

/**
 * What happened to one order of a bulk run; bulk_run returns it to the page
 * as `fgo_result`.
 *
 *   issued   an invoice now exists (Issue / Retry)
 *   done     the action went through (Email / Cancel / Storno / Delete)
 *   failed   FGO, the mailer or a pre-check block said no
 *   skipped  there was nothing to do (already invoiced, not invoiced, ...)
 *
 * `message` is text from elsewhere (FGO's error, the mailer's), shown as it
 * came; `reasons` are pre-check codes the controller translates.
 */
final readonly class BulkRunResult
{
    public const OUTCOME_ISSUED = 'issued';
    public const OUTCOME_DONE = 'done';
    public const OUTCOME_FAILED = 'failed';
    public const OUTCOME_SKIPPED = 'skipped';

    /**
     * @param list<PrecheckReason> $reasons
     */
    public function __construct(
        public int $orderId,
        public string $outcome,
        public string $message = '',
        public array $reasons = [],
        public string $invoiceSeries = '',
        public string $invoiceNumber = '',
        public string $pdfLink = '',
        public string $emailStatus = '',
    ) {
        if (!in_array($outcome, [self::OUTCOME_ISSUED, self::OUTCOME_DONE, self::OUTCOME_FAILED, self::OUTCOME_SKIPPED], true)) {
            throw new \InvalidArgumentException('Unknown bulk outcome "' . $outcome . '"');
        }
    }

    /**
     * @return array{
     *     order_id: int,
     *     outcome: string,
     *     message: string,
     *     reasons: list<array{code: string, level: string, params: array<string, string>}>,
     *     invoice_series: string,
     *     invoice_number: string,
     *     pdf_link: string,
     *     email_status: string
     * }
     */
    public function toArray(): array
    {
        return [
            'order_id' => $this->orderId,
            'outcome' => $this->outcome,
            'message' => $this->message,
            'reasons' => array_map(static fn (PrecheckReason $r): array => $r->toArray(), $this->reasons),
            'invoice_series' => $this->invoiceSeries,
            'invoice_number' => $this->invoiceNumber,
            'pdf_link' => $this->pdfLink,
            'email_status' => $this->emailStatus,
        ];
    }
}
