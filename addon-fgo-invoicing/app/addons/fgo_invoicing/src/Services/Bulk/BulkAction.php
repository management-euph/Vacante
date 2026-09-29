<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Services\Bulk;

/**
 * What an admin can do to many orders at once from the orders list.
 *
 * The value is what the stored selection of a pre-check page holds
 * (fgo_invoicing.bulk?token=...) and what every bulk_run request carries;
 * the context-menu dispatch is `fgo_invoicing.m_<value>`. Downloading the PDFs
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
     * Orders one pre-check page handles: the largest page size of the orders
     * list, so a whole page of ticked orders fits in one run (about four
     * minutes at FGO's one request per second). The selection itself keeps
     * every id; a larger one is announced on the page, which offers the next
     * batch, never dropped silently.
     */
    public const MAX_BATCH = 250;

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
     * retried as ISSUES, not as Retry, because an order can fail without
     * leaving a `failed` row (no answer from the server, a block found at run
     * time, an error before the row was written), and Retry would skip it as
     * "no failed attempt". A failed cancel is simply cancelled again.
     */
    public function retryAction(): self
    {
        return $this->issues() ? self::Issue : $this;
    }
}
