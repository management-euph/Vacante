<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\FgoInvoicing\Constants;
use Tygh\Addons\FgoInvoicing\Dto\Invoice\IssueInvoiceResponse;
use Tygh\Addons\FgoInvoicing\Services\BillingExtrasResolver;
use Tygh\Addons\FgoInvoicing\Services\BillingMapper;
use Tygh\Addons\FgoInvoicing\Services\ConfigProvider;
use Tygh\Addons\FgoInvoicing\Services\InvoiceCanceler;
use Tygh\Addons\FgoInvoicing\Services\InvoiceIssuer;
use Tygh\Addons\FgoInvoicing\Services\InvoiceMailer;
use Tygh\Addons\FgoInvoicing\Tests\Support\DedupingFgoApi;
use Tygh\Addons\FgoInvoicing\Tests\Support\FakeFgoApi;
use Tygh\Addons\FgoInvoicing\Tests\Support\InMemoryInvoiceRepository;
use Tygh\Addons\FgoInvoicing\Tests\Support\InMemoryProfileFieldCatalog;
use Tygh\Addons\FgoInvoicing\Tests\Support\LogStub;
use Tygh\Addons\FgoInvoicing\Tests\Support\RecordingDiagnosticLog;

/**
 * FGO is called only by the request that claimed the order's row, the
 * result lands only on that claim, and a re-issue after Anulare / Storno /
 * Ștergere is a new document, never the cancelled one saved again.
 */
#[CoversClass(InvoiceIssuer::class)]
final class InvoiceIssuerClaimTest extends TestCase
{
    private InMemoryInvoiceRepository $repo;
    private RecordingDiagnosticLog $diagnostics;

    /** @var list<array<string, mixed>> */
    private array $sent = [];

    protected function setUp(): void
    {
        ConfigProvider::seed([
            'invoice_type' => 'Factura',
            'invoice_series' => 'F',
            'verify_duplicate' => 'Y',
            'auto_email_pdf' => 'Y',
            'debug_logging' => 'N',
        ]);
        LogStub::reset();
        $this->repo = new InMemoryInvoiceRepository();
        $this->diagnostics = new RecordingDiagnosticLog();
        $this->sent = [];
    }

    protected function tearDown(): void
    {
        ConfigProvider::reset();
        LogStub::reset();
    }

    private function issuer(FakeFgoApi|DedupingFgoApi $api): InvoiceIssuer
    {
        $mailer = new InvoiceMailer(
            $this->repo,
            function (array $payload): bool {
                $this->sent[] = $payload;

                return true;
            },
            static fn (int $id): ?array => null,
        );

        return new InvoiceIssuer(
            $api,
            $this->repo,
            new BillingMapper('RON'),
            new BillingExtrasResolver(InMemoryProfileFieldCatalog::withDescriptions([])),
            $mailer,
            $this->diagnostics,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function order(): array
    {
        return [
            'order_id' => 5,
            'user_id' => 7,
            'b_firstname' => 'Ana',
            'b_lastname' => 'Pop',
            'b_country' => 'RO',
            'email' => 'ana@example.ro',
            'products' => [['product' => 'Sejur', 'amount' => 1, 'subtotal' => 100.0, 'tax_value' => 0.0]],
        ];
    }

    // ── The claim ───────────────────────────────────────────────────────

    /**
     * A pending row younger than the stale threshold is a request talking to
     * FGO right now. The second caller sends nothing and leaves no trace: no
     * diagnostic row, the row untouched.
     */
    public function testAFreshPendingRowIsLeftToTheRequestHoldingIt(): void
    {
        $this->repo->put(5, ['status' => 'pending', 'updated_age' => 30, 'updated_at' => '2026-09-29 10:00:00']);
        $before = $this->repo->rows[5];
        $api = new FakeFgoApi();

        $result = $this->issuer($api)->issueForOrder(5, self::order());

        self::assertSame(InvoiceIssuer::RESULT_IN_PROGRESS, $result['status']);
        self::assertStringContainsString('right now (since 2026-09-29 10:00:00)', $result['error'] ?? '');
        self::assertSame([], $api->calls, 'FGO is not called');
        self::assertSame([], $this->diagnostics->records, 'no diagnostic row for a request that sent nothing');
        self::assertSame($before, $this->repo->rows[5], 'the row is not touched');
        self::assertContains('[warn] issue-in-progress', LogStub::messages());
    }

    public function testAStalePendingRowIsTakenOver(): void
    {
        $this->repo->put(5, ['status' => 'pending', 'updated_age' => Constants::PENDING_STALE_SECONDS + 1]);
        $api = new FakeFgoApi();

        $result = $this->issuer($api)->issueForOrder(5, self::order());

        self::assertSame(Constants::STATUS_ISSUED, $result['status']);
        self::assertSame(1, $api->issued);
    }

    /**
     * Two requests read "no row" at the same time; the INSERT IGNORE lets only
     * one of them in. The one that lost sends nothing, and reports the invoice
     * when the winner has finished by then.
     */
    public function testTheRequestThatLostTheInsertSendsNothing(): void
    {
        $repo = new class () extends InMemoryInvoiceRepository {
            public string $winnerState = Constants::STATUS_PENDING;

            #[\Override]
            public function insertPending(int $orderId, ?int $cartId = null): array
            {
                $this->put($orderId, ['status' => $this->winnerState, 'invoice_series' => 'F', 'invoice_number' => '0001']);

                return ['id' => $orderId, 'isExisting' => true, 'status' => $this->winnerState, 'claimed' => false];
            }

            #[\Override]
            public function findByOrderId(int $orderId): ?array
            {
                return $this->insertCalled() ? parent::findByOrderId($orderId) : null;
            }

            private function insertCalled(): bool
            {
                return $this->rows !== [];
            }
        };
        $this->repo = $repo;
        $api = new FakeFgoApi();

        self::assertSame(InvoiceIssuer::RESULT_IN_PROGRESS, $this->issuer($api)->issueForOrder(5, self::order())['status']);

        $repo->rows = [];
        $repo->winnerState = Constants::STATUS_ISSUED;
        self::assertSame(
            ['status' => 'issued', 'invoice_id' => 'already-issued'],
            $this->issuer($api)->issueForOrder(5, self::order()),
        );
        self::assertSame([], $api->calls);
    }

    /**
     * FGO issued, but the claim was taken over meanwhile: the invoice number
     * must surface (answer, diagnostic log), never vanish, and the row that
     * is there now is not overwritten.
     */
    public function testAnInvoiceIssuedAfterTheClaimWasLostIsReportedNotDropped(): void
    {
        $repo = new class () extends InMemoryInvoiceRepository {
            #[\Override]
            public function markIssued(int $orderId, IssueInvoiceResponse $response, array $requestForm): bool
            {
                $this->put($orderId, ['status' => 'issued', 'invoice_series' => 'F', 'invoice_number' => '0001']);

                return false;
            }
        };
        $this->repo = $repo;

        $result = $this->issuer(new FakeFgoApi())->issueForOrder(5, self::order());

        self::assertSame(Constants::STATUS_FAILED, $result['status']);
        self::assertStringContainsString('FGO issued the invoice F 0003 for order #5', $result['error'] ?? '');
        self::assertStringContainsString('now: issued F 0001', $result['error'] ?? '');
        self::assertSame('0001', $repo->rows[5]['invoice_number'], 'the recorded invoice stays');
        self::assertSame(['success', 'conflict'], $this->diagnostics->codes());
        self::assertSame([], $this->sent, 'no e-mail for an invoice that is not recorded');
    }

    /**
     * A late failure must never turn another request's invoice into "failed".
     */
    public function testALateFailureDoesNotOverwriteAnIssuedRow(): void
    {
        $repo = new class () extends InMemoryInvoiceRepository {
            #[\Override]
            public function markFailed(int $orderId, string $errorMessage, array $requestForm, ?array $rawResponse = null): bool
            {
                $this->rows[$orderId]['status'] = Constants::STATUS_ISSUED; // someone issued meanwhile

                return parent::markFailed($orderId, $errorMessage, $requestForm, $rawResponse);
            }
        };
        $this->repo = $repo;
        $api = new FakeFgoApi();
        $api->issueError = 'HTTP 504';

        $result = $this->issuer($api)->issueForOrder(5, self::order());

        self::assertSame(Constants::STATUS_FAILED, $result['status']);
        self::assertSame(Constants::STATUS_ISSUED, $repo->rows[5]['status']);
        self::assertContains('[warn] failed-row-changed', LogStub::messages());
    }

    // ── Re-issue after Anulare / Storno / Ștergere ─────────────────────

    /**
     * FGO deduplicating by RequestId: the re-issue names the cancelled
     * invoice in its RequestId, so FGO issues a new document. The row, the
     * diagnostic log and a warning event say what it replaced; a retry of
     * the same re-issue keeps its RequestId.
     */
    public function testAReissueGetsANewInvoiceAndSaysWhichOneItReplaced(): void
    {
        $api = new DedupingFgoApi(DedupingFgoApi::BY_REQUEST_ID);
        $issuer = $this->issuer($api);

        self::assertSame('0003', $issuer->issueForOrder(5, self::order())['invoice_id'] ?? null);
        self::assertSame('ok', (new InvoiceCanceler($api, $this->repo))->cancel(5)['status']);
        self::assertSame(Constants::STATUS_CANCELED, $this->repo->rows[5]['status']);

        $this->sent = [];
        $result = $issuer->issueForOrder(5, self::order());

        self::assertSame(Constants::STATUS_ISSUED, $result['status']);
        self::assertSame('0004', $this->repo->rows[5]['invoice_number']);
        self::assertSame(Constants::STATUS_ISSUED, $this->repo->rows[5]['status']);
        [$firstId, $reissueId] = $api->requestIds();
        self::assertNotSame($firstId, $reissueId, 'a re-issue has its own RequestId');
        self::assertSame('true', $api->payloads[1]['VerificareDuplicat'], 'deduplication stays as configured');
        self::assertStringContainsString('replaces the invoice F 0003 (row status before this attempt: canceled) with F 0004', $this->diagnostics->records[1]['message']);
        self::assertContains('[warn] reissued', LogStub::messages());
        self::assertSame('0004', $this->sent[0]['invoice_number'] ?? null, 'the new invoice is e-mailed');
    }

    public function testTheRetriesOfOneReissueKeepItsRequestId(): void
    {
        $this->repo->put(5, ['status' => 'reversed', 'invoice_series' => 'F', 'invoice_number' => '0003']);
        $api = new FakeFgoApi();
        $api->issueError = 'HTTP 504';
        $recording = new DedupingFgoApi(DedupingFgoApi::BY_REQUEST_ID, 10);

        $this->issuer($api)->issueForOrder(5, self::order());
        self::assertSame(Constants::STATUS_FAILED, $this->repo->rows[5]['status']);
        self::assertSame('0003', $this->repo->rows[5]['invoice_number'], 'a failed re-issue still knows what it replaces');

        $this->issuer($recording)->issueForOrder(5, self::order());
        $this->repo->put(5, ['status' => 'failed', 'invoice_series' => 'F', 'invoice_number' => '0003']);
        $this->issuer($recording)->issueForOrder(5, self::order());

        [$a, $b] = $recording->requestIds();
        self::assertSame($a, $b);
        self::assertSame(
            (new BillingMapper('RON'))->mapOrderInfo(self::order(), 'F', '0003')->requestId,
            $a,
        );
    }

    /**
     * FGO deduplicating by order: the re-issue gets the cancelled F 0003
     * back. It must not be saved as issued nor e-mailed; the row fails with
     * what to do, and the attempt is in the diagnostic log.
     */
    public function testAReissueAnsweredWithTheCancelledInvoiceIsRefused(): void
    {
        $api = new DedupingFgoApi(DedupingFgoApi::BY_ORDER);
        $issuer = $this->issuer($api);
        $issuer->issueForOrder(5, self::order());
        (new InvoiceCanceler($api, $this->repo))->storno(5);
        $this->sent = [];

        $result = $issuer->issueForOrder(5, self::order());

        self::assertSame(Constants::STATUS_FAILED, $result['status']);
        $error = $result['error'] ?? '';
        self::assertStringContainsString('FGO returned the invoice F 0003 again', $error);
        self::assertStringContainsString('Issue the new invoice in FGO manually, or ask FGO support', $error);
        $row = $this->repo->rows[5];
        self::assertSame(Constants::STATUS_FAILED, $row['status']);
        self::assertSame($error, $row['last_error']);
        self::assertSame(['F', '0003'], [$row['invoice_series'], $row['invoice_number']], 'still the invoice a re-issue replaces');
        self::assertSame([], $this->sent, 'the cancelled PDF is not e-mailed');
        self::assertSame(['success', 'reissue/same-invoice'], $this->diagnostics->codes());
        self::assertContains('[error] reissue-returned-previous', LogStub::messages());
    }

    public function testAFailedFirstIssueIsNoReissue(): void
    {
        $this->repo->put(5, ['status' => 'failed', 'invoice_series' => '', 'invoice_number' => '']);
        $api = new DedupingFgoApi();

        $this->issuer($api)->issueForOrder(5, self::order());

        self::assertSame((new BillingMapper('RON'))->mapOrderInfo(self::order())->requestId, $api->requestIds()[0]);
        self::assertNotContains('[warn] reissued', LogStub::messages());
    }
}
