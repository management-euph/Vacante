<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Tests\Support;

use Tygh\Addons\FgoInvoicing\Api\FgoApiClient;

/**
 * FGO's issue endpoint with deduplication (VerificareDuplicat=true), whose
 * exact key FGO does not document. Two readings are modelled:
 *
 *   BY_REQUEST_ID  a repeated RequestId answers with the invoice it issued
 *                  before (what the add-on relies on);
 *   BY_ORDER       a repeated IdExtern (the order) does, whatever the
 *                  RequestId: the case where a re-issue gets the cancelled
 *                  invoice back.
 *
 * Every other call issues the next number. Cancelling does not make FGO
 * forget a document, as a real deduplication would not either.
 */
final class DedupingFgoApi extends FgoApiClient
{
    public const BY_REQUEST_ID = 'request_id';
    public const BY_ORDER = 'order';

    /** @var list<array<string, scalar|null>> every form received */
    public array $payloads = [];

    /** @var array<string, array{Serie: string, Numar: string, Link: string, LinkPlata: string}> */
    private array $issued = [];

    private int $next;

    public function __construct(private readonly string $dedupBy = self::BY_REQUEST_ID, int $firstNumber = 3)
    {
        $this->next = $firstNumber;
    }

    #[\Override]
    public function issueInvoice(array $payload): array
    {
        $this->payloads[] = $payload;
        $key = $this->dedupBy === self::BY_ORDER
            ? 'order:' . (string) ($payload['IdExtern'] ?? '')
            : 'request:' . (string) ($payload['RequestId'] ?? '');

        if (($payload['VerificareDuplicat'] ?? '') === 'true' && isset($this->issued[$key])) {
            return ['Success' => true, 'Message' => 'Factura existenta', 'Factura' => $this->issued[$key]];
        }

        $number = sprintf('%04d', $this->next++);
        $factura = ['Serie' => 'F', 'Numar' => $number, 'Link' => 'https://api-testuat.fgo.ro/pdf/' . $number, 'LinkPlata' => ''];
        $this->issued[$key] = $factura;

        return ['Success' => true, 'Message' => 'OK', 'Factura' => $factura];
    }

    #[\Override]
    public function cancelInvoice(string $invoiceSeries, string $invoiceNumber): array
    {
        return ['Success' => true];
    }

    #[\Override]
    public function stornoInvoice(string $invoiceSeries, string $invoiceNumber): array
    {
        return ['Success' => true];
    }

    #[\Override]
    public function deleteInvoice(string $invoiceSeries, string $invoiceNumber): array
    {
        return ['Success' => true];
    }

    /** @return list<string> */
    public function requestIds(): array
    {
        return array_map(static fn (array $p): string => (string) ($p['RequestId'] ?? ''), $this->payloads);
    }
}
