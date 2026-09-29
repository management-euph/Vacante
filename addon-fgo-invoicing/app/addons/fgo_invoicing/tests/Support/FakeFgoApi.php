<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Tests\Support;

use Tygh\Addons\FgoInvoicing\Api\FgoApiClient;
use Tygh\Addons\FgoInvoicing\Api\FgoApiException;

/**
 * FGO without the network: records every call; an error message set on
 * $issueError / $invoiceActionError makes that call fail as FGO would
 * (FgoApiException with FGO's Message).
 */
final class FakeFgoApi extends FgoApiClient
{
    /** @var list<array{string, string, string}> [method, series, number] */
    public array $calls = [];

    public int $issued = 0;

    public ?string $issueError = null;

    public ?string $invoiceActionError = null;

    public function __construct(private readonly string $series = 'F', private readonly int $firstNumber = 3)
    {
    }

    #[\Override]
    public function check(): array
    {
        return ['Success' => true];
    }

    #[\Override]
    public function issueInvoice(array $payload): array
    {
        $this->calls[] = ['issueInvoice', '', ''];
        if ($this->issueError !== null) {
            throw new FgoApiException($this->issueError);
        }
        $number = sprintf('%04d', $this->firstNumber + $this->issued);
        $this->issued++;

        return [
            'Success' => true,
            'Message' => 'OK',
            'Factura' => [
                'Numar' => $number,
                'Serie' => $this->series,
                'Link' => 'https://api-testuat.fgo.ro/pdf/' . $number,
                'LinkPlata' => '',
            ],
        ];
    }

    #[\Override]
    public function cancelInvoice(string $invoiceSeries, string $invoiceNumber): array
    {
        return $this->invoiceAction('cancelInvoice', $invoiceSeries, $invoiceNumber);
    }

    #[\Override]
    public function stornoInvoice(string $invoiceSeries, string $invoiceNumber): array
    {
        return $this->invoiceAction('stornoInvoice', $invoiceSeries, $invoiceNumber);
    }

    #[\Override]
    public function deleteInvoice(string $invoiceSeries, string $invoiceNumber): array
    {
        return $this->invoiceAction('deleteInvoice', $invoiceSeries, $invoiceNumber);
    }

    #[\Override]
    public function attachAwb(string $invoiceSeries, string $invoiceNumber, string $awb): array
    {
        return $this->invoiceAction('attachAwb', $invoiceSeries, $invoiceNumber);
    }

    /**
     * @return array<string, mixed>
     */
    private function invoiceAction(string $method, string $series, string $number): array
    {
        $this->calls[] = [$method, $series, $number];
        if ($this->invoiceActionError !== null) {
            throw new FgoApiException($this->invoiceActionError);
        }

        return ['Success' => true];
    }
}
