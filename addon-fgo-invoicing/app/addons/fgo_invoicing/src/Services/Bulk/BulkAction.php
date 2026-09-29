<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Services\Bulk;

/**
 * What an admin can do to many orders at once from the orders list.
 *
 * The value is what travels in the URL of the pre-check page
 * (fgo_invoicing.bulk?action=issue) and in every bulk_run request; the
 * context-menu dispatch is `fgo_invoicing.m_<value>`. Downloading the PDFs
 * is deliberately not a case: it has no pre-check and calls FGO for nothing,
 * it only fetches files that already exist (fgo_invoicing.m_download_pdfs).
 */
enum BulkAction: string
{
    case Issue = 'issue';
    case Retry = 'retry';
    case Email = 'email';
    case Cancel = 'cancel';
    case Storno = 'storno';
    case Delete = 'delete';

    /**
     * Orders one run handles. The page is sent one order per second or so,
     * and a longer queue is a page nobody keeps open to the end; the admin is
     * told when more were selected, the rest are never dropped silently.
     */
    public const MAX_BATCH = 100;

    /** The action a context-menu mode (`m_issue`, `m_cancel`, ...) starts. */
    public static function fromMenuMode(string $mode): ?self
    {
        return str_starts_with($mode, 'm_') ? self::tryFrom(substr($mode, 2)) : null;
    }

    public function menuMode(): string
    {
        return 'm_' . $this->value;
    }

    /** Issue and Retry both end in InvoiceIssuer and share every pre-check rule. */
    public function issues(): bool
    {
        return $this === self::Issue || $this === self::Retry;
    }

    /** Cancel / Storno / Delete act on an invoice FGO already has. */
    public function actsOnIssuedInvoice(): bool
    {
        return $this === self::Cancel || $this === self::Storno || $this === self::Delete;
    }

    /**
     * The action "Retry failed" on the results page starts: failed issues are
     * retried as issues, a failed cancel is simply cancelled again.
     */
    public function retryAction(): self
    {
        return $this->issues() ? self::Retry : $this;
    }
}
