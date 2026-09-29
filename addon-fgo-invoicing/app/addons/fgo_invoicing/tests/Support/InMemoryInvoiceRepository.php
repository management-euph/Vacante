<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Tests\Support;

use Tygh\Addons\FgoInvoicing\Constants;
use Tygh\Addons\FgoInvoicing\Dto\Invoice\IssueInvoiceResponse;
use Tygh\Addons\FgoInvoicing\Repository\InvoiceRepository;

/**
 * ?:fgo_invoices in memory, for the services that read and write it
 * (issuer, canceler, mailer, bulk runner, orders-list column).
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
                $out[$id] = $this->rows[$id];
            }
        }

        return $out;
    }

    #[\Override]
    public function insertPending(int $orderId, ?int $cartId = null): array
    {
        if (isset($this->rows[$orderId])) {
            return ['id' => $orderId, 'isExisting' => true, 'status' => (string) $this->rows[$orderId]['status']];
        }
        $this->put($orderId, ['status' => Constants::STATUS_PENDING]);

        return ['id' => $orderId, 'isExisting' => false, 'status' => Constants::STATUS_PENDING];
    }

    #[\Override]
    public function markIssued(int $orderId, IssueInvoiceResponse $response, array $requestForm): void
    {
        $this->put($orderId, array_replace($this->rows[$orderId] ?? [], [
            'status' => Constants::STATUS_ISSUED,
            'invoice_series' => (string) $response->invoiceSeries,
            'invoice_number' => (string) $response->invoiceNumber,
            'pdf_link' => (string) $response->pdfLink,
            'payment_link' => (string) $response->paymentLink,
            'last_error' => '',
        ]));
    }

    #[\Override]
    public function markFailed(int $orderId, string $errorMessage, array $requestForm, ?array $rawResponse = null): void
    {
        $row = $this->rows[$orderId] ?? [];
        $this->put($orderId, array_replace($row, [
            'status' => Constants::STATUS_FAILED,
            'last_error' => $errorMessage,
            'retry_count' => (int) ($row['retry_count'] ?? 0) + 1,
        ]));
    }

    #[\Override]
    public function markCanceled(int $orderId): void
    {
        $this->rows[$orderId]['status'] = Constants::STATUS_CANCELED;
    }

    #[\Override]
    public function markReversed(int $orderId): void
    {
        $this->rows[$orderId]['status'] = Constants::STATUS_REVERSED;
    }

    #[\Override]
    public function markDeleted(int $orderId): void
    {
        $this->rows[$orderId]['status'] = Constants::STATUS_DELETED;
    }
}
