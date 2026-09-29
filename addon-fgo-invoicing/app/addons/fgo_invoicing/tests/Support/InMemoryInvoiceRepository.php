<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Tests\Support;

use Tygh\Addons\FgoInvoicing\Constants;
use Tygh\Addons\FgoInvoicing\Dto\Invoice\IssueInvoiceResponse;
use Tygh\Addons\FgoInvoicing\Repository\InvoiceRepository;

/**
 * ?:fgo_invoices in memory, for the services that read and write it
 * (issuer, canceler, mailer, bulk runner, orders-list column), with the same
 * claim rules as the SQL: a row is claimed only by the call that inserted it
 * or turned it `pending`, markIssued()/markFailed() write only a `pending`
 * row and the cancel family only an `issued` one.
 *
 * `updated_age` / `emailed_age` stand for the ages MySQL computes; a test
 * sets them on put() to age a row.
 */
class InMemoryInvoiceRepository extends InvoiceRepository
{
    /** @var array<int, array<string, mixed>> */
    public array $rows = [];

    /** How many batch lookups ran, and with which ids. */
    /** @var list<list<int>> */
    public array $batchLookups = [];

    public function __construct()
    {
    }

    /**
     * @param array<string, mixed> $row
     */
    public function put(int $orderId, array $row): self
    {
        $this->rows[$orderId] = array_replace([
            'id' => $orderId,
            'order_id' => $orderId,
            'status' => Constants::STATUS_PENDING,
            'invoice_series' => '',
            'invoice_number' => '',
            'pdf_link' => '',
            'payment_link' => '',
            'last_error' => '',
            'retry_count' => 0,
            'updated_at' => '2026-09-29 10:00:00',
            'updated_age' => 0,
            'emailed_at' => null,
            'emailed_age' => null,
            'request_payload' => '',
            'payload' => '',
        ], $row);

        return $this;
    }

    #[\Override]
    public function findByOrderId(int $orderId): ?array
    {
        return $this->rows[$orderId] ?? null;
    }

    #[\Override]
    public function findByOrderIds(array $orderIds): array
    {
        $this->batchLookups[] = $orderIds;
        $out = [];
        foreach ($orderIds as $id) {
            if (isset($this->rows[$id])) {
                $row = $this->rows[$id];
                unset($row['request_payload'], $row['payload']);
                $out[$id] = $row;
            }
        }

        return $out;
    }

    #[\Override]
    public function insertPending(int $orderId, ?int $cartId = null): array
    {
        if (isset($this->rows[$orderId])) {
            return ['id' => $orderId, 'isExisting' => true, 'status' => (string) $this->rows[$orderId]['status'], 'claimed' => false];
        }
        $this->put($orderId, ['status' => Constants::STATUS_PENDING]);

        return ['id' => $orderId, 'isExisting' => false, 'status' => Constants::STATUS_PENDING, 'claimed' => true];
    }

    #[\Override]
    public function claimForRetry(int $orderId, int $staleSeconds = Constants::PENDING_STALE_SECONDS): bool
    {
        $row = $this->rows[$orderId] ?? null;
        if ($row === null) {
            return false;
        }
        $status = (string) $row['status'];
        $claimable = in_array($status, [Constants::STATUS_FAILED, Constants::STATUS_CANCELED, Constants::STATUS_REVERSED, Constants::STATUS_DELETED], true)
            || ($status === Constants::STATUS_PENDING && (int) ($row['updated_age'] ?? 0) > $staleSeconds);
        if (!$claimable) {
            return false;
        }
        $this->rows[$orderId]['status'] = Constants::STATUS_PENDING;
        $this->rows[$orderId]['updated_age'] = 0;

        return true;
    }

    #[\Override]
    public function markIssued(int $orderId, IssueInvoiceResponse $response, array $requestForm): bool
    {
        if (($this->rows[$orderId]['status'] ?? null) !== Constants::STATUS_PENDING) {
            return false;
        }
        $this->put($orderId, array_replace($this->rows[$orderId], [
            'status' => Constants::STATUS_ISSUED,
            'invoice_series' => (string) $response->invoiceSeries,
            'invoice_number' => (string) $response->invoiceNumber,
            'pdf_link' => (string) $response->pdfLink,
            'payment_link' => (string) $response->paymentLink,
            'last_error' => '',
            'updated_age' => 0,
            'request_payload' => (string) json_encode($requestForm),
            'payload' => (string) json_encode($response->raw),
        ]));

        return true;
    }

    #[\Override]
    public function markFailed(int $orderId, string $errorMessage, array $requestForm, ?array $rawResponse = null): bool
    {
        $row = $this->rows[$orderId] ?? [];
        if (($row['status'] ?? null) !== Constants::STATUS_PENDING) {
            return false;
        }
        $this->put($orderId, array_replace($row, [
            'status' => Constants::STATUS_FAILED,
            'last_error' => $errorMessage,
            'retry_count' => (int) ($row['retry_count'] ?? 0) + 1,
            'updated_age' => 0,
            'request_payload' => (string) json_encode($requestForm),
            'payload' => $rawResponse !== null ? (string) json_encode($rawResponse) : '',
        ]));

        return true;
    }

    #[\Override]
    public function markCanceled(int $orderId): bool
    {
        return $this->fromIssued($orderId, Constants::STATUS_CANCELED);
    }

    #[\Override]
    public function markReversed(int $orderId): bool
    {
        return $this->fromIssued($orderId, Constants::STATUS_REVERSED);
    }

    #[\Override]
    public function markDeleted(int $orderId): bool
    {
        return $this->fromIssued($orderId, Constants::STATUS_DELETED);
    }

    #[\Override]
    public function markEmailed(int $orderId): bool
    {
        if (!isset($this->rows[$orderId])) {
            return false;
        }
        $this->rows[$orderId]['emailed_at'] = '2026-09-29 10:00:00';
        $this->rows[$orderId]['emailed_age'] = 0;

        return true;
    }

    #[\Override]
    public function attachAwb(int $orderId, string $awb): void
    {
        if (isset($this->rows[$orderId])) {
            $this->rows[$orderId]['awb'] = $awb;
        }
    }

    #[\Override]
    public function ensureSchema(): bool
    {
        return true;
    }

    private function fromIssued(int $orderId, string $status): bool
    {
        if (($this->rows[$orderId]['status'] ?? null) !== Constants::STATUS_ISSUED) {
            return false;
        }
        $this->rows[$orderId]['status'] = $status;

        return true;
    }
}
