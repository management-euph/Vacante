<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Tests\Unit\Services\Bulk;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\FgoInvoicing\Constants;
use Tygh\Addons\FgoInvoicing\Services\BillingExtrasResolver;
use Tygh\Addons\FgoInvoicing\Services\BillingMapper;
use Tygh\Addons\FgoInvoicing\Services\Bulk\BulkAction;
use Tygh\Addons\FgoInvoicing\Services\Bulk\BulkPrecheck;
use Tygh\Addons\FgoInvoicing\Services\Bulk\BulkRunner;
use Tygh\Addons\FgoInvoicing\Services\Bulk\BulkRunResult;
use Tygh\Addons\FgoInvoicing\Services\Bulk\PrecheckReason;
use Tygh\Addons\FgoInvoicing\Services\ConfigProvider;
use Tygh\Addons\FgoInvoicing\Services\InvoiceCanceler;
use Tygh\Addons\FgoInvoicing\Services\InvoiceIssuer;
use Tygh\Addons\FgoInvoicing\Services\InvoiceMailer;
use Tygh\Addons\FgoInvoicing\Tests\Support\FakeFgoApi;
use Tygh\Addons\FgoInvoicing\Tests\Support\InMemoryInvoiceRepository;
use Tygh\Addons\FgoInvoicing\Tests\Support\InMemoryProfileFieldCatalog;
use Tygh\Addons\FgoInvoicing\Tests\Support\LogStub;

/**
 * bulk_run acts on one order per request. The page it is called from may be
 * stale or tampered with, so the runner re-checks the order as it is now and
 * only then calls FGO (through the real issuer / canceler / mailer, with the
 * API, the table and the mail transport replaced).
 */
#[CoversClass(BulkRunner::class)]
#[CoversClass(BulkRunResult::class)]
final class BulkRunnerTest extends TestCase
{
    private FakeFgoApi $api;
    private InMemoryInvoiceRepository $repo;

    /** @var array<int, array<string, mixed>> */
    private array $orders = [];

    /** @var list<array<string, mixed>> */
    private array $sent = [];

    private bool $mailerAccepts = true;

    protected function setUp(): void
    {
        ConfigProvider::seed(['invoice_type' => 'Factura', 'invoice_series' => 'F', 'auto_email_pdf' => 'N', 'debug_logging' => 'N']);
        LogStub::reset();
        $this->api = new FakeFgoApi();
        $this->repo = new InMemoryInvoiceRepository();
        $this->orders = [5 => self::order(5), 6 => self::order(6)];
        $this->sent = [];
        $this->mailerAccepts = true;
    }

    protected function tearDown(): void
    {
        ConfigProvider::reset();
        LogStub::reset();
    }

    /**
     * @return array<string, mixed>
     */
    private static function order(int $id, array $overrides = []): array
    {
        return array_replace([
            'order_id' => $id,
            'status' => 'P',
            'total' => 100.0,
            'email' => 'client' . $id . '@example.ro',
            'lang_code' => 'ro',
            'company_id' => 1,
            'b_firstname' => 'Ana',
            'b_lastname' => 'Pop',
            'b_country' => 'RO',
            'products' => [['product' => 'Sejur', 'amount' => 1, 'subtotal' => 100.0, 'tax_value' => 0]],
        ], $overrides);
    }

    private function runner(bool $vatRequired = false, bool $cifSource = false, ?\Closure $loader = null): BulkRunner
    {
        $resolver = new BillingExtrasResolver(new InMemoryProfileFieldCatalog());
        $loader ??= fn (int $id): ?array => $this->orders[$id] ?? null;
        $mailer = new InvoiceMailer(
            $this->repo,
            function (array $payload): bool {
                $this->sent[] = $payload;

                return $this->mailerAccepts;
            },
            $loader,
        );
        $issuer = new InvoiceIssuer($this->api, $this->repo, new BillingMapper('RON'), $resolver, $mailer);

        return new BulkRunner(
            precheck: new BulkPrecheck(new BillingMapper('RON'), $vatRequired, false, $cifSource, false),
            resolver: $resolver,
            repo:     $this->repo,
            issuer:   $issuer,
            canceler: new InvoiceCanceler($this->api, $this->repo),
            mailer:   $mailer,
            orderLoader: $loader,
        );
    }

    /**
     * @return list<string>
     */
    private static function codes(BulkRunResult $result): array
    {
        return array_map(static fn (PrecheckReason $r): string => $r->code, $result->reasons);
    }

    // ── Issue / Retry ────────────────────────────────────────────────────

    public function testAReadyOrderIsIssued(): void
    {
        $result = $this->runner()->run(BulkAction::Issue, 5);

        self::assertSame(BulkRunResult::OUTCOME_ISSUED, $result->outcome);
        self::assertSame('F', $result->invoiceSeries);
        self::assertSame('0003', $result->invoiceNumber);
        self::assertSame('https://api-testuat.fgo.ro/pdf/0003', $result->pdfLink);
        self::assertSame('off', $result->emailStatus, 'the page asked for no e-mail');
        self::assertSame(1, $this->api->issued);
        self::assertSame(Constants::STATUS_ISSUED, $this->repo->rows[5]['status']);
        self::assertSame([], $this->sent);
    }

    public function testTheEmailBoxOverridesTheSetting(): void
    {
        $result = $this->runner()->run(BulkAction::Issue, 5, true);

        self::assertSame('sent', $result->emailStatus);
        self::assertCount(1, $this->sent);
        self::assertSame('client5@example.ro', $this->sent[0]['to']);
        self::assertSame('https://api-testuat.fgo.ro/pdf/0003', $this->sent[0]['pdf_link']);
        self::assertSame('ro', $this->sent[0]['lang_code']);
    }

    /**
     * REGRESSION GUARD: the page listed the order as "ready", but it was
     * invoiced in the meantime (status hook, second tab, double click). The
     * server must not trust the page: skipped, and FGO is not called.
     */
    public function testAnOrderIssuedSinceThePageWasDrawnIsSkipped(): void
    {
        $this->repo->put(5, ['status' => 'issued', 'invoice_series' => 'F', 'invoice_number' => '0002', 'pdf_link' => 'https://api.fgo.ro/p/2']);

        $result = $this->runner()->run(BulkAction::Issue, 5);

        self::assertSame(BulkRunResult::OUTCOME_SKIPPED, $result->outcome);
        self::assertSame(['already_invoiced'], self::codes($result));
        self::assertSame('0002', $result->invoiceNumber);
        self::assertSame('https://api.fgo.ro/p/2', $result->pdfLink);
        self::assertSame([], $this->api->calls);
    }

    public function testABlockedOrderFailsWithTheReasonAndFgoIsNotCalled(): void
    {
        $this->orders[5] = self::order(5, ['company' => 'SC ACME SRL']);

        $result = $this->runner(vatRequired: true, cifSource: true)->run(BulkAction::Issue, 5);

        self::assertSame(BulkRunResult::OUTCOME_FAILED, $result->outcome);
        self::assertSame(['pj_without_cif_required'], self::codes($result));
        self::assertSame('', $result->message);
        self::assertSame([], $this->api->calls);
        self::assertArrayNotHasKey(5, $this->repo->rows, 'nothing recorded for an order that was never sent');
    }

    /**
     * A warning (here: an incomplete order, unticked by default) is the
     * admin's call: when they tick it, it goes.
     */
    public function testAWarningDoesNotStopAnOrderTheAdminTicked(): void
    {
        $this->orders[5] = self::order(5, ['status' => 'N']);

        $result = $this->runner()->run(BulkAction::Issue, 5, false, 'warn', ['order_status']);

        self::assertSame(BulkRunResult::OUTCOME_ISSUED, $result->outcome);
    }

    /**
     * The page showed the order as ready; since then it turned `warn` (here:
     * its status went back to Incomplete). Nobody decided to send that, so
     * it is skipped with the new warning, and FGO is not called.
     */
    public function testAWarningThePageDidNotShowStopsTheOrder(): void
    {
        $this->orders[5] = self::order(5, ['status' => 'N']);

        $result = $this->runner()->run(BulkAction::Issue, 5, false, 'ready', []);

        self::assertSame(BulkRunResult::OUTCOME_SKIPPED, $result->outcome);
        self::assertSame(['changed_since_precheck', 'order_status'], self::codes($result));
        self::assertSame([], $this->api->calls);
    }

    /**
     * Shown as warn, but for another reason: the new warning was not seen.
     * A warning that went away is no reason to stop.
     */
    public function testOnlyTheWarningsThePageShowedCount(): void
    {
        $this->orders[5] = self::order(5, ['status' => 'N', 'total' => 0]);

        $new = $this->runner()->run(BulkAction::Issue, 5, false, 'warn', ['order_status']);
        self::assertSame(['changed_since_precheck', 'zero_total'], self::codes($new));

        $fewer = $this->runner()->run(BulkAction::Issue, 5, false, 'warn', ['order_status', 'zero_total', 'cif_invalid']);
        self::assertSame(BulkRunResult::OUTCOME_ISSUED, $fewer->outcome);
    }

    public function testAReadyOrRetryVerdictNeedsNoSeenWarning(): void
    {
        $this->repo->put(5, ['status' => 'failed', 'last_error' => 'HTTP 504']);

        self::assertSame(BulkRunResult::OUTCOME_ISSUED, $this->runner()->run(BulkAction::Issue, 5, false, 'ready', [])->outcome);
    }

    /**
     * Another request holds the order's claim (auto-issue from a status
     * change, a second tab): nothing is sent, the row says why.
     */
    public function testAnOrderBeingIssuedRightNowIsSkipped(): void
    {
        $this->repo->put(5, ['status' => 'pending', 'updated_age' => 5]);

        $result = $this->runner()->run(BulkAction::Issue, 5, false, 'ready', []);

        self::assertSame(BulkRunResult::OUTCOME_SKIPPED, $result->outcome);
        self::assertSame(['in_progress'], self::codes($result));
        self::assertSame([], $this->api->calls);
    }

    /**
     * The pre-check saw a claimable row, but the claim went to another
     * request in between: the issuer's in_progress becomes a skip too.
     */
    public function testALostClaimIsReportedAsInProgress(): void
    {
        $repo = new class () extends InMemoryInvoiceRepository {
            #[\Override]
            public function claimForRetry(int $orderId, int $staleSeconds = Constants::PENDING_STALE_SECONDS): bool
            {
                $this->rows[$orderId]['status'] = Constants::STATUS_PENDING;

                return false;
            }
        };
        $repo->put(5, ['status' => 'failed']);
        $this->repo = $repo;

        $result = $this->runner()->run(BulkAction::Issue, 5, false, 'retry', ['last_error']);

        self::assertSame(BulkRunResult::OUTCOME_SKIPPED, $result->outcome);
        self::assertSame(['in_progress'], self::codes($result));
        self::assertSame([], $this->api->calls);
    }

    public function testAnFgoRejectionFailsWithFgosMessage(): void
    {
        $this->api->issueError = 'CIF invalid';

        $result = $this->runner()->run(BulkAction::Issue, 5);

        self::assertSame(BulkRunResult::OUTCOME_FAILED, $result->outcome);
        self::assertSame('CIF invalid', $result->message);
        self::assertSame(Constants::STATUS_FAILED, $this->repo->rows[5]['status']);
    }

    public function testRetryReissuesAFailedOrder(): void
    {
        $this->repo->put(5, ['status' => 'failed', 'last_error' => 'HTTP 504']);

        $result = $this->runner()->run(BulkAction::Retry, 5);

        self::assertSame(BulkRunResult::OUTCOME_ISSUED, $result->outcome);
        self::assertSame(1, $this->api->issued);
    }

    public function testRetrySkipsAnOrderThatNeverFailed(): void
    {
        $result = $this->runner()->run(BulkAction::Retry, 5);

        self::assertSame(BulkRunResult::OUTCOME_SKIPPED, $result->outcome);
        self::assertSame(['not_failed'], self::codes($result));
        self::assertSame([], $this->api->calls);
    }

    /**
     * Between the runner's re-check and the issuer's own look at the row,
     * another request issued it: the issuer answers "already issued", which
     * is not this run's invoice.
     */
    public function testAnInvoiceIssuedByARacingRequestIsReportedSkipped(): void
    {
        $repo = new class () extends InMemoryInvoiceRepository {
            private bool $hidden = true;

            #[\Override]
            public function findByOrderId(int $orderId): ?array
            {
                if ($this->hidden) {
                    $this->hidden = false;

                    return null;
                }

                return parent::findByOrderId($orderId);
            }
        };
        $repo->put(5, ['status' => 'issued', 'invoice_series' => 'F', 'invoice_number' => '0009']);
        $this->repo = $repo;

        $result = $this->runner()->run(BulkAction::Issue, 5);

        self::assertSame(BulkRunResult::OUTCOME_SKIPPED, $result->outcome);
        self::assertSame(['already_invoiced'], self::codes($result));
        self::assertSame(['[invoice]' => 'F 0009'], $result->reasons[0]->params);
        self::assertSame([], $this->api->calls);
    }

    public function testAnInvoiceIssuedByARacingRequestWithoutANumberSaysDash(): void
    {
        $repo = new class () extends InMemoryInvoiceRepository {
            private bool $hidden = true;

            #[\Override]
            public function findByOrderId(int $orderId): ?array
            {
                if ($this->hidden) {
                    $this->hidden = false;

                    return null;
                }

                return parent::findByOrderId($orderId);
            }
        };
        $repo->put(5, ['status' => 'issued']);
        $this->repo = $repo;

        $result = $this->runner()->run(BulkAction::Issue, 5);

        self::assertSame(['[invoice]' => '—'], $result->reasons[0]->params);
    }

    // ── Email ────────────────────────────────────────────────────────────

    public function testEmailSendsTheIssuedInvoice(): void
    {
        $this->repo->put(5, ['status' => 'issued', 'invoice_series' => 'F', 'invoice_number' => '0002', 'pdf_link' => 'https://api.fgo.ro/p/2', 'payment_link' => 'https://pay.fgo.ro/2']);

        $result = $this->runner()->run(BulkAction::Email, 5);

        self::assertSame(BulkRunResult::OUTCOME_DONE, $result->outcome);
        self::assertSame('sent', $result->emailStatus);
        self::assertSame('F', $result->invoiceSeries);
        self::assertCount(1, $this->sent);
        self::assertSame('https://pay.fgo.ro/2', $this->sent[0]['payment_link']);
        self::assertSame([], $this->api->calls, 'e-mailing never calls FGO');
    }

    public function testEmailRecordsWhenItWasSent(): void
    {
        $this->repo->put(5, ['status' => 'issued', 'invoice_series' => 'F', 'invoice_number' => '0002', 'pdf_link' => 'https://api.fgo.ro/p/2']);

        $this->runner()->run(BulkAction::Email, 5);

        self::assertNotNull($this->repo->rows[5]['emailed_at']);
    }

    /**
     * Sent within the day: unticked on the page; when the admin ticks it the
     * page reports the warning as seen and it goes; otherwise it is skipped.
     */
    public function testARecentlyEmailedInvoiceGoesOnlyWhenTheAdminSawTheWarning(): void
    {
        $this->repo->put(5, ['status' => 'issued', 'invoice_series' => 'F', 'invoice_number' => '0002', 'pdf_link' => 'https://api.fgo.ro/p/2', 'emailed_at' => '2026-09-29 09:00:00', 'emailed_age' => 600]);

        self::assertSame(['changed_since_precheck', 'recently_emailed'], self::codes($this->runner()->run(BulkAction::Email, 5, false, 'ready', [])));
        self::assertSame([], $this->sent);
        self::assertSame(BulkRunResult::OUTCOME_DONE, $this->runner()->run(BulkAction::Email, 5, false, 'warn', ['recently_emailed'])->outcome);
        self::assertCount(1, $this->sent);
    }

    public function testEmailFailsWhenTheMailerRefuses(): void
    {
        $this->repo->put(5, ['status' => 'issued', 'invoice_series' => 'F', 'invoice_number' => '0002', 'pdf_link' => 'https://api.fgo.ro/p/2']);
        $this->mailerAccepts = false;

        $result = $this->runner()->run(BulkAction::Email, 5);

        self::assertSame(BulkRunResult::OUTCOME_FAILED, $result->outcome);
        self::assertStringContainsString('did not send', $result->message);
    }

    public function testEmailSkipsAnOrderWithoutInvoice(): void
    {
        $result = $this->runner()->run(BulkAction::Email, 5);

        self::assertSame(BulkRunResult::OUTCOME_SKIPPED, $result->outcome);
        self::assertSame(['not_invoiced'], self::codes($result));
        self::assertSame([], $this->sent);
    }

    // ── Cancel / Storno / Delete ─────────────────────────────────────────

    /**
     * @return iterable<string, array{BulkAction, string, string}>
     */
    public static function invoiceActions(): iterable
    {
        yield 'cancel' => [BulkAction::Cancel, 'cancelInvoice', Constants::STATUS_CANCELED];
        yield 'storno' => [BulkAction::Storno, 'stornoInvoice', Constants::STATUS_REVERSED];
        yield 'delete' => [BulkAction::Delete, 'deleteInvoice', Constants::STATUS_DELETED];
    }

    #[DataProvider('invoiceActions')]
    public function testAnIssuedInvoiceIsActedOn(BulkAction $action, string $method, string $state): void
    {
        $this->repo->put(5, ['status' => 'issued', 'invoice_series' => 'F', 'invoice_number' => '0002']);

        $result = $this->runner()->run($action, 5);

        self::assertSame(BulkRunResult::OUTCOME_DONE, $result->outcome);
        self::assertSame([[$method, 'F', '0002']], $this->api->calls);
        self::assertSame($state, $this->repo->rows[5]['status']);
        self::assertSame('0002', $result->invoiceNumber);
    }

    #[DataProvider('invoiceActions')]
    public function testAnFgoRefusalOfAnInvoiceActionFails(BulkAction $action, string $method, string $state): void
    {
        $this->repo->put(5, ['status' => 'issued', 'invoice_series' => 'F', 'invoice_number' => '0002']);
        $this->api->invoiceActionError = 'Factura nu poate fi anulata';

        $result = $this->runner()->run($action, 5);

        self::assertSame(BulkRunResult::OUTCOME_FAILED, $result->outcome);
        self::assertSame('Factura nu poate fi anulata', $result->message);
        self::assertSame(Constants::STATUS_ISSUED, $this->repo->rows[5]['status']);
    }

    public function testCancellingTwiceIsSkippedTheSecondTime(): void
    {
        $this->repo->put(5, ['status' => 'issued', 'invoice_series' => 'F', 'invoice_number' => '0002']);
        $runner = $this->runner();

        $runner->run(BulkAction::Cancel, 5);
        $second = $runner->run(BulkAction::Cancel, 5);

        self::assertSame(BulkRunResult::OUTCOME_SKIPPED, $second->outcome);
        self::assertSame(['already_canceled'], self::codes($second));
        self::assertCount(1, $this->api->calls);
    }

    // ── Never throws ─────────────────────────────────────────────────────

    public function testAMissingOrderFails(): void
    {
        foreach ([0, -1, 404] as $id) {
            $result = $this->runner()->run(BulkAction::Issue, $id);
            self::assertSame(BulkRunResult::OUTCOME_FAILED, $result->outcome, (string) $id);
            self::assertSame(['order_not_found'], self::codes($result));
        }
        self::assertSame([], $this->api->calls);
    }

    public function testAnythingThrownBecomesAFailedResult(): void
    {
        $runner = $this->runner(loader: static function (int $id): ?array {
            throw new \RuntimeException('database is gone');
        });

        $result = $runner->run(BulkAction::Issue, 5);

        self::assertSame(BulkRunResult::OUTCOME_FAILED, $result->outcome);
        self::assertSame('[RuntimeException] database is gone', $result->message);
    }

    public function testTheResultSerialisesForThePage(): void
    {
        $result = $this->runner()->run(BulkAction::Issue, 5, true);

        self::assertSame([
            'order_id' => 5,
            'outcome' => 'issued',
            'message' => '',
            'reasons' => [],
            'invoice_series' => 'F',
            'invoice_number' => '0003',
            'pdf_link' => 'https://api-testuat.fgo.ro/pdf/0003',
            'email_status' => 'sent',
        ], $result->toArray());
    }

    public function testAnUnknownOutcomeIsAProgrammingError(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new BulkRunResult(1, 'maybe');
    }
}
