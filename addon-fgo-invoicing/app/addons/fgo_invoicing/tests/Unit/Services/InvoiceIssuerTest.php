<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\FgoInvoicing\Api\FgoApiClient;
use Tygh\Addons\FgoInvoicing\Api\FgoApiException;
use Tygh\Addons\FgoInvoicing\Constants;
use Tygh\Addons\FgoInvoicing\Repository\InvoiceRepository;
use Tygh\Addons\FgoInvoicing\Services\BillingExtrasResolver;
use Tygh\Addons\FgoInvoicing\Services\BillingMapper;
use Tygh\Addons\FgoInvoicing\Services\ConfigProvider;
use Tygh\Addons\FgoInvoicing\Services\InvoiceIssuer;
use Tygh\Addons\FgoInvoicing\Services\InvoiceMailer;
use Tygh\Addons\FgoInvoicing\Tests\Support\InMemoryInvoiceRepository;
use Tygh\Addons\FgoInvoicing\Tests\Support\InMemoryProfileFieldCatalog;
use Tygh\Addons\FgoInvoicing\Tests\Support\LogStub;

#[CoversClass(InvoiceIssuer::class)]
final class InvoiceIssuerTest extends TestCase
{
    /**
     * Settings every test starts from; seed() overrides single keys.
     *
     * @var array<string, mixed>
     */
    private const SETTINGS = [
        'invoice_type' => 'Factura',
        'invoice_series' => 'F',
        'verify_duplicate' => 'Y',
        'sanitize_vat' => 'N',
        'article_id_field' => 'sku',
        'product_description' => 'N',
        'additional_info' => 'Y',
        'shipping_tax_vat' => 'vat_included',
        'shipping_code' => 'SHIPPING',
        'discount_code' => 'DISCOUNT',
        'administration_code' => '',
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

    /**
     * @param array<string, mixed> $settings merged over the defaults above
     */
    private static function seed(array $settings): void
    {
        ConfigProvider::seed(array_replace(self::SETTINGS, $settings));
    }

    /**
     * @param array<int, string> $fieldDescriptions custom profile fields: field_id => description
     */
    private static function resolver(array $fieldDescriptions = []): BillingExtrasResolver
    {
        return new BillingExtrasResolver(InMemoryProfileFieldCatalog::withDescriptions($fieldDescriptions));
    }

    private function issuer(FgoApiClient $api, InvoiceRepository $repo, ?BillingExtrasResolver $resolver = null): InvoiceIssuer
    {
        return new InvoiceIssuer($api, $repo, new BillingMapper('RON'), $resolver ?? self::resolver());
    }

    private function order(): array
    {
        return [
            'order_id' => 1234,
            'user_id' => 7,
            'b_firstname' => 'Ion',
            'b_lastname' => 'Popescu',
            'b_country' => 'RO',
            'b_state' => 'Cluj',
            'b_city' => 'Cluj-Napoca',
            'b_address' => 'Str. Mare 1',
            'email' => 'ion@example.ro',
            'phone' => '+40700111222',
            'subtotal' => 100.0,
            'subtotal_tax_amount' => 21.0,
            'shipping_cost' => 0,
            'products' => [
                ['product' => 'Pernă', 'product_code' => 'SKU-1', 'amount' => 1, 'subtotal' => 100.0, 'tax_value' => 21.0],
            ],
        ];
    }

    public function testSuccessPathPersistsIssuedRowAndReturnsInvoiceNumber(): void
    {
        $api = $this->successApi();
        $repo = $this->fakeRepository();
        $issuer = $this->issuer($api, $repo);

        $result = $issuer->issueForOrder(1234, $this->order());

        self::assertSame(Constants::STATUS_ISSUED, $result['status']);
        self::assertSame('INV-1', $result['invoice_id']);
        $row = $repo->findByOrderId(1234);
        self::assertNotNull($row);
        self::assertSame(Constants::STATUS_ISSUED, $row['status']);
        self::assertSame('INV-1', $row['invoice_number']);
        self::assertSame('F', $row['invoice_series']);
        self::assertSame('https://files.fgo.ro/x.pdf', $row['pdf_link']);
    }

    public function testIdempotencyShortCircuitsWhenRowAlreadyIssued(): void
    {
        $api = $this->successApi();
        $repo = $this->fakeRepository();
        $issuer = $this->issuer($api, $repo);

        $issuer->issueForOrder(1234, $this->order());
        $callsAfterFirst = $api->callCount;

        $second = $issuer->issueForOrder(1234, $this->order());
        self::assertSame(Constants::STATUS_ISSUED, $second['status']);
        self::assertSame('already-issued', $second['invoice_id']);
        self::assertSame($callsAfterFirst, $api->callCount, 'API not called again after issued');
    }

    public function testFgoFailurePersistsLastErrorAndIncrementsRetryCount(): void
    {
        $api = $this->failingApi('CIF invalid');
        $repo = $this->fakeRepository();
        $issuer = $this->issuer($api, $repo);

        $result = $issuer->issueForOrder(1234, $this->order());
        self::assertSame(Constants::STATUS_FAILED, $result['status']);
        self::assertSame('CIF invalid', $result['error']);

        $row = $repo->findByOrderId(1234);
        self::assertNotNull($row);
        self::assertSame(Constants::STATUS_FAILED, $row['status']);
        self::assertSame('CIF invalid', $row['last_error']);
        self::assertSame(1, $row['retry_count']);
    }

    public function testRetryAfterFailureBumpsRetryCountAndStaysFailed(): void
    {
        $api = $this->failingApi('still bad');
        $repo = $this->fakeRepository();
        $issuer = $this->issuer($api, $repo);

        $issuer->issueForOrder(1234, $this->order());
        $issuer->issueForOrder(1234, $this->order());

        $row = $repo->findByOrderId(1234);
        self::assertNotNull($row);
        self::assertSame(Constants::STATUS_FAILED, $row['status']);
        self::assertSame(2, $row['retry_count']);
    }

    public function testInvalidOrderIdReturnsInvalidStatus(): void
    {
        $api = $this->successApi();
        $repo = $this->fakeRepository();
        $issuer = $this->issuer($api, $repo);

        $result = $issuer->issueForOrder(0);
        self::assertSame('invalid', $result['status']);
        self::assertSame(0, $api->callCount);
    }

    // ── Billing extras (CIF / Reg. Com. / CNP) ───────────────────────────

    /**
     * REGRESSION: the CIF the customer typed into the store's profile field
     * never reached FGO. The issuer now resolves it before mapping.
     */
    public function testCifFromTheOrderProfileFieldsReachesTheFgoRequest(): void
    {
        $api = $this->successApi();
        $issuer = $this->issuer($api, $this->fakeRepository(), self::resolver([12 => 'CIF', 13 => 'Nr. Reg. Com.']));

        $result = $issuer->issueForOrder(1234, $this->order() + [
            'company' => 'ACME SRL',
            'fields' => [12 => 'RO 123 456 78', 13 => 'J40/1/2020'],
        ]);

        self::assertSame(Constants::STATUS_ISSUED, $result['status']);
        self::assertNotNull($api->lastPayload);
        self::assertSame('PJ', $api->lastPayload['Client[Tip]']);
        self::assertSame('12345678', $api->lastPayload['Client[CodUnic]']);
        self::assertSame('true', $api->lastPayload['Client[PlatitorTVA]']);
        self::assertSame('J40/1/2020', $api->lastPayload['Client[NrRegCom]']);
        self::assertSame('RON', $api->lastPayload['Valuta']);
    }

    /**
     * place_order_post hands the issuer no order_info; the lazily loaded one
     * must be resolved the same way. Separate process: the fn_get_order_info
     * stand-in is a global function, and in-process it would change the path
     * every other test here takes.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testLazilyLoadedOrderInfoIsResolvedToo(): void
    {
        require_once __DIR__ . '/../../Fixtures/fn_get_order_info_stub.php';
        $GLOBALS['fgo_test_orders'] = [1234 => $this->order() + [
            'company' => 'ACME SRL',
            'fields' => [12 => 'RO12345678'],
        ]];
        $api = $this->successApi();
        $issuer = $this->issuer($api, $this->fakeRepository(), self::resolver([12 => 'CUI']));

        $result = $issuer->issueForOrder(1234);

        self::assertSame(Constants::STATUS_ISSUED, $result['status']);
        self::assertNotNull($api->lastPayload);
        self::assertSame('12345678', $api->lastPayload['Client[CodUnic]']);
    }

    public function testCompanyWithoutCifIsBlockedBeforeAnyApiCallWhenRequired(): void
    {
        self::seed(['client_vat_required' => 'Y']);
        $api = $this->successApi();
        $repo = $this->fakeRepository();
        $issuer = $this->issuer($api, $repo, self::resolver([12 => 'CIF']));

        $result = $issuer->issueForOrder(1234, $this->order() + ['company' => 'ACME SRL', 'fields' => [12 => ' ']]);

        self::assertSame(Constants::STATUS_FAILED, $result['status']);
        self::assertSame(0, $api->callCount, 'FGO must not be called');
        self::assertStringContainsString('Company customer has no CIF', $result['error'] ?? '');
        self::assertStringContainsString('order #1234', $result['error'] ?? '');
        self::assertStringContainsString('then retry', $result['error'] ?? '');
        self::assertStringContainsString('fgo_invoicing.view?order_id=1234', $result['error'] ?? '', 'where to retry');

        $row = $repo->findByOrderId(1234);
        self::assertNotNull($row);
        self::assertSame(Constants::STATUS_FAILED, $row['status']);
        self::assertSame($result['error'], $row['last_error']);
        self::assertSame('[]', $row['request_payload'], 'nothing was sent');
    }

    /**
     * A placeholder typed into a required CUI field is no CIF: with the CIF
     * rule on, the company is blocked exactly as if the field were empty.
     */
    public function testCompanyWithAPlaceholderCifIsBlockedWhenRequired(): void
    {
        self::seed(['client_vat_required' => 'Y']);
        $api = $this->successApi();
        $issuer = $this->issuer($api, $this->fakeRepository(), self::resolver([12 => 'CIF']));

        $result = $issuer->issueForOrder(1234, $this->order() + ['company' => 'ACME SRL', 'fields' => [12 => 'N/A']]);

        self::assertSame(Constants::STATUS_FAILED, $result['status']);
        self::assertSame(0, $api->callCount);
        self::assertStringContainsString('Company customer has no CIF', $result['error'] ?? '');
    }

    /**
     * UPGRADE HAZARD: client_vat_required existed long before anything read
     * it ("Require CIF at checkout"). On a store that has it on but no CIF
     * field, blocking would stop every company invoice at deploy for an
     * identifier no customer could have typed. Issue, and say why in the log.
     */
    public function testCompanyWithoutCifIsIssuedWithAWarningWhenTheStoreHasNoCifField(): void
    {
        self::seed(['client_vat_required' => 'Y']);
        $api = $this->successApi();
        $issuer = $this->issuer($api, $this->fakeRepository(), self::resolver([14 => 'CNP', 15 => 'Observații']));

        $result = $issuer->issueForOrder(1234, $this->order() + ['company' => 'ACME SRL']);

        self::assertSame(Constants::STATUS_ISSUED, $result['status']);
        self::assertSame(1, $api->callCount);
        self::assertContains('[warn] identity-source-missing', LogStub::messages());
        self::assertStringContainsString(
            'client_vat_required is on but the store has no CIF profile field',
            self::loggedContextMessage('[warn] identity-source-missing'),
        );
    }

    public function testIndividualWithoutCnpIsIssuedWithAWarningWhenTheStoreHasNoCnpField(): void
    {
        self::seed(['client_cnp_required' => 'Y']);
        $api = $this->successApi();
        $issuer = $this->issuer($api, $this->fakeRepository(), self::resolver([12 => 'CIF']));

        $result = $issuer->issueForOrder(1234, $this->order());

        self::assertSame(Constants::STATUS_ISSUED, $result['status']);
        self::assertSame(1, $api->callCount);
        self::assertStringContainsString(
            'client_cnp_required is on but the store has no CNP profile field',
            self::loggedContextMessage('[warn] identity-source-missing'),
        );
    }

    /**
     * REGRESSION: a CNP typed into the store's only "CUI" field invoiced the
     * private customer as a company. It is their CNP, and PF CodUnic.
     */
    public function testCnpTypedIntoACuiOnlyFieldIsInvoicedAsAnIndividual(): void
    {
        self::seed(['client_vat_required' => 'Y', 'client_cnp_required' => 'Y']);
        $api = $this->successApi();
        $issuer = $this->issuer($api, $this->fakeRepository(), self::resolver([12 => 'CUI']));

        $result = $issuer->issueForOrder(1234, $this->order() + ['fields' => [12 => '1960101123456']]);

        self::assertSame(Constants::STATUS_ISSUED, $result['status']);
        self::assertNotNull($api->lastPayload);
        self::assertSame('PF', $api->lastPayload['Client[Tip]']);
        self::assertSame('1960101123456', $api->lastPayload['Client[CodUnic]']);
    }

    public function testCompanyWithoutCifIsIssuedWhenNotRequired(): void
    {
        $api = $this->successApi();
        $issuer = $this->issuer($api, $this->fakeRepository());

        $result = $issuer->issueForOrder(1234, $this->order() + ['company' => 'ACME SRL']);

        self::assertSame(Constants::STATUS_ISSUED, $result['status']);
        self::assertSame(1, $api->callCount);
        self::assertNotNull($api->lastPayload);
        self::assertArrayNotHasKey('Client[CodUnic]', $api->lastPayload);
    }

    public function testCompanyWithCifIsIssuedWhenCifRequired(): void
    {
        self::seed(['client_vat_required' => 'Y', 'client_cnp_required' => 'Y']);
        $api = $this->successApi();
        $issuer = $this->issuer($api, $this->fakeRepository(), self::resolver([12 => 'CIF']));

        $result = $issuer->issueForOrder(1234, $this->order() + ['company' => 'ACME SRL', 'fields' => [12 => '12345678']]);

        self::assertSame(Constants::STATUS_ISSUED, $result['status']);
        self::assertSame(1, $api->callCount);
    }

    public function testIndividualWithoutCnpIsBlockedBeforeAnyApiCallWhenRequired(): void
    {
        self::seed(['client_cnp_required' => 'Y', 'client_vat_required' => 'Y']);
        $api = $this->successApi();
        $repo = $this->fakeRepository();
        $issuer = $this->issuer($api, $repo, self::resolver([14 => 'CNP']));

        $result = $issuer->issueForOrder(1234, $this->order());

        self::assertSame(Constants::STATUS_FAILED, $result['status']);
        self::assertSame(0, $api->callCount, 'FGO must not be called');
        self::assertStringContainsString('Individual customer has no CNP', $result['error'] ?? '');
        self::assertStringContainsString('order #1234', $result['error'] ?? '');
        self::assertSame(Constants::STATUS_FAILED, $repo->findByOrderId(1234)['status'] ?? null);
    }

    public function testIndividualWithCnpIsIssuedWhenCnpRequired(): void
    {
        self::seed(['client_cnp_required' => 'Y']);
        $api = $this->successApi();
        $issuer = $this->issuer($api, $this->fakeRepository(), self::resolver([14 => 'CNP']));

        $result = $issuer->issueForOrder(1234, $this->order() + ['fields' => [14 => '1960101123456']]);

        self::assertSame(Constants::STATUS_ISSUED, $result['status']);
        self::assertNotNull($api->lastPayload);
        self::assertSame('PF', $api->lastPayload['Client[Tip]']);
        self::assertSame('1960101123456', $api->lastPayload['Client[CodUnic]']);
    }

    /**
     * The CNP is a Romanian identifier: a customer abroad has none to give,
     * so the CNP rule must not block them.
     */
    public function testForeignIndividualIsNotBlockedByTheCnpRule(): void
    {
        self::seed(['client_cnp_required' => 'Y']);
        $api = $this->successApi();
        $issuer = $this->issuer($api, $this->fakeRepository(), self::resolver([14 => 'CNP']));

        $result = $issuer->issueForOrder(1234, array_replace($this->order(), ['b_country' => 'DE']));

        self::assertSame(Constants::STATUS_ISSUED, $result['status']);
        self::assertSame(1, $api->callCount);
    }

    public function testIndividualWithoutCnpIsIssuedWhenNotRequired(): void
    {
        self::seed(['client_vat_required' => 'Y']);
        $api = $this->successApi();
        $issuer = $this->issuer($api, $this->fakeRepository());

        $result = $issuer->issueForOrder(1234, $this->order());

        self::assertSame(Constants::STATUS_ISSUED, $result['status'], 'the CIF rule is about companies only');
    }

    // ── Customer e-mail after issuing ───────────────────────────────────

    /**
     * @param list<array<string, mixed>> $sent
     */
    private function issuerWithMailer(FgoApiClient $api, InvoiceRepository $repo, array &$sent, bool $accepts = true): InvoiceIssuer
    {
        $mailer = new InvoiceMailer(
            $repo,
            static function (array $payload) use (&$sent, $accepts): bool {
                $sent[] = $payload;

                return $accepts;
            },
            static fn (int $id): ?array => null,
        );

        return new InvoiceIssuer($api, $repo, new BillingMapper('RON'), self::resolver(), $mailer);
    }

    public function testTheSettingDecidesWhenTheCallerDoesNot(): void
    {
        $sent = [];
        self::seed(['auto_email_pdf' => 'Y']);
        $result = $this->issuerWithMailer($this->successApi(), $this->fakeRepository(), $sent)->issueForOrder(1234, $this->order());

        self::assertSame('sent', $result['email_status'] ?? null);
        self::assertCount(1, $sent);
        self::assertSame('ion@example.ro', $sent[0]['to']);
        self::assertSame('https://files.fgo.ro/x.pdf', $sent[0]['pdf_link']);
        self::assertSame('INV-1', $sent[0]['invoice_number']);
        self::assertSame(1234, $sent[0]['order_id']);

        $sent = [];
        self::seed(['auto_email_pdf' => 'N']);
        $off = $this->issuerWithMailer($this->successApi(), $this->fakeRepository(), $sent)->issueForOrder(1234, $this->order());
        self::assertSame('off', $off['email_status'] ?? null);
        self::assertSame([], $sent);
    }

    /**
     * The bulk page's "Email the PDF link" box overrides auto_email_pdf in
     * both directions.
     */
    public function testTheCallerOverridesTheSetting(): void
    {
        $sent = [];
        self::seed(['auto_email_pdf' => 'N']);
        $on = $this->issuerWithMailer($this->successApi(), $this->fakeRepository(), $sent)->issueForOrder(1234, $this->order(), true);
        self::assertSame('sent', $on['email_status'] ?? null);
        self::assertCount(1, $sent);

        $sent = [];
        self::seed(['auto_email_pdf' => 'Y']);
        $off = $this->issuerWithMailer($this->successApi(), $this->fakeRepository(), $sent)->issueForOrder(1234, $this->order(), false);
        self::assertSame('off', $off['email_status'] ?? null);
        self::assertSame([], $sent);
    }

    /**
     * The invoice exists on FGO's side: an e-mail that cannot be sent is
     * reported and logged, the issue still succeeds.
     */
    public function testAFailedEmailDoesNotFailTheInvoice(): void
    {
        $sent = [];
        $result = $this->issuerWithMailer($this->successApi(), $this->fakeRepository(), $sent, false)
            ->issueForOrder(1234, $this->order(), true);

        self::assertSame(Constants::STATUS_ISSUED, $result['status']);
        self::assertSame('failed', $result['email_status'] ?? null);
        self::assertContains('[warn] email-send-failed', LogStub::messages());
    }

    public function testNoEmailIsAttemptedWhenIssuingFails(): void
    {
        $sent = [];
        $result = $this->issuerWithMailer($this->failingApi('boom'), $this->fakeRepository(), $sent)
            ->issueForOrder(1234, $this->order(), true);

        self::assertSame(Constants::STATUS_FAILED, $result['status']);
        self::assertArrayNotHasKey('email_status', $result);
        self::assertSame([], $sent);
    }

    /**
     * Without an injected mailer the issuer builds the production one, which
     * outside CS-Cart (no functions/email.php) reports "failed", never throws.
     */
    public function testTheDefaultMailerNeverBreaksIssuing(): void
    {
        $result = $this->issuer($this->successApi(), $this->fakeRepository())->issueForOrder(1234, $this->order(), true);

        self::assertSame(Constants::STATUS_ISSUED, $result['status']);
        self::assertSame('failed', $result['email_status'] ?? null);
    }

    private static function loggedContextMessage(string $event): string
    {
        foreach (LogStub::$events as $logged) {
            $context = $logged['data']['context'] ?? null;
            if (($logged['data']['message'] ?? null) === $event && is_array($context) && is_string($context['message'] ?? null)) {
                return $context['message'];
            }
        }

        return '';
    }

    // ── Test doubles ─────────────────────────────────────────────────────

    private function successApi(): FgoApiClient
    {
        return new class () extends FgoApiClient {
            public int $callCount = 0;
            /** @var array<string, mixed>|null */
            public ?array $lastPayload = null;
            public function __construct()
            {
            }
            public function check(): array
            {
                return ['Success' => true];
            }
            public function issueInvoice(array $payload): array
            {
                $this->callCount++;
                $this->lastPayload = $payload;
                return [
                    'Success' => true,
                    'Message' => 'OK',
                    'Factura' => [
                        'Numar' => 'INV-1',
                        'Serie' => 'F',
                        'Link' => 'https://files.fgo.ro/x.pdf',
                        'LinkPlata' => 'https://pay.fgo.ro/x',
                    ],
                ];
            }
            public function cancelInvoice(string $s, string $n): array
            {
                return ['Success' => true];
            }
            public function stornoInvoice(string $s, string $n): array
            {
                return ['Success' => true];
            }
            public function deleteInvoice(string $s, string $n): array
            {
                return ['Success' => true];
            }
            public function attachAwb(string $s, string $n, string $a): array
            {
                return ['Success' => true];
            }
        };
    }

    private function failingApi(string $msg): FgoApiClient
    {
        return new class ($msg) extends FgoApiClient {
            public int $callCount = 0;
            public function __construct(private string $msg)
            {
            }
            public function check(): array
            {
                return ['Success' => true];
            }
            public function issueInvoice(array $payload): array
            {
                $this->callCount++;
                throw new FgoApiException($this->msg);
            }
            public function cancelInvoice(string $s, string $n): array
            {
                return ['Success' => true];
            }
            public function stornoInvoice(string $s, string $n): array
            {
                return ['Success' => true];
            }
            public function deleteInvoice(string $s, string $n): array
            {
                return ['Success' => true];
            }
            public function attachAwb(string $s, string $n, string $a): array
            {
                return ['Success' => true];
            }
        };
    }

    /**
     * In-memory ?:fgo_invoices with the SQL's claim rules (the shared
     * double, so the issuer is tested against one definition of them).
     */
    private function fakeRepository(): InMemoryInvoiceRepository
    {
        return new InMemoryInvoiceRepository();
    }
}
