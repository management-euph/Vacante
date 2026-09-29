<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Tests\Support;

use Tygh\Addons\FgoInvoicing\Repository\DiagnosticLogRepository;

/** ?:fgo_diagnostic_logs in memory: the rows InvoiceIssuer records, one per attempt. */
final class RecordingDiagnosticLog extends DiagnosticLogRepository
{
    /** @var list<array{order_id: int, code: string, message: string}> */
    public array $records = [];

    #[\Override]
    public function record(int $orderId, ?bool $cnpChecksumValid, ?int $cnpLength, string $responseCode, string $errorMessage, array $maskedForm): void
    {
        $this->records[] = ['order_id' => $orderId, 'code' => $responseCode, 'message' => $errorMessage];
    }

    /** @return list<string> */
    public function codes(): array
    {
        return array_column($this->records, 'code');
    }
}
