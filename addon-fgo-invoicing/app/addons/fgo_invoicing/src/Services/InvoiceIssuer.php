<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Services;

use Tygh\Addons\FgoInvoicing\Api\FgoApiClient;
use Tygh\Addons\FgoInvoicing\Api\FgoApiException;
use Tygh\Addons\FgoInvoicing\Constants;
use Tygh\Addons\FgoInvoicing\Dto\Invoice\IssueInvoiceRequest;
use Tygh\Addons\FgoInvoicing\Dto\Invoice\IssueInvoiceResponse;
use Tygh\Addons\FgoInvoicing\Helpers\CnpMasker;
use Tygh\Addons\FgoInvoicing\Helpers\RomanianTaxId;
use Tygh\Addons\FgoInvoicing\Helpers\TypeCoerce;
use Tygh\Addons\FgoInvoicing\Repository\DiagnosticLogRepository;
use Tygh\Addons\FgoInvoicing\Repository\InvoiceRepository;

/**
 * Orchestrates issuing an FGO invoice for a CS-Cart order.
 *
 * Invariants:
 *   - At most one `issued` row per `order_id` (DB-enforced UNIQUE).
 *   - FGO is called only by the request that CLAIMED the order's row: it
 *     inserted it (InvoiceRepository::insertPending) or turned a failed /
 *     cancelled / reversed / deleted / long-stale pending row `pending`
 *     (claimForRetry), each atomically. Two tabs, two admins, or a bulk run
 *     overlapping the change_order_status auto-issue therefore cannot both
 *     send the order: the loser calls nothing and answers RESULT_IN_PROGRESS
 *     (the hooks only log it; the bulk page shows "being issued right now").
 *     With verify_duplicate off, a second call would be a second fiscal
 *     invoice. An `issued` row short-circuits before any claim.
 *   - The result is written only over this request's own `pending` row
 *     (markIssued / markFailed are conditional), so a late writer never
 *     overwrites a real invoice. Should FGO issue while the row was taken
 *     over (a claim older than Constants::PENDING_STALE_SECONDS, i.e. a
 *     request stuck far beyond every timeout), the invoice is not lost
 *     silently: the attempt fails with its number and the diagnostic log
 *     keeps it.
 *   - Failures persist `status=failed`, increment `retry_count`, and write
 *     the FGO `Message` (truncated) into `last_error`. The row is reused on
 *     subsequent attempts.
 *   - A row that is not `issued` but still carries an invoice series and
 *     number held an invoice that was cancelled, reversed or deleted (the
 *     cancel family and markFailed keep those columns). Issuing again is a
 *     RE-ISSUE of that invoice: BillingMapper derives the RequestId from it,
 *     so FGO's deduplication does not hand back the cancelled document, and
 *     should FGO still answer with the same series and number, the answer is
 *     refused (not saved as issued, not e-mailed) with what to do. A
 *     successful re-issue names the invoice it replaced in the diagnostic
 *     log and a warning event before the row is overwritten.
 *   - Every order_info — handed in by a hook or loaded here — goes through
 *     BillingExtrasResolver before mapping, so the CIF / Reg. Com. / CNP the
 *     customer typed into the store's profile fields reach the invoice.
 *   - `client_vat_required` / `client_cnp_required` are enforced after that
 *     resolution and BEFORE any API call: a PJ without a CIF (or a Romanian
 *     PF without a CNP) is marked failed with the fix spelled out, and FGO is
 *     never called. A fiscal invoice issued without the identifier cannot
 *     simply be edited afterwards; it has to be reversed.
 *     They block ONLY on a store that has a profile field the identifier
 *     could have been typed into. Both settings shipped long before anything
 *     read them ("Require CIF / CNP at checkout"), so stores may have them
 *     on without such a field; blocking there would stop every invoice at
 *     deploy for something no customer could supply. Those orders are issued
 *     as before and a warning is logged instead.
 *   - On success the customer e-mail with the signed PDF link goes out
 *     through InvoiceMailer when `auto_email_pdf` is on, or when the caller
 *     says so ($sendEmail: the bulk page's "Email the PDF link" box, which
 *     overrides the setting either way). Failure to send the e-mail does NOT
 *     roll back issuance — the invoice has already been emitted on FGO's
 *     side; the result reports it as `email_status`.
 *   - Nothing stored keeps an individual's CNP: the request and response
 *     copies in ?:fgo_invoices, the error text and the per-attempt row in
 *     ?:fgo_diagnostic_logs carry it masked (CnpMasker). That row also
 *     records whether the CNP passed its checksum and how long it was, and
 *     how FGO answered — enough to tell why an attempt failed.
 */
final class InvoiceIssuer
{
    /**
     * issueForOrder() status when another request holds the order's claim:
     * nothing was sent to FGO. Never stored in ?:fgo_invoices.
     */
    public const RESULT_IN_PROGRESS = 'in_progress';

    private readonly InvoiceMailer $mailer;

    private readonly DiagnosticLogRepository $diagnostics;

    /**
     * $mailer and $diagnostics default to the production ones (CS-Cart's
     * mailer, the ?:fgo_diagnostic_logs table); the bulk page and the hooks
     * get the Container's.
     */
    public function __construct(
        private readonly FgoApiClient $api,
        private readonly InvoiceRepository $repo,
        private readonly BillingMapper $mapper,
        private readonly BillingExtrasResolver $resolver,
        ?InvoiceMailer $mailer = null,
        ?DiagnosticLogRepository $diagnostics = null,
    ) {
        $this->mailer = $mailer ?? new InvoiceMailer($repo, InvoiceMailer::productionSender(), OrderInfoSource::core());
        $this->diagnostics = $diagnostics ?? new DiagnosticLogRepository();
    }

    /**
     * @param array<string, mixed>|null $orderInfo Pre-fetched order_info; fetched lazily when null.
     * @param bool|null $sendEmail E-mail the PDF link after issuing; null follows the auto_email_pdf setting.
     *
     * @return array{status: string, invoice_id?: string, error?: string, email_status?: string}
     */
    public function issueForOrder(int $orderId, ?array $orderInfo = null, ?bool $sendEmail = null): array
    {
        if ($orderId <= 0) {
            return ['status' => 'invalid', 'error' => 'orderId must be positive'];
        }

        $before = $this->repo->findByOrderId($orderId);
        if ($before !== null && self::statusOf($before) === Constants::STATUS_ISSUED) {
            return ['status' => Constants::STATUS_ISSUED, 'invoice_id' => 'already-issued'];
        }
        $claimed = $before === null
            ? $this->repo->insertPending($orderId)['claimed']
            : $this->repo->claimForRetry($orderId);
        if (!$claimed) {
            return $this->notClaimed($orderId);
        }
        // Read before the claim, which changes only the status: the invoice
        // this attempt replaces, if any.
        $replaced = self::replacedInvoice($before);

        if ($orderInfo === null) {
            $orderInfo = $this->loadOrderInfo($orderId);
            if ($orderInfo === null) {
                $err = 'fn_get_order_info returned no data for order ' . $orderId;
                $this->recordFailure($orderId, $err, []);
                $this->logEvent('error', 'order-not-found', ['order_id' => $orderId]);
                return ['status' => Constants::STATUS_FAILED, 'error' => $err];
            }
        }

        // Known before the first line that can throw, so every catch below can
        // mask what it stores.
        $form = [];
        $maskedForm = [];
        $cnp = '';
        $cnpFacts = [null, null];

        try {
            $orderInfo = $this->resolver->resolve($orderInfo);
            $request = $this->mapper->mapOrderInfo($orderInfo, $replaced['series'] ?? '', $replaced['number'] ?? '');
            $form = $request->toFormFields();
            $maskedForm = CnpMasker::maskForm($form);
            $cnp = CnpMasker::cnpInForm($form);
            $cnpFacts = self::cnpFacts($request, $orderInfo);

            $missing = $this->missingIdentity($request, $orderId);
            if ($missing !== null) {
                // Nothing was sent, so no request is stored.
                $this->recordFailure($orderId, $missing, []);
                $this->diagnostics->record($orderId, $cnpFacts[0], $cnpFacts[1], 'blocked', $missing, []);
                $this->logEvent('warn', 'identity-missing', ['order_id' => $orderId, 'message' => $missing]);
                return ['status' => Constants::STATUS_FAILED, 'error' => $missing];
            }

            $rawResponse = $this->api->issueInvoice($form);
            $response = IssueInvoiceResponse::fromApiResponse($rawResponse);
            $stored = IssueInvoiceResponse::fromApiResponse(CnpMasker::scrubArray($rawResponse, $cnp));

            if ($replaced !== null && self::isSameInvoice($response, $replaced)) {
                return $this->refuseReturnedInvoice($orderId, $replaced, $maskedForm, $stored->raw, $cnpFacts);
            }

            $label = self::label($response->invoiceSeries ?? '', $response->invoiceNumber ?? '');
            if ($replaced !== null) {
                $note = 'Re-issue: replaces the invoice ' . $replaced['label'] . ' (row status before this attempt: '
                    . $replaced['status'] . ') with ' . $label . '.';
                $this->diagnostics->record($orderId, $cnpFacts[0], $cnpFacts[1], 'success', $note, $maskedForm);
                $this->logEvent('warn', 'reissued', [
                    'order_id' => $orderId,
                    'replaced_invoice' => $replaced['label'],
                    'replaced_status' => $replaced['status'],
                    'invoice' => $label,
                ]);
            } else {
                $this->diagnostics->record($orderId, $cnpFacts[0], $cnpFacts[1], 'success', '', $maskedForm);
            }

            if (!$this->repo->markIssued($orderId, $stored, $maskedForm)) {
                return $this->issuedButRowChanged($orderId, $label, $maskedForm, $cnpFacts);
            }
            $emailStatus = $this->maybeEmail($orderId, $orderInfo, $response, $sendEmail ?? ConfigProvider::autoEmailPdf());
            $this->logEvent('info', 'issued', [
                'order_id' => $orderId,
                'invoice_number' => $response->invoiceNumber,
                'invoice_series' => $response->invoiceSeries,
            ]);
            return [
                'status' => Constants::STATUS_ISSUED,
                'invoice_id' => (string) ($response->invoiceNumber ?? ''),
                'email_status' => $emailStatus,
            ];
        } catch (FgoApiException $e) {
            // FGO may quote the CNP back in its message or body.
            $msg = CnpMasker::scrubText($e->getMessage(), $cnp);
            $raw = $e->rawResponse !== null ? CnpMasker::scrubArray($e->rawResponse, $cnp) : null;
            $this->recordFailure($orderId, $msg, $maskedForm, $raw);
            $this->diagnostics->record($orderId, $cnpFacts[0], $cnpFacts[1], self::failureCode($e), $msg, $maskedForm);
            $this->logEvent('error', 'fgo-rejected', [
                'order_id' => $orderId,
                'message' => $msg,
                'http' => $e->httpStatus,
            ]);
            return ['status' => Constants::STATUS_FAILED, 'error' => $msg];
        } catch (\Throwable $e) {
            $msg = CnpMasker::scrubText('[' . $e::class . '] ' . $e->getMessage(), $cnp);
            $this->recordFailure($orderId, $msg, $maskedForm);
            $this->diagnostics->record($orderId, $cnpFacts[0], $cnpFacts[1], 'error', $msg, $maskedForm);
            $this->logEvent('error', 'unexpected', [
                'order_id' => $orderId,
                'exception' => $e::class,
                'message' => CnpMasker::scrubText($e->getMessage(), $cnp),
            ]);
            return ['status' => Constants::STATUS_FAILED, 'error' => $msg];
        }
    }

    /**
     * The claim went to another request. It may have finished in the
     * meantime: an invoice it issued is reported as such.
     *
     * @return array{status: string, invoice_id?: string, error?: string}
     */
    private function notClaimed(int $orderId): array
    {
        $now = $this->repo->findByOrderId($orderId);
        if ($now !== null && self::statusOf($now) === Constants::STATUS_ISSUED) {
            return ['status' => Constants::STATUS_ISSUED, 'invoice_id' => 'already-issued'];
        }
        $since = $now !== null ? TypeCoerce::toString($now['updated_at'] ?? '') : '';
        $this->logEvent('warn', 'issue-in-progress', ['order_id' => $orderId, 'since' => $since]);

        return [
            'status' => self::RESULT_IN_PROGRESS,
            'error' => 'Another request is issuing the FGO invoice of order #' . $orderId . ' right now'
                . ($since !== '' ? ' (since ' . $since . ')' : '')
                . '; nothing was sent to FGO. Look again in a minute.',
        ];
    }

    /**
     * FGO answered a re-issue with the very invoice it replaces: its
     * deduplication matched the order, not the new RequestId. Saving that as
     * `issued` would show a cancelled document as the order's invoice and
     * e-mail it to the customer, so the attempt fails instead.
     *
     * @param array{series: string, number: string, label: string, status: string} $replaced
     * @param array<string, scalar|null> $maskedForm
     * @param array<string, mixed> $storedRaw the response, CNP masked
     * @param array{0: ?bool, 1: ?int} $cnpFacts
     *
     * @return array{status: string, error: string}
     */
    private function refuseReturnedInvoice(int $orderId, array $replaced, array $maskedForm, array $storedRaw, array $cnpFacts): array
    {
        $msg = 'FGO returned the invoice ' . $replaced['label'] . ' again, the one order #' . $orderId . ' had before it was'
            . ' cancelled, reversed or deleted, instead of issuing a new one (FGO deduplicated the re-issue).'
            . ' Nothing was recorded as issued and no e-mail was sent.'
            . ' Issue the new invoice in FGO manually, or ask FGO support how to re-issue it.';
        $this->recordFailure($orderId, $msg, $maskedForm, $storedRaw);
        $this->diagnostics->record($orderId, $cnpFacts[0], $cnpFacts[1], 'reissue/same-invoice', $msg, $maskedForm);
        $this->logEvent('error', 'reissue-returned-previous', [
            'order_id' => $orderId,
            'invoice' => $replaced['label'],
            'message' => $msg,
        ]);

        return ['status' => Constants::STATUS_FAILED, 'error' => $msg];
    }

    /**
     * FGO issued, but this request's `pending` row is gone (taken over as
     * stale, or written by another request). The invoice number must not be
     * lost: it is in the answer, the diagnostic log and the event log.
     *
     * @param array<string, scalar|null> $maskedForm
     * @param array{0: ?bool, 1: ?int} $cnpFacts
     *
     * @return array{status: string, error: string}
     */
    private function issuedButRowChanged(int $orderId, string $label, array $maskedForm, array $cnpFacts): array
    {
        $now = $this->repo->findByOrderId($orderId) ?? [];
        $current = trim(self::statusOf($now) . ' ' . self::label(
            TypeCoerce::toString($now['invoice_series'] ?? ''),
            TypeCoerce::toString($now['invoice_number'] ?? ''),
        ));
        $msg = 'FGO issued the invoice ' . $label . ' for order #' . $orderId . ', but the order\'s invoice row changed while FGO'
            . ' was answering (now: ' . ($current !== '' ? $current : 'unknown') . '), so it was not recorded here.'
            . ' Check the order in FGO: if it now has two invoices, reverse the one that is not recorded.';
        $this->diagnostics->record($orderId, $cnpFacts[0], $cnpFacts[1], 'conflict', $msg, $maskedForm);
        $this->logEvent('error', 'issued-row-changed', ['order_id' => $orderId, 'invoice' => $label, 'message' => $msg]);

        return ['status' => Constants::STATUS_FAILED, 'error' => $msg];
    }

    /**
     * markFailed() writes only this request's own pending row; when it no
     * longer is, the failure is logged and the row left to whoever holds it.
     *
     * @param array<string, scalar|null> $form
     * @param array<string, mixed>|null $raw
     */
    private function recordFailure(int $orderId, string $message, array $form, ?array $raw = null): void
    {
        if (!$this->repo->markFailed($orderId, $message, $form, $raw)) {
            $this->logEvent('warn', 'failed-row-changed', ['order_id' => $orderId, 'message' => $message]);
        }
    }

    /**
     * The invoice a new attempt replaces: the series and number a row that is
     * not `issued` still carries (see the class docblock), or null.
     *
     * @param array<string, mixed>|null $row the row as it was before the claim
     *
     * @return array{series: string, number: string, label: string, status: string}|null
     */
    private static function replacedInvoice(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }
        $series = trim(TypeCoerce::toString($row['invoice_series'] ?? ''));
        $number = trim(TypeCoerce::toString($row['invoice_number'] ?? ''));
        if ($series === '' || $number === '') {
            return null;
        }

        return ['series' => $series, 'number' => $number, 'label' => self::label($series, $number), 'status' => self::statusOf($row)];
    }

    /**
     * @param array{series: string, number: string, label: string, status: string} $replaced
     */
    private static function isSameInvoice(IssueInvoiceResponse $response, array $replaced): bool
    {
        $number = trim($response->invoiceNumber ?? '');

        return $number !== ''
            && strcasecmp($number, $replaced['number']) === 0
            && strcasecmp(trim($response->invoiceSeries ?? ''), $replaced['series']) === 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function statusOf(array $row): string
    {
        return strtolower(trim(TypeCoerce::toString($row['status'] ?? '')));
    }

    /** "F 0002"; "—" when FGO sent neither. */
    private static function label(string $series, string $number): string
    {
        $label = trim(trim($series) . ' ' . trim($number));

        return $label !== '' ? $label : '—';
    }

    /**
     * fgo_response_code for a failed FGO call: "rejected/<http>" when FGO
     * answered with Success=false (a decoded body), "http/<code>" for an HTTP
     * error without one, "network" when no HTTP status came back at all.
     */
    private static function failureCode(FgoApiException $e): string
    {
        if ($e->rawResponse !== null) {
            return $e->httpStatus !== null ? 'rejected/' . $e->httpStatus : 'rejected';
        }

        return $e->httpStatus !== null ? 'http/' . $e->httpStatus : 'network';
    }

    /**
     * [is_cnp_checksum_valid, cnp_length] for ?:fgo_diagnostic_logs: both null
     * unless an individual's CNP is on the request. The length is of the value
     * as the customer typed it (spaces included), which is what shows a
     * missing digit or stray whitespace.
     *
     * @param array<string, mixed> $orderInfo resolved (BillingExtrasResolver)
     *
     * @return array{0: ?bool, 1: ?int}
     */
    private static function cnpFacts(IssueInvoiceRequest $request, array $orderInfo): array
    {
        $client = $request->client;
        if ($client->isCompany() || $client->codUnic === null || $client->codUnic === '') {
            return [null, null];
        }

        $typed = trim(TypeCoerce::toString($orderInfo['fgo_billing_cnp'] ?? ''));
        if ($typed === '') {
            $typed = $client->codUnic;
        }

        return [RomanianTaxId::isValidCnp(RomanianTaxId::compact($typed)), mb_strlen($typed)];
    }

    /**
     * Why this invoice must not be issued yet, or null when it may.
     *
     * The CNP rule skips foreign customers: the CNP is a Romanian identifier,
     * and a customer abroad cannot have one to give. Neither rule blocks on a
     * store with no field to hold the identifier (see the class docblock);
     * that case is logged, once per issue attempt.
     */
    private function missingIdentity(IssueInvoiceRequest $request, int $orderId): ?string
    {
        $client = $request->client;
        if ($client->codUnic !== null && $client->codUnic !== '') {
            return null;
        }

        if ($client->isCompany()) {
            if (!ConfigProvider::clientVatRequired()) {
                return null;
            }
            if (!$this->resolver->hasSourceFor(BillingExtrasResolver::KEY_CIF)) {
                $this->logEvent('warn', 'identity-source-missing', [
                    'order_id' => $orderId,
                    'message' => 'client_vat_required is on but the store has no CIF profile field;'
                        . ' issued without a CIF. Create a CIF text field under Administration > Profile fields.',
                ]);

                return null;
            }

            return 'Company customer has no CIF / VAT ID: fill the CIF profile field on order #' . $orderId
                . ', then retry with Issue on its FGO invoice page (fgo_invoicing.view?order_id=' . $orderId . ').';
        }

        if (!ConfigProvider::clientCnpRequired() || $client->strain) {
            return null;
        }
        if (!$this->resolver->hasSourceFor(BillingExtrasResolver::KEY_CNP)) {
            $this->logEvent('warn', 'identity-source-missing', [
                'order_id' => $orderId,
                'message' => 'client_cnp_required is on but the store has no CNP profile field;'
                    . ' issued without a CNP. Create a CNP text field under Administration > Profile fields.',
            ]);

            return null;
        }

        return 'Individual customer has no CNP: fill the CNP profile field on order #' . $orderId
            . ', then retry with Issue on its FGO invoice page (fgo_invoicing.view?order_id=' . $orderId . ').';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadOrderInfo(int $orderId): ?array
    {
        return OrderInfoSource::core()($orderId);
    }

    /**
     * @param array<string, mixed> $orderInfo
     *
     * @return string InvoiceMailer status, or 'off' when no e-mail was wanted
     */
    private function maybeEmail(int $orderId, array $orderInfo, IssueInvoiceResponse $response, bool $wanted): string
    {
        if (!$wanted) {
            return 'off';
        }
        $orderInfo['order_id'] = $orderId;
        $result = $this->mailer->send(
            $orderInfo,
            $response->invoiceSeries ?? '',
            $response->invoiceNumber ?? '',
            $response->pdfLink ?? '',
            $response->paymentLink ?? '',
        );
        if ($result['status'] === InvoiceMailer::STATUS_FAILED) {
            $this->logEvent('warn', 'email-send-failed', [
                'order_id' => $orderId,
                'message' => $result['error'] ?? '',
            ]);
        }

        return $result['status'];
    }

    /**
     * @param array<string, mixed> $context
     */
    private function logEvent(string $level, string $event, array $context): void
    {
        if (!function_exists('fn_log_event')) {
            return;
        }
        if (!ConfigProvider::debugLogging() && $level === 'info') {
            return;
        }
        fn_log_event('fgo_invoicing', 'runtime', [
            'message' => '[' . $level . '] ' . $event,
            'context' => $context,
        ]);
    }
}
