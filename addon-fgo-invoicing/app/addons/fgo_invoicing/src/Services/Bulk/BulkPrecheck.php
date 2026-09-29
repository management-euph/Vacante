<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Services\Bulk;

use Tygh\Addons\FgoInvoicing\Constants;
use Tygh\Addons\FgoInvoicing\Dto\Billing\BillingParty;
use Tygh\Addons\FgoInvoicing\Helpers\RomanianTaxId;
use Tygh\Addons\FgoInvoicing\Helpers\TypeCoerce;
use Tygh\Addons\FgoInvoicing\Services\BillingMapper;

/**
 * Decides, per order, what a bulk action would do before anything reaches
 * FGO: the pre-check page lists it, and bulk_run asks again, server-side,
 * right before acting on each order (the page may be stale, or tampered
 * with; an order issued meanwhile must come back "skipped").
 *
 * Pure: it is handed the order already run through BillingExtrasResolver,
 * its ?:fgo_invoices row (or null) and the store facts it needs as
 * constructor flags, so every rule below is unit-tested without CS-Cart.
 *
 * The client it judges is BillingMapper's, i.e. exactly the one that would
 * be sent. The identity rules mirror InvoiceIssuer::missingIdentity(): a
 * missing CIF / CNP BLOCKS only when the setting requires it AND the store
 * has a field the customer could have typed it into; otherwise it is a
 * warning, as the issuer then issues and logs. Checksums (RomanianTaxId) are
 * warnings only: FGO is the authority on a CIF, and a local rule must not
 * stop a genuine order.
 *
 * A pending row younger than Constants::PENDING_STALE_SECONDS is a request
 * talking to FGO right now: skipped ("being issued right now"). An older one
 * is offered as a retry with a warning; the issuer's atomic claim decides
 * either way. A row that stays unticked by default (a re-issue, an
 * unfinished order, an invoice e-mailed in the last day) is always a `warn`
 * row, never `ready` or `retry`: select-all leaves those alone.
 *
 * @phpstan-type InvoiceFacts array{
 *     status: string,
 *     series: string,
 *     number: string,
 *     label: string,
 *     pdf_link: string,
 *     last_error: string,
 *     updated_age: ?int,
 *     emailed_at: string,
 *     emailed_age: ?int
 * }
 */
final class BulkPrecheck
{
    /**
     * CS-Cart order statuses that are not a finished sale: Incomplete,
     * Failed, Declined, Canceled, Backordered. Invoicing one is possible
     * (the admin may know better) but never by default.
     */
    public const INCOMPLETE_STATUSES = ['N', 'F', 'D', 'I', 'B'];

    /** The last FGO error is shown in a table cell; its full text is on the invoice page. */
    private const ERROR_EXCERPT = 160;

    /** A second "your invoice" e-mail within this many seconds is not sent by default. */
    public const EMAIL_RESEND_WINDOW_SECONDS = 86400;

    /**
     * @param array<string, string> $statusNames CS-Cart order status code => name, for the messages
     * @param bool $seriesConfigured whether the invoice series setting is filled in
     */
    public function __construct(
        private readonly BillingMapper $mapper,
        private readonly bool $clientVatRequired = false,
        private readonly bool $clientCnpRequired = false,
        private readonly bool $hasCifSource = false,
        private readonly bool $hasCnpSource = false,
        private readonly array $statusNames = [],
        private readonly bool $seriesConfigured = true,
    ) {
    }

    /**
     * @param array<string, mixed> $orderInfo resolved (BillingExtrasResolver) order_info
     * @param array<string, mixed>|null $invoice the order's ?:fgo_invoices row
     */
    public function check(BulkAction $action, array $orderInfo, ?array $invoice): PrecheckRow
    {
        $client = null;
        $mappingError = '';
        try {
            $client = $this->mapper->mapOrderInfo($orderInfo)->client;
        } catch (\Throwable $e) {
            $mappingError = $e->getMessage() !== '' ? $e->getMessage() : $e::class;
        }

        $inv = self::invoice($invoice);
        $email = trim(TypeCoerce::toString($orderInfo['email'] ?? ''));

        [$verdict, $reasons, $selected] = match (true) {
            $action->issues() => $this->forIssue($action, $orderInfo, $inv, $client, $mappingError),
            $action === BulkAction::Email => self::forEmail($inv, $email),
            default => self::forInvoiceAction($inv),
        };

        return new PrecheckRow(
            orderId:       TypeCoerce::toInt($orderInfo['order_id'] ?? 0),
            customerName:  self::customerName($orderInfo, $client),
            clientType:    $client !== null ? $client->tip : '',
            total:         TypeCoerce::toFloat($orderInfo['total'] ?? 0),
            orderStatus:   strtoupper(trim(TypeCoerce::toString($orderInfo['status'] ?? ''))),
            verdict:       $verdict,
            reasons:       $reasons,
            selected:      $selected && $verdict->actionable(),
            invoiceStatus: $inv['status'],
            invoiceSeries: $inv['series'],
            invoiceNumber: $inv['number'],
            pdfLink:       $inv['pdf_link'],
            email:         $email,
        );
    }

    /**
     * @param array<string, mixed> $orderInfo
     * @param InvoiceFacts $inv
     *
     * @return array{0: PrecheckVerdict, 1: list<PrecheckReason>, 2: bool}
     */
    private function forIssue(
        BulkAction $action,
        array $orderInfo,
        array $inv,
        ?BillingParty $client,
        string $mappingError,
    ): array {
        $status = $inv['status'];
        if ($status === Constants::STATUS_ISSUED) {
            return [PrecheckVerdict::Skip, [self::reason('already_invoiced', PrecheckReason::LEVEL_INFO, ['[invoice]' => self::labelOrDash($inv['label'])])], false];
        }
        $pending = $status === Constants::STATUS_PENDING;
        if ($pending && !self::isStale($inv)) {
            // Another request holds the claim and may be talking to FGO.
            return [PrecheckVerdict::Skip, [self::reason('in_progress', PrecheckReason::LEVEL_INFO)], false];
        }
        $retryable = $status === Constants::STATUS_FAILED || $pending;
        if ($action === BulkAction::Retry && !$retryable) {
            return [PrecheckVerdict::Skip, [self::reason('not_failed', PrecheckReason::LEVEL_INFO)], false];
        }

        $verdict = PrecheckVerdict::Ready;
        $selected = true;
        $reasons = [];
        if ($status === Constants::STATUS_FAILED) {
            $verdict = PrecheckVerdict::Retry;
            $reasons[] = self::reason('last_error', PrecheckReason::LEVEL_INFO, [
                '[error]' => $inv['last_error'] !== '' ? self::excerpt($inv['last_error']) : '—',
            ]);
        } elseif ($pending) {
            // Pending for longer than any FGO call takes: its request died.
            // The issuer's claim takes the row over, so ticked is safe.
            $verdict = PrecheckVerdict::Retry;
            $reasons[] = self::reason('stale_pending', PrecheckReason::LEVEL_WARN);
        } elseif (in_array($status, [Constants::STATUS_CANCELED, Constants::STATUS_REVERSED, Constants::STATUS_DELETED], true)) {
            // Issuing again creates a NEW fiscal document next to the one
            // that was cancelled: allowed, never by default.
            $selected = false;
            $reasons[] = self::reason('previously_' . $status, PrecheckReason::LEVEL_WARN, ['[invoice]' => self::labelOrDash($inv['label'])]);
        }
        if ($retryable && $inv['series'] !== '' && $inv['number'] !== '') {
            // A failed re-issue: the next attempt still replaces that invoice.
            $reasons[] = self::reason('reissue_of', PrecheckReason::LEVEL_INFO, ['[invoice]' => $inv['label']]);
        }

        if ($client === null) {
            $reasons[] = self::reason('mapping_failed', PrecheckReason::LEVEL_BLOCK, ['[error]' => self::excerpt($mappingError)]);

            return [PrecheckVerdict::Block, $reasons, false];
        }

        if (!$this->seriesConfigured) {
            // FGO rejects an invoice without a series ("Campul 'Serie' este
            // obligatoriu"): nothing would be issued, so nothing is sent.
            $reasons[] = self::reason('no_invoice_series', PrecheckReason::LEVEL_BLOCK);
        }

        $orderStatus = strtoupper(trim(TypeCoerce::toString($orderInfo['status'] ?? '')));
        if (in_array($orderStatus, self::INCOMPLETE_STATUSES, true)) {
            $selected = false;
            $reasons[] = self::reason('order_status', PrecheckReason::LEVEL_WARN, [
                '[status]' => $this->statusNames[$orderStatus] ?? $orderStatus,
            ]);
        }

        foreach ($this->identityReasons($client) as $reason) {
            $reasons[] = $reason;
        }

        if (abs(TypeCoerce::toFloat($orderInfo['total'] ?? 0)) < 0.005) {
            $reasons[] = self::reason('zero_total', PrecheckReason::LEVEL_WARN);
        }

        foreach ($reasons as $reason) {
            if ($reason->level === PrecheckReason::LEVEL_BLOCK) {
                return [PrecheckVerdict::Block, $reasons, false];
            }
        }
        if (!$selected || ($verdict === PrecheckVerdict::Ready && self::anyWarning($reasons))) {
            // Unticked by default means "read this first": a warn row, also
            // when it failed before (a failed invoice on an unfinished order).
            $verdict = PrecheckVerdict::Warn;
        }

        return [$verdict, $reasons, $selected];
    }

    /**
     * @return list<PrecheckReason>
     */
    private function identityReasons(BillingParty $client): array
    {
        $codUnic = trim($client->codUnic ?? '');

        if ($client->isCompany()) {
            if ($codUnic === '') {
                return $this->clientVatRequired && $this->hasCifSource
                    ? [self::reason('pj_without_cif_required', PrecheckReason::LEVEL_BLOCK)]
                    : [self::reason('pj_without_cif', PrecheckReason::LEVEL_WARN)];
            }

            return !$client->strain && !RomanianTaxId::isValidCif($codUnic)
                ? [self::reason('cif_invalid', PrecheckReason::LEVEL_WARN, ['[cif]' => $codUnic])]
                : [];
        }

        // The CNP is a Romanian identifier: a customer abroad has none.
        if ($client->strain) {
            return [];
        }
        if ($codUnic === '') {
            if (!$this->clientCnpRequired) {
                return [];
            }

            return $this->hasCnpSource
                ? [self::reason('pf_without_cnp_required', PrecheckReason::LEVEL_BLOCK)]
                : [self::reason('cnp_required_no_field', PrecheckReason::LEVEL_WARN)];
        }

        // The CNP itself is personal data: the message does not repeat it.
        return RomanianTaxId::isValidCnp($codUnic) ? [] : [self::reason('cnp_invalid', PrecheckReason::LEVEL_WARN)];
    }

    /**
     * @param InvoiceFacts $inv
     *
     * @return array{0: PrecheckVerdict, 1: list<PrecheckReason>, 2: bool}
     */
    private static function forEmail(array $inv, string $email): array
    {
        if ($inv['status'] !== Constants::STATUS_ISSUED) {
            return self::notIssued($inv);
        }
        if ($inv['pdf_link'] === '') {
            return [PrecheckVerdict::Block, [self::reason('no_pdf_link', PrecheckReason::LEVEL_BLOCK)], false];
        }
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return [PrecheckVerdict::Block, [self::reason('no_email', PrecheckReason::LEVEL_BLOCK)], false];
        }
        if ($inv['emailed_age'] !== null && $inv['emailed_age'] < self::EMAIL_RESEND_WINDOW_SECONDS) {
            // The customer already has it; a second copy only on purpose.
            return [PrecheckVerdict::Warn, [self::reason('recently_emailed', PrecheckReason::LEVEL_WARN, [
                '[time]' => $inv['emailed_at'] !== '' ? $inv['emailed_at'] : '—',
            ])], false];
        }

        return [PrecheckVerdict::Ready, [], true];
    }

    /**
     * Cancel / Storno / Delete.
     *
     * @param InvoiceFacts $inv
     *
     * @return array{0: PrecheckVerdict, 1: list<PrecheckReason>, 2: bool}
     */
    private static function forInvoiceAction(array $inv): array
    {
        if ($inv['status'] !== Constants::STATUS_ISSUED) {
            return self::notIssued($inv);
        }
        // FGO identifies the document by series + number; without them
        // InvoiceCanceler cannot even build the request.
        if ($inv['series'] === '' || $inv['number'] === '') {
            return [PrecheckVerdict::Block, [self::reason('no_series_number', PrecheckReason::LEVEL_BLOCK)], false];
        }

        return [PrecheckVerdict::Ready, [], true];
    }

    /**
     * @param InvoiceFacts $inv
     *
     * @return array{0: PrecheckVerdict, 1: list<PrecheckReason>, 2: bool}
     */
    private static function notIssued(array $inv): array
    {
        $status = $inv['status'];
        if (in_array($status, [Constants::STATUS_CANCELED, Constants::STATUS_REVERSED, Constants::STATUS_DELETED], true)) {
            return [PrecheckVerdict::Skip, [self::reason('already_' . $status, PrecheckReason::LEVEL_INFO, ['[invoice]' => self::labelOrDash($inv['label'])])], false];
        }

        return [PrecheckVerdict::Skip, [self::reason('not_invoiced', PrecheckReason::LEVEL_INFO)], false];
    }

    /**
     * The row's facts the rules read. The ages are the seconds MySQL computed
     * (InvoiceRepository: updated_age, emailed_age); null when the row did
     * not carry one.
     *
     * @param array<string, mixed>|null $row
     *
     * @return InvoiceFacts
     */
    private static function invoice(?array $row): array
    {
        $series = trim(TypeCoerce::toString($row['invoice_series'] ?? ''));
        $number = trim(TypeCoerce::toString($row['invoice_number'] ?? ''));

        return [
            'status' => strtolower(trim(TypeCoerce::toString($row['status'] ?? ''))),
            'series' => $series,
            'number' => $number,
            'label' => trim($series . ' ' . $number),
            'pdf_link' => trim(TypeCoerce::toString($row['pdf_link'] ?? '')),
            'last_error' => trim(TypeCoerce::toString($row['last_error'] ?? '')),
            'updated_age' => self::age($row['updated_age'] ?? null),
            'emailed_at' => trim(TypeCoerce::toString($row['emailed_at'] ?? '')),
            'emailed_age' => ($row['emailed_at'] ?? null) === null ? null : self::age($row['emailed_age'] ?? null),
        ];
    }

    private static function age(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : TypeCoerce::toInt($value);
    }

    /**
     * Pending for longer than any FGO call can take. An unknown age counts
     * as fresh: the row is left to the claim rather than offered.
     *
     * @param InvoiceFacts $inv
     */
    private static function isStale(array $inv): bool
    {
        return $inv['updated_age'] !== null && $inv['updated_age'] > Constants::PENDING_STALE_SECONDS;
    }

    /** An invoice label for a message: "—" when the row has no series or number. */
    private static function labelOrDash(string $label): string
    {
        return $label !== '' ? $label : '—';
    }

    /**
     * The name that would be on the invoice; failing a mappable order, the
     * name on the order form, so a blocked row is still recognisable.
     *
     * @param array<string, mixed> $orderInfo
     */
    private static function customerName(array $orderInfo, ?BillingParty $client): string
    {
        if ($client !== null) {
            return $client->denumire;
        }
        $first = trim(TypeCoerce::toString($orderInfo['b_firstname'] ?? $orderInfo['firstname'] ?? ''));
        $last = trim(TypeCoerce::toString($orderInfo['b_lastname'] ?? $orderInfo['lastname'] ?? ''));
        $name = trim($first . ' ' . $last);
        if ($name !== '') {
            return $name;
        }
        $company = trim(TypeCoerce::toString($orderInfo['company'] ?? ''));

        return $company !== '' ? $company : trim(TypeCoerce::toString($orderInfo['email'] ?? ''));
    }

    /**
     * @param list<PrecheckReason> $reasons
     */
    private static function anyWarning(array $reasons): bool
    {
        foreach ($reasons as $reason) {
            if ($reason->level === PrecheckReason::LEVEL_WARN) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, string> $params
     */
    private static function reason(string $code, string $level, array $params = []): PrecheckReason
    {
        return new PrecheckReason($code, $level, $params);
    }

    private static function excerpt(string $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        return mb_strlen($text) > self::ERROR_EXCERPT ? rtrim(mb_substr($text, 0, self::ERROR_EXCERPT - 1)) . '…' : $text;
    }
}
