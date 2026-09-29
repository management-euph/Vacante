<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\FgoInvoicing\Api\FgoApiClient;
use Tygh\Addons\FgoInvoicing\Api\FgoApiException;
use Tygh\Addons\FgoInvoicing\Constants;
use Tygh\Addons\FgoInvoicing\Dto\Invoice\IssueInvoiceResponse;
use Tygh\Addons\FgoInvoicing\Repository\DiagnosticLogRepository;
use Tygh\Addons\FgoInvoicing\Services\BillingExtrasResolver;
use Tygh\Addons\FgoInvoicing\Services\BillingMapper;
use Tygh\Addons\FgoInvoicing\Services\ConfigProvider;
use Tygh\Addons\FgoInvoicing\Services\InvoiceIssuer;
use Tygh\Addons\FgoInvoicing\Tests\Support\InMemoryInvoiceRepository;
use Tygh\Addons\FgoInvoicing\Tests\Support\InMemoryProfileFieldCatalog;
use Tygh\Addons\FgoInvoicing\Tests\Support\LogStub;

/**
 * An individual's CNP must reach FGO, and no copy the add-on keeps may hold
 * it: the request/response in ?:fgo_invoices, the error text, and the
 * per-attempt row in ?:fgo_diagnostic_logs carry it masked. That row also
 * records the CNP's checksum verdict and typed length, and how FGO answered.
 */
#[CoversClass(InvoiceIssuer::class)]
final class InvoiceIssuerPrivacyTest extends TestCase
{
    private const SETTINGS = [
        'invoice_type' => 'Factura',
        'invoice_series' => 'F',
        'verify_duplicate' => 'Y',
        'sanitize_vat' => 'N',
        'article_id_field' => 'sku',
        'shipping_tax_vat' => 'vat_included',
        'auto_email_pdf' => 'N',
        'debug_logging' => 'N',
    ];

    protected function setUp(): void
    {
        ConfigProvider::seed(self::SETTINGS);
        LogStub::reset();
    }

    protected function tearDown(): void
    {
        ConfigProvider::reset();
        LogStub::reset();
    }

    /** A CNP with a correct check digit, derived here rather than invented. */
    private static function validCnp(string $first12 = '198051212345'): string
    {
        $key = '279146358279';
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int) $first12[$i] * (int) $key[$i];
        }
        $check = $sum % 11;

        return $first12 . ($check === 10 ? '1' : (string) $check);
    }

    private static function masked(string $cnp): string
    {
        return substr($cnp, 0, 5) . '******' . substr($cnp, -2);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function order(array $overrides = []): array
    {
        return array_replace([
            'order_id' => 1234,
            'user_id' => 7,
            'b_firstname' => 'Ion',
            'b_lastname' => 'Popescu',
            'b_country' => 'RO',
            'b_city' => 'Cluj-Napoca',
            'email' => 'ion@example.ro',
            'subtotal' => 100.0,
            'products' => [['product' => 'Sejur', 'amount' => 1, 'subtotal' => 100.0, 'tax_value' => 0.0]],
        ], $overrides);
    }

    /**
     * @return array{InvoiceIssuer, RecordingRepository, RecordingDiagnostics}
     */
    private static function issuer(FgoApiClient $api, array $fieldDescriptions = []): array
    {
        $repo = new RecordingRepository();
        $diagnostics = new RecordingDiagnostics();
        $issuer = new InvoiceIssuer(
            $api,
            $repo,
            new BillingMapper('RON'),
            new BillingExtrasResolver(InMemoryProfileFieldCatalog::withDescriptions($fieldDescriptions)),
            null,
            $diagnostics,
        );

        return [$issuer, $repo, $diagnostics];
    }

    public function testFgoGetsTheCnpButTheStoredCopiesDoNot(): void
    {
        $cnp = self::validCnp();
        $api = new EchoingApi(['Success' => true, 'Message' => 'Factura emisa pentru CNP ' . $cnp]);
        [$issuer, $repo, $diagnostics] = self::issuer($api);

        $result = $issuer->issueForOrder(1234, self::order(['fgo_billing_cnp' => $cnp]));

        self::assertSame(Constants::STATUS_ISSUED, $result['status']);
        self::assertSame($cnp, $api->lastPayload['Client[CodUnic]'] ?? null, 'FGO receives the real CNP');

        self::assertSame(self::masked($cnp), $repo->issuedForm['Client[CodUnic]'] ?? null);
        self::assertStringNotContainsString($cnp, (string) json_encode($repo->issuedRaw));
        self::assertStringContainsString(self::masked($cnp), (string) json_encode($repo->issuedRaw));

        self::assertCount(1, $diagnostics->records);
        $row = $diagnostics->records[0];
        self::assertSame([1234, true, 13, 'success', ''], [$row['order_id'], $row['valid'], $row['length'], $row['code'], $row['message']]);
        self::assertSame(self::masked($cnp), $row['form']['Client[CodUnic]'] ?? null);
    }

    public function testTheLengthIsOfTheValueAsTypedAndTheChecksumIsReported(): void
    {
        $cnp = self::validCnp();
        [$issuer, , $diagnostics] = self::issuer(new EchoingApi(['Success' => true]));
        $issuer->issueForOrder(1234, self::order(['fgo_billing_cnp' => substr($cnp, 0, 7) . ' ' . substr($cnp, 7)]));
        self::assertSame([true, 14], [$diagnostics->records[0]['valid'], $diagnostics->records[0]['length']], 'a stray space shows in the length');

        $wrongCheck = substr($cnp, 0, 12) . (string) (((int) substr($cnp, -1) + 1) % 10);
        [$issuer, , $diagnostics] = self::issuer(new EchoingApi(['Success' => true]));
        $issuer->issueForOrder(1234, self::order(['fgo_billing_cnp' => $wrongCheck]));
        self::assertSame([false, 13], [$diagnostics->records[0]['valid'], $diagnostics->records[0]['length']]);
    }

    public function testACompanysCifIsKeptAndNoCnpFactsAreRecorded(): void
    {
        [$issuer, $repo, $diagnostics] = self::issuer(new EchoingApi(['Success' => true]));

        $issuer->issueForOrder(1234, self::order(['company' => 'ACME SRL', 'fgo_billing_cui' => 'RO12345678']));

        self::assertSame('12345678', $repo->issuedForm['Client[CodUnic]'] ?? null);
        self::assertSame([null, null, 'success'], [$diagnostics->records[0]['valid'], $diagnostics->records[0]['length'], $diagnostics->records[0]['code']]);
    }

    public function testAnFgoRejectionIsStoredMaskedWithItsCode(): void
    {
        $cnp = self::validCnp();
        $api = new ThrowingApi(new FgoApiException('CNP ' . $cnp . ' nu este valid', ['Success' => false, 'Message' => 'CNP ' . $cnp . ' nu este valid'], 200));
        [$issuer, $repo, $diagnostics] = self::issuer($api);

        $result = $issuer->issueForOrder(1234, self::order(['fgo_billing_cnp' => $cnp]));

        self::assertSame(Constants::STATUS_FAILED, $result['status']);
        self::assertSame('CNP ' . self::masked($cnp) . ' nu este valid', $result['error'] ?? null, 'the page never shows the CNP either');
        self::assertSame('CNP ' . self::masked($cnp) . ' nu este valid', $repo->failedMessage);
        self::assertSame(self::masked($cnp), $repo->failedForm['Client[CodUnic]'] ?? null);
        self::assertStringNotContainsString($cnp, (string) json_encode($repo->failedRaw));
        self::assertSame('rejected/200', $diagnostics->records[0]['code']);
        self::assertSame('CNP ' . self::masked($cnp) . ' nu este valid', $diagnostics->records[0]['message']);
        foreach (LogStub::$events as $event) {
            self::assertStringNotContainsString($cnp, (string) json_encode($event), 'nor does the event log');
        }
    }

    public function testHttpAndNetworkFailuresGetTheirOwnCodes(): void
    {
        [$issuer, , $diagnostics] = self::issuer(new ThrowingApi(new FgoApiException('FGO HTTP failure on factura/emitere: Server error HTTP 503', null, 503)));
        $issuer->issueForOrder(1234, self::order());
        self::assertSame('http/503', $diagnostics->records[0]['code']);

        [$issuer, , $diagnostics] = self::issuer(new ThrowingApi(new FgoApiException('FGO HTTP failure on factura/emitere: cURL error: timeout')));
        $issuer->issueForOrder(1234, self::order());
        self::assertSame('network', $diagnostics->records[0]['code']);
    }

    public function testABlockedAttemptIsRecordedAndSendsNothing(): void
    {
        ConfigProvider::seed(self::SETTINGS + ['client_cnp_required' => 'Y']);
        $api = new EchoingApi(['Success' => true]);
        [$issuer, $repo, $diagnostics] = self::issuer($api, [40 => 'CNP']);

        $result = $issuer->issueForOrder(1234, self::order());

        self::assertSame(Constants::STATUS_FAILED, $result['status']);
        self::assertNull($api->lastPayload, 'FGO was not called');
        self::assertSame([], $repo->failedForm);
        self::assertSame('blocked', $diagnostics->records[0]['code']);
        self::assertSame([], $diagnostics->records[0]['form']);
    }

    public function testAnUnexpectedErrorIsRecorded(): void
    {
        [$issuer, , $diagnostics] = self::issuer(new EchoingApi(['Success' => true]));

        // order_info without an order id makes the mapper throw.
        $result = $issuer->issueForOrder(1234, self::order(['order_id' => 0]));

        self::assertSame(Constants::STATUS_FAILED, $result['status']);
        self::assertSame('error', $diagnostics->records[0]['code']);
        self::assertSame([null, null], [$diagnostics->records[0]['valid'], $diagnostics->records[0]['length']]);
    }
}

final class RecordingRepository extends InMemoryInvoiceRepository
{
    /** @var array<string, scalar|null> */
    public array $issuedForm = [];

    /** @var array<string, mixed> */
    public array $issuedRaw = [];

    /** @var array<string, scalar|null>|null */
    public ?array $failedForm = null;

    public string $failedMessage = '';

    /** @var array<string, mixed>|null */
    public ?array $failedRaw = null;

    #[\Override]
    public function markIssued(int $orderId, IssueInvoiceResponse $response, array $requestForm): void
    {
        $this->issuedForm = $requestForm;
        $this->issuedRaw = $response->raw;
        parent::markIssued($orderId, $response, $requestForm);
    }

    #[\Override]
    public function markFailed(int $orderId, string $errorMessage, array $requestForm, ?array $rawResponse = null): void
    {
        $this->failedForm = $requestForm;
        $this->failedMessage = $errorMessage;
        $this->failedRaw = $rawResponse;
        parent::markFailed($orderId, $errorMessage, $requestForm, $rawResponse);
    }
}

final class RecordingDiagnostics extends DiagnosticLogRepository
{
    /** @var list<array{order_id: int, valid: ?bool, length: ?int, code: string, message: string, form: array<string, scalar|null>}> */
    public array $records = [];

    #[\Override]
    public function record(int $orderId, ?bool $cnpChecksumValid, ?int $cnpLength, string $responseCode, string $errorMessage, array $maskedForm): void
    {
        $this->records[] = [
            'order_id' => $orderId,
            'valid' => $cnpChecksumValid,
            'length' => $cnpLength,
            'code' => $responseCode,
            'message' => $errorMessage,
            'form' => $maskedForm,
        ];
    }
}

/** Answers every issue call with the given body plus an invoice. */
final class EchoingApi extends FgoApiClient
{
    /** @var array<string, scalar|null>|null */
    public ?array $lastPayload = null;

    /**
     * @param array<string, mixed> $body
     */
    public function __construct(private readonly array $body)
    {
    }

    #[\Override]
    public function issueInvoice(array $payload): array
    {
        $this->lastPayload = $payload;

        return $this->body + ['Factura' => ['Numar' => '0001', 'Serie' => 'F', 'Link' => 'https://api-testuat.fgo.ro/pdf/1']];
    }
}

final class ThrowingApi extends FgoApiClient
{
    public function __construct(private readonly FgoApiException $error)
    {
    }

    #[\Override]
    public function issueInvoice(array $payload): array
    {
        throw $this->error;
    }
}
