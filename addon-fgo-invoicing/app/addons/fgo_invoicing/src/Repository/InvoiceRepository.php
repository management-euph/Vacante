<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Repository;

use Tygh\Addons\FgoInvoicing\Constants;
use Tygh\Addons\FgoInvoicing\Dto\Invoice\IssueInvoiceResponse;
use Tygh\Addons\FgoInvoicing\Helpers\TypeCoerce;

/**
 * Persistence for `cscart_fgo_invoices`: one row per CS-Cart order id
 * (UNIQUE constraint).
 *
 * The row is also the LOCK on an order's FGO call. A `pending` row is what an
 * issue request in flight looks like, so a request may only call FGO after it
 * has turned the row into `pending` ITSELF, atomically:
 *
 *   - no row yet: insertPending()'s INSERT IGNORE, which claims only when this
 *     very statement inserted the row;
 *   - a row that failed or whose invoice was cancelled / reversed / deleted,
 *     or a pending row older than Constants::PENDING_STALE_SECONDS (its
 *     request died): claimForRetry()'s conditional UPDATE, which claims only
 *     when it changed exactly one row.
 *
 * Both lean on what CS-Cart's db_query() returns for a write
 * (Tygh\Database\Connection::queryPostProcess, 4.20.1): the new AUTO_INCREMENT
 * id when the statement generated one, otherwise the affected-row count. An
 * INSERT IGNORE that hit the UNIQUE key generates no id and affects 0 rows; an
 * UPDATE never generates one. The statements must start with the verb at
 * offset 0, as core looks at the first six characters to pick the count.
 *
 * markIssued() / markFailed() write only a row that is still `pending`, and
 * markCanceled() / markReversed() / markDeleted() only one that is still
 * `issued`; each reports whether it wrote, so a writer that lost a race is
 * told instead of silently overwriting a real invoice.
 *
 * `emailed_at` came after the first release: ensureSchema() adds it to a
 * store installed before it (information_schema-guarded, once per
 * SCHEMA_VERSION, stamped in ?:storage_data; the pattern of
 * DiagnosticLogRepository::ensureTable()). Reinstalling is not an option, it
 * would drop the table.
 *
 * Not `final` so unit tests can stub it with an in-memory subclass; production
 * code should still treat this as the only implementation.
 */
class InvoiceRepository
{
    /** Bump when ADDED_COLUMNS changes, so existing stores check them again. */
    public const SCHEMA_VERSION = '1';

    /**
     * Columns added after the first release, name => definition. Kept in step
     * with addon.xml's CREATE TABLE (a test compares them).
     */
    public const ADDED_COLUMNS = [
        'emailed_at' => "DATETIME NULL DEFAULT NULL COMMENT 'when the invoice e-mail last went to the customer'",
    ];

    private const SCHEMA_STAMP = 'fgo_invoicing_invoices_columns';

    /** Statuses a new issue attempt may take over at once (see claimForRetry()). */
    private const CLAIMABLE_STATUSES = [
        Constants::STATUS_FAILED,
        Constants::STATUS_CANCELED,
        Constants::STATUS_REVERSED,
        Constants::STATUS_DELETED,
    ];

    /** null: not checked in this process yet. */
    private static ?bool $schemaReady = null;

    /**
     * The whole row, plus `updated_age` (seconds since updated_at) and, once
     * the column exists, `emailed_age` (seconds since emailed_at, null when
     * never e-mailed). Both ages are computed by MySQL, so they never depend
     * on PHP and the database agreeing on a time zone.
     *
     * @return array<string, mixed>|null
     */
    public function findByOrderId(int $orderId): ?array
    {
        if ($orderId <= 0) {
            return null;
        }
        $row = db_get_row(
            'SELECT *, ' . $this->ageColumns() . ' FROM ?:fgo_invoices WHERE order_id = ?i',
            $orderId,
        );
        if (!is_array($row) || $row === []) {
            return null;
        }
        /** @var array<string, mixed> $row */
        return $row;
    }

    /**
     * The invoice rows of many orders in ONE query, keyed by order_id; orders
     * without a row are absent.
     *
     * For the orders list (every admin page load of it, through the
     * get_orders_post hook), the order details panel and the bulk pre-check,
     * so only the columns those show are read: the request/response payloads
     * are kilobytes of JSON per row that none of them needs, and must not
     * leave the FGO pages. findByOrderId() still returns the whole row.
     *
     * @param list<int> $orderIds
     *
     * @return array<int, array<string, mixed>>
     */
    public function findByOrderIds(array $orderIds): array
    {
        $ids = [];
        foreach ($orderIds as $id) {
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        if ($ids === []) {
            return [];
        }

        $columns = 'order_id, status, invoice_series, invoice_number, pdf_link, payment_link, last_error, retry_count, updated_at';
        if ($this->ensureSchema()) {
            $columns .= ', emailed_at';
        }
        $rows = db_get_array(
            'SELECT ' . $columns . ', ' . $this->ageColumns()
            . ' FROM ?:fgo_invoices WHERE order_id IN (?n)',
            array_values($ids),
        );
        if (!is_array($rows)) {
            return [];
        }

        $byOrder = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            /** @var array<string, mixed> $row */
            $orderId = TypeCoerce::toInt($row['order_id'] ?? 0);
            if ($orderId > 0) {
                $byOrder[$orderId] = $row;
            }
        }

        return $byOrder;
    }

    /**
     * Insert the order's `pending` row. `claimed` is true only when THIS call
     * inserted it, i.e. when this request may call FGO; a row that already
     * existed (or that a concurrent request inserted a moment earlier) is
     * left untouched and reported with its current status.
     *
     * @return array{id: int, isExisting: bool, status: string, claimed: bool}
     */
    public function insertPending(int $orderId, ?int $cartId = null): array
    {
        if ($orderId <= 0) {
            throw new \InvalidArgumentException('orderId must be positive');
        }

        $inserted = db_query(
            'INSERT IGNORE INTO ?:fgo_invoices (order_id, cart_id, status, created_at, updated_at)'
            . ' VALUES (?i, ?i, ?s, NOW(), NOW())',
            $orderId,
            $cartId ?? 0,
            Constants::STATUS_PENDING,
        );
        $claimed = TypeCoerce::toInt($inserted) > 0;

        $reload = $this->findByOrderId($orderId);
        if ($reload === null) {
            throw new \RuntimeException('Failed to insert/load fgo_invoices row for order ' . $orderId);
        }
        return [
            'id' => TypeCoerce::toInt($reload['id'] ?? 0),
            'isExisting' => !$claimed,
            'status' => TypeCoerce::toString($reload['status'] ?? ''),
            'claimed' => $claimed,
        ];
    }

    /**
     * Claim an existing row for a new issue attempt: turn it `pending` when it
     * failed, when its invoice was cancelled / reversed / deleted, or when it
     * has been pending for longer than $staleSeconds. True only when exactly
     * one row changed, i.e. this request won; an `issued` row, or a pending
     * one another request is still working on, is never taken.
     *
     * The invoice series and number stay: on a cancelled invoice they are
     * what makes the next attempt a re-issue of it (InvoiceIssuer).
     */
    public function claimForRetry(int $orderId, int $staleSeconds = Constants::PENDING_STALE_SECONDS): bool
    {
        if ($orderId <= 0) {
            return false;
        }
        $changed = db_query(
            'UPDATE ?:fgo_invoices SET status = ?s, updated_at = NOW()'
            . ' WHERE order_id = ?i'
            . ' AND (status IN (?a) OR (status = ?s AND updated_at < NOW() - INTERVAL ?i SECOND))',
            Constants::STATUS_PENDING,
            $orderId,
            self::CLAIMABLE_STATUSES,
            Constants::STATUS_PENDING,
            max(1, $staleSeconds),
        );

        return TypeCoerce::toInt($changed) === 1;
    }

    /**
     * Record the invoice FGO issued. Writes only a row that is still
     * `pending` (this request's claim); false when the row had changed
     * meanwhile, and nothing was written.
     *
     * @param array<string, scalar|null> $requestForm raw form fields sent to FGO (for diagnostics)
     */
    public function markIssued(int $orderId, IssueInvoiceResponse $response, array $requestForm): bool
    {
        $changed = db_query(
            'UPDATE ?:fgo_invoices'
            . ' SET status = ?s, success = 1, invoice_number = ?s, invoice_series = ?s, pdf_link = ?s,'
            . ' payment_link = ?s, message = ?s, request_payload = ?s, payload = ?s, last_error = NULL, updated_at = NOW()'
            . ' WHERE order_id = ?i AND status = ?s',
            Constants::STATUS_ISSUED,
            (string) ($response->invoiceNumber ?? ''),
            (string) ($response->invoiceSeries ?? ''),
            (string) ($response->pdfLink ?? ''),
            (string) ($response->paymentLink ?? ''),
            (string) ($response->message ?? ''),
            (string) json_encode($requestForm, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
            (string) json_encode($response->raw, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
            $orderId,
            Constants::STATUS_PENDING,
        );

        return TypeCoerce::toInt($changed) === 1;
    }

    /**
     * Record a failed attempt. Writes only a row that is still `pending`, so
     * a late failure can never overwrite an invoice another request issued.
     * The series and number are left as they were (see claimForRetry()).
     *
     * @param array<string, scalar|null> $requestForm
     * @param array<string, mixed>|null $rawResponse
     */
    public function markFailed(int $orderId, string $errorMessage, array $requestForm, ?array $rawResponse = null): bool
    {
        $changed = db_query(
            'UPDATE ?:fgo_invoices'
            . ' SET status = ?s, success = 0, last_error = ?s, message = ?s, retry_count = retry_count + 1,'
            . ' request_payload = ?s, payload = ?s, updated_at = NOW()'
            . ' WHERE order_id = ?i AND status = ?s',
            Constants::STATUS_FAILED,
            $errorMessage,
            mb_substr($errorMessage, 0, 250),
            (string) json_encode($requestForm, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
            $rawResponse !== null
                ? (string) json_encode($rawResponse, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR)
                : '',
            $orderId,
            Constants::STATUS_PENDING,
        );

        return TypeCoerce::toInt($changed) === 1;
    }

    /** False when the row was no longer `issued` (nothing written). */
    public function markCanceled(int $orderId): bool
    {
        return $this->updateIssuedStatus($orderId, Constants::STATUS_CANCELED);
    }

    /** False when the row was no longer `issued` (nothing written). */
    public function markReversed(int $orderId): bool
    {
        return $this->updateIssuedStatus($orderId, Constants::STATUS_REVERSED);
    }

    /** False when the row was no longer `issued` (nothing written). */
    public function markDeleted(int $orderId): bool
    {
        return $this->updateIssuedStatus($orderId, Constants::STATUS_DELETED);
    }

    /**
     * Note that the invoice e-mail went to the customer (the bulk "Email"
     * pre-check warns about a second one within a day). `updated_at = updated_at`
     * keeps the column's ON UPDATE CURRENT_TIMESTAMP from firing: an e-mail
     * is not a change of the invoice. False when the column is missing and
     * could not be added.
     */
    public function markEmailed(int $orderId): bool
    {
        if ($orderId <= 0 || !$this->ensureSchema()) {
            return false;
        }
        $changed = db_query(
            'UPDATE ?:fgo_invoices SET emailed_at = NOW(), updated_at = updated_at WHERE order_id = ?i',
            $orderId,
        );

        return TypeCoerce::toInt($changed) === 1;
    }

    public function attachAwb(int $orderId, string $awb): void
    {
        db_query(
            'UPDATE ?:fgo_invoices SET awb = ?s, updated_at = NOW() WHERE order_id = ?i',
            $awb,
            $orderId,
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listRecent(int $limit = 100): array
    {
        $limit = max(1, min(1000, $limit));
        $rows = db_get_array(
            'SELECT * FROM ?:fgo_invoices ORDER BY id DESC LIMIT ?i',
            $limit,
        );
        if (!is_array($rows)) {
            return [];
        }
        /** @var array<int, array<string, mixed>> $rows */
        return $rows;
    }

    /**
     * Whether the columns added after the first release exist, adding the
     * missing ones first. Once per process; the information_schema lookup
     * and the DDL once per SCHEMA_VERSION.
     *
     * Never throws: on a store where the ALTER is refused the add-on keeps
     * working without them (no e-mail timestamp), it must not take the
     * orders list or checkout down.
     *
     * The table name in the lookup is written as '?:fgo_invoices' INSIDE the
     * string literal on purpose: CS-Cart replaces every `?:` in a query with
     * the table prefix, quoted or not, which yields the real table name.
     */
    public function ensureSchema(): bool
    {
        if (self::$schemaReady !== null) {
            return self::$schemaReady;
        }
        self::$schemaReady = false;

        try {
            $stamped = function_exists('fn_get_storage_data') && function_exists('fn_set_storage_data');
            if ($stamped && TypeCoerce::toString(fn_get_storage_data(self::SCHEMA_STAMP)) === self::SCHEMA_VERSION) {
                self::$schemaReady = true;

                return true;
            }

            foreach (self::ADDED_COLUMNS as $name => $definition) {
                $exists = TypeCoerce::toInt(db_get_field(
                    'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()'
                    . " AND TABLE_NAME = '?:fgo_invoices' AND COLUMN_NAME = ?s",
                    $name,
                ));
                if ($exists === 0) {
                    db_query('ALTER TABLE ?:fgo_invoices ADD COLUMN `' . $name . '` ' . $definition);
                }
            }

            if ($stamped) {
                fn_set_storage_data(self::SCHEMA_STAMP, self::SCHEMA_VERSION);
            }
            self::$schemaReady = true;
        } catch (\Throwable $e) {
            error_log('fgo_invoicing: could not add the new ?:fgo_invoices columns — ' . $e->getMessage());
        }

        return self::$schemaReady;
    }

    /**
     * Test seam: forget the schema check of this process, or pretend it ran
     * with the given outcome.
     */
    public static function resetSchemaState(?bool $ready = null): void
    {
        self::$schemaReady = $ready;
    }

    /** The computed age columns findByOrderId() / findByOrderIds() add. */
    private function ageColumns(): string
    {
        $ages = 'TIMESTAMPDIFF(SECOND, updated_at, NOW()) AS updated_age';
        if ($this->ensureSchema()) {
            $ages .= ', TIMESTAMPDIFF(SECOND, emailed_at, NOW()) AS emailed_age';
        }

        return $ages;
    }

    private function updateIssuedStatus(int $orderId, string $status): bool
    {
        $changed = db_query(
            'UPDATE ?:fgo_invoices SET status = ?s, updated_at = NOW() WHERE order_id = ?i AND status = ?s',
            $status,
            $orderId,
            Constants::STATUS_ISSUED,
        );

        return TypeCoerce::toInt($changed) === 1;
    }
}
