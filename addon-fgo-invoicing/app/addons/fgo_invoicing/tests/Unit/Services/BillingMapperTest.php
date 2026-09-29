<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\FgoInvoicing\Constants;
use Tygh\Addons\FgoInvoicing\Services\BillingMapper;
use Tygh\Addons\FgoInvoicing\Services\ConfigProvider;

#[CoversClass(BillingMapper::class)]
final class BillingMapperTest extends TestCase
{
    protected function setUp(): void
    {
        ConfigProvider::seed([
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
        ]);
    }

    protected function tearDown(): void
    {
        ConfigProvider::reset();
    }

    private function baseOrder(array $overrides = []): array
    {
        return array_replace([
            'order_id' => 1234,
            'user_id' => 7,
            'b_firstname' => 'Ion',
            'b_lastname' => 'Popescu',
            'b_country' => 'RO',
            'b_state' => 'Cluj',
            'b_city' => 'Cluj-Napoca',
            'b_address' => 'Str. Mare 1',
            'b_zipcode' => '400000',
            'email' => 'ion@example.ro',
            'phone' => '+40700111222',
            'payment_method' => ['payment' => 'Card'],
            'subtotal' => 200.00,
            'subtotal_tax_amount' => 42.00,
            'shipping_cost' => 19.95,
            'subtotal_discount' => 0,
            'shipping' => [['shipping' => 'Curier']],
            'products' => [
                [
                    'product' => 'Pernă',
                    'product_code' => 'SKU-1',
                    'amount' => 2,
                    'subtotal' => 200.00,
                    'tax_value' => 42.00,
                ],
            ],
        ], $overrides);
    }

    public function testB2cOrderProducesPersonClient(): void
    {
        $req = (new BillingMapper())->mapOrderInfo($this->baseOrder());
        self::assertSame(Constants::TIP_PERSON, $req->client->tip);
        self::assertSame('Ion Popescu', $req->client->denumire);
        self::assertNull($req->client->codUnic);
        self::assertNull($req->client->nrRegCom);
        self::assertFalse($req->client->platitorTva);
        self::assertFalse($req->client->strain);
        self::assertSame(7, $req->client->idExtern);
    }

    public function testB2bOrderWithRoCifMarksPlatitorTvaAndStripsPrefix(): void
    {
        $req = (new BillingMapper())->mapOrderInfo($this->baseOrder([
            'b_company' => 'SC ACME SRL',
            'fgo_billing_company' => 'SC ACME SRL',
            'fgo_billing_cui' => 'RO12345678',
            'fgo_billing_reg' => 'J40/12345/2020',
        ]));

        self::assertSame(Constants::TIP_COMPANY, $req->client->tip);
        self::assertSame('SC ACME SRL', $req->client->denumire);
        self::assertSame('12345678', $req->client->codUnic);
        self::assertSame('J40/12345/2020', $req->client->nrRegCom);
        self::assertTrue($req->client->platitorTva);
    }

    public function testB2bWithoutRoPrefixIsNotPlatitorTva(): void
    {
        $req = (new BillingMapper())->mapOrderInfo($this->baseOrder([
            'fgo_billing_company' => 'SC ACME SRL',
            'fgo_billing_cui' => '12345678',
        ]));

        self::assertSame(Constants::TIP_COMPANY, $req->client->tip);
        self::assertSame('12345678', $req->client->codUnic);
        self::assertFalse($req->client->platitorTva);
    }

    public function testForeignCustomerCarriesStrainFlag(): void
    {
        $req = (new BillingMapper())->mapOrderInfo($this->baseOrder([
            'b_country' => 'DE',
        ]));
        self::assertTrue($req->client->strain);
        self::assertSame('DE', $req->client->tara);
    }

    public function testProductLineCarriesGrossPretTotalAndSnappedVat(): void
    {
        $req = (new BillingMapper())->mapOrderInfo($this->baseOrder());
        self::assertCount(2, $req->continut, 'product + shipping');
        $line = $req->continut[0];
        self::assertSame('Pernă', $line->denumire);
        self::assertSame(2.0, $line->nrProduse);
        self::assertSame(242.0, $line->pretTotal);
        self::assertSame(21, $line->cotaTva->percent);
        self::assertSame('SKU-1', $line->codArticol);
    }

    /** A booking paid with a deposit invoices an advance; its balance order, the rest. */
    public function testDepositAndBalanceLinesSaySo(): void
    {
        $deposit = (new BillingMapper())->mapOrderInfo($this->baseOrder(['products' => [[
            'product' => 'ADMIRAL', 'amount' => 1, 'subtotal' => 89.70, 'tax_value' => 0,
            'extra' => ['travel_deposit' => ['deposit' => 89.70, 'balance' => 209.30]],
        ]]]));
        self::assertSame('Avans rezervare: ADMIRAL', $deposit->continut[0]->denumire);

        $balance = (new BillingMapper())->mapOrderInfo($this->baseOrder(['products' => [[
            'product' => 'ADMIRAL', 'amount' => 1, 'subtotal' => 209.30, 'tax_value' => 0,
            'extra' => ['travel_balance_id' => 17, 'parent_order_id' => 1042],
        ]]]));
        self::assertSame('Rest de plată rezervare: ADMIRAL (comanda #1042)', $balance->continut[0]->denumire);
    }

    public function testDiscountAddedAsNegativeQuantityLine(): void
    {
        $req = (new BillingMapper())->mapOrderInfo($this->baseOrder([
            'subtotal_discount' => 30.00,
        ]));
        // product + discount + shipping = 3
        self::assertCount(3, $req->continut);
        $discount = $req->continut[1];
        self::assertSame('Reducere', $discount->denumire);
        self::assertSame(-1.0, $discount->nrProduse);
        self::assertSame(30.0, $discount->pretTotal);
        self::assertSame('DISCOUNT', $discount->codArticol);
    }

    public function testShippingZeroVatModeProducesZeroRate(): void
    {
        ConfigProvider::seed([
            'invoice_type' => 'Factura',
            'shipping_tax_vat' => 'vat_zero',
            'shipping_code' => 'SHIPPING',
            'discount_code' => 'DISCOUNT',
            'article_id_field' => 'sku',
            'product_description' => 'N',
            'additional_info' => 'Y',
            'verify_duplicate' => 'Y',
            'administration_code' => '',
        ]);

        $req = (new BillingMapper())->mapOrderInfo($this->baseOrder());
        $shipping = $req->continut[1]; // product, then shipping
        self::assertSame(0, $shipping->cotaTva->percent);
        self::assertSame(Constants::UM_SERVICE, $shipping->um);
    }

    public function testRequestIdIsDeterministicForSameOrder(): void
    {
        $req1 = (new BillingMapper())->mapOrderInfo($this->baseOrder());
        $req2 = (new BillingMapper())->mapOrderInfo($this->baseOrder());
        self::assertSame($req1->requestId, $req2->requestId);
        self::assertNotEmpty($req1->requestId);
    }

    /**
     * The first issue keeps the RequestId it always had: rows issued before
     * re-issues existed must still deduplicate against it.
     */
    public function testAFirstIssueKeepsTheOrderOnlyRequestId(): void
    {
        $hash = sha1(sha1('fgo_invoicing') . '1234');
        $expected = sprintf(
            '%s-%s-5%s-%s%s-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 13, 3),
            dechex((hexdec(substr($hash, 16, 2)) & 0x3F) | 0x80),
            substr($hash, 18, 2),
            substr($hash, 20, 12),
        );

        self::assertSame($expected, (new BillingMapper())->mapOrderInfo($this->baseOrder())->requestId);
        self::assertSame($expected, (new BillingMapper())->mapOrderInfo($this->baseOrder(), '', '')->requestId);
    }

    /**
     * A re-issue must not share the RequestId of the invoice it replaces (FGO
     * would deduplicate it into the cancelled one), must stay the same across
     * its own retries, and differs per replaced invoice.
     */
    public function testAReissueNamesTheInvoiceItReplaces(): void
    {
        $mapper = new BillingMapper();
        $first = $mapper->mapOrderInfo($this->baseOrder())->requestId;
        $reissue = $mapper->mapOrderInfo($this->baseOrder(), 'F', '0002');

        self::assertNotSame($first, $reissue->requestId);
        self::assertSame($reissue->requestId, $mapper->mapOrderInfo($this->baseOrder(), ' F ', '0002 ')->requestId, 'retries are idempotent');
        self::assertNotSame($reissue->requestId, $mapper->mapOrderInfo($this->baseOrder(), 'F', '0005')->requestId);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $reissue->requestId);
        self::assertSame($reissue->requestId, $reissue->toFormFields()['RequestId']);
        self::assertSame('true', $reissue->toFormFields()['VerificareDuplicat'], 'kept as configured');
    }

    public function testExplicatiiContainsOrderNumberAndPaymentMethod(): void
    {
        $req = (new BillingMapper())->mapOrderInfo($this->baseOrder());
        self::assertNotNull($req->explicatii);
        self::assertStringContainsString('Comanda nr. 1234', $req->explicatii);
        self::assertStringContainsString('Modalitate plata: Card', $req->explicatii);
    }

    /**
     * REGRESSION: Valuta was $order_info['secondary_currency'] — the currency
     * the shopper was BROWSING in. CS-Cart stores every order amount in the
     * PRIMARY currency, so an order placed while browsing in EUR was invoiced
     * as EUR with its RON amounts.
     */
    public function testCurrencyIsThePrimaryCurrencyNotTheBrowsingCurrency(): void
    {
        $req = (new BillingMapper('RON'))->mapOrderInfo($this->baseOrder([
            'secondary_currency' => 'EUR',
            'currency' => 'USD',
        ]));

        self::assertSame('RON', $req->valuta);
    }

    public function testCurrencyFollowsTheStorePrimaryCurrency(): void
    {
        $req = (new BillingMapper(' eur '))->mapOrderInfo($this->baseOrder(['secondary_currency' => 'RON']));

        self::assertSame('EUR', $req->valuta);
    }

    /**
     * Unpinned, the mapper asks ConfigProvider, which falls back to RON when
     * CART_PRIMARY_CURRENCY is not defined (as in this bootstrap).
     */
    public function testCurrencyFallsBackToConfigProviderThenRon(): void
    {
        self::assertSame('RON', (new BillingMapper())->mapOrderInfo($this->baseOrder())->valuta);
        self::assertSame(ConfigProvider::primaryCurrency(), (new BillingMapper())->mapOrderInfo($this->baseOrder())->valuta);
        self::assertSame('RON', (new BillingMapper('  '))->mapOrderInfo($this->baseOrder())->valuta, 'blank is not a currency');
    }

    // ── Identifiers (fed by BillingExtrasResolver) ───────────────────────

    public function testIndividualSendsTheirCnpAsCodUnic(): void
    {
        $req = (new BillingMapper())->mapOrderInfo($this->baseOrder([
            'fgo_billing_cui' => '',
            'fgo_billing_cnp' => ' 196 0101 123456 ',
        ]));

        self::assertSame(Constants::TIP_PERSON, $req->client->tip);
        self::assertSame('1960101123456', $req->client->codUnic);
        self::assertNull($req->client->nrRegCom);
        self::assertFalse($req->client->platitorTva);
        self::assertSame('1960101123456', $req->client->toFormFields()['Client[CodUnic]']);
    }

    /**
     * The legacy ?:user_profiles.fgo_billing_cui column doubled as the CNP
     * slot for an explicit PF (tip = 2): still honoured when no CNP resolved.
     */
    public function testExplicitIndividualWithoutCnpKeepsTheLegacyCuiAsCodUnic(): void
    {
        $req = (new BillingMapper())->mapOrderInfo($this->baseOrder([
            'fgo_billing_tip' => 2,
            'fgo_billing_cui' => '2960101123456',
        ]));

        self::assertSame(Constants::TIP_PERSON, $req->client->tip);
        self::assertSame('2960101123456', $req->client->codUnic);
    }

    public function testCnpIsIgnoredForACompany(): void
    {
        $req = (new BillingMapper())->mapOrderInfo($this->baseOrder([
            'company' => 'ACME SRL',
            'fgo_billing_cui' => 'RO12345678',
            'fgo_billing_cnp' => '1960101123456',
        ]));

        self::assertSame(Constants::TIP_COMPANY, $req->client->tip);
        self::assertSame('12345678', $req->client->codUnic);
    }

    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function typedCifs(): array
    {
        return [
            'spaces'           => ['RO 123 456 78', '12345678', true],
            'dots and dashes'  => ['ro-123.456.78', '12345678', true],
            'tab and newline'  => [" RO\t12345678\n", '12345678', true],
            'no prefix spaced' => ['123 456 78', '12345678', false],
            'pasted label'     => ['CUI: RO14399840', '14399840', true],
            'en dash'          => ['RO123456–78', '12345678', true],
            'leading zeros'    => ['RO0012345678', '12345678', true],
        ];
    }

    #[DataProvider('typedCifs')]
    public function testTypedCifIsCompactedBeforeThePrefixCheck(string $typed, string $codUnic, bool $platitorTva): void
    {
        $req = (new BillingMapper())->mapOrderInfo($this->baseOrder([
            'company' => 'ACME SRL',
            'fgo_billing_cui' => $typed,
        ]));

        self::assertSame(Constants::TIP_COMPANY, $req->client->tip);
        self::assertSame($codUnic, $req->client->codUnic);
        self::assertSame($platitorTva, $req->client->platitorTva);
    }

    /**
     * REGRESSION: any text in the CIF slot made the customer a company. What
     * customers type into a required CUI field when they have none is no CIF:
     * it must neither turn them into a PJ nor reach FGO as CodUnic, and "RO"
     * alone must not flag a VAT payer.
     *
     * @return array<string, array{string}>
     */
    public static function placeholderCifs(): array
    {
        return [
            'N/A'             => ['N/A'],
            'nu e cazul'      => ['nu e cazul'],
            'persoana fizica' => ['persoana fizica'],
            'zero'            => ['0'],
            'zeros'           => ['0000000000'],
            'dash'            => ['-'],
            'RO alone'        => ['RO'],
            'RO0'             => ['RO0'],
        ];
    }

    #[DataProvider('placeholderCifs')]
    public function testAPlaceholderCifMakesNobodyACompany(string $typed): void
    {
        $person = (new BillingMapper())->mapOrderInfo($this->baseOrder(['fgo_billing_cui' => $typed]));
        self::assertSame(Constants::TIP_PERSON, $person->client->tip, "'{$typed}'");
        self::assertNull($person->client->codUnic);
        self::assertFalse($person->client->platitorTva);

        $company = (new BillingMapper())->mapOrderInfo($this->baseOrder(['company' => 'ACME SRL', 'fgo_billing_cui' => $typed]));
        self::assertSame(Constants::TIP_COMPANY, $company->client->tip, 'the company name still makes a PJ');
        self::assertNull($company->client->codUnic, 'but the placeholder is not its CIF');
        self::assertFalse($company->client->platitorTva);

        $explicitPerson = (new BillingMapper())->mapOrderInfo($this->baseOrder(['fgo_billing_tip' => 2, 'fgo_billing_cui' => $typed]));
        self::assertNull($explicitPerson->client->codUnic, 'nor the legacy PF fallback');
    }

    /**
     * Foreign VAT ids go out as typed (compacted): a letter prefix is part of
     * them, and so is a leading zero (Belgium's 0123456789 is a different
     * number without it). Only a Romanian id loses its leading zeros.
     *
     * An EU member-state prefix marks a VAT-registered company, so it sets
     * PlatitorTVA like RO does. A non-EU prefix (GB since Brexit, CH) or no
     * prefix at all does not.
     *
     * @return array<string, array{string, string, string, bool}>
     */
    public static function foreignVatIds(): array
    {
        return [
            'DE'                  => ['DE', 'DE123456789', 'DE123456789', true],
            'DE spaced'           => ['DE', 'DE 123 456 789', 'DE123456789', true],
            'BG with zero'        => ['BG', 'BG0123456789', 'BG0123456789', true],
            'AT with U'           => ['AT', 'ATU12345678', 'ATU12345678', true],
            'NL with B suffix'    => ['NL', 'NL123456789B01', 'NL123456789B01', true],
            'Greece EL'           => ['GR', 'EL123456789', 'EL123456789', true],
            'Greece typed as GR'  => ['GR', 'GR123456789', 'GR123456789', true],
            'BE without prefix'   => ['BE', '0123.456.789', '0123456789', false],
            'GB is not EU'        => ['GB', 'GB123456789', 'GB123456789', false],
            'CH is not EU'        => ['CH', 'CHE123456789', 'CHE123456789', false],
        ];
    }

    #[DataProvider('foreignVatIds')]
    public function testForeignVatIdsAreSentAsTyped(string $country, string $typed, string $codUnic, bool $platitorTva): void
    {
        $req = (new BillingMapper())->mapOrderInfo($this->baseOrder([
            'b_country' => $country,
            'company' => 'Foreign Ltd',
            'fgo_billing_cui' => $typed,
        ]));

        self::assertSame(Constants::TIP_COMPANY, $req->client->tip);
        self::assertTrue($req->client->strain);
        self::assertSame($codUnic, $req->client->codUnic);
        self::assertSame($platitorTva, $req->client->platitorTva);
    }

    public function testARomanianCustomerWithAnEuVatIdIsAVatPayer(): void
    {
        $req = (new BillingMapper())->mapOrderInfo($this->baseOrder([
            'company' => 'ACME GmbH',
            'fgo_billing_cui' => 'DE123456789',
        ]));

        self::assertFalse($req->client->strain);
        self::assertSame('DE123456789', $req->client->codUnic);
        self::assertTrue($req->client->platitorTva);
    }

    /**
     * Client[IdExtern] for a company is its CIF's digits (the WooCommerce
     * plugin's rule), not the user_id: every guest checkout has user_id 0, so
     * all guest companies used to share one client record in FGO.
     *
     * @return array<string, array{string, string, int}>
     */
    public static function companyClientIds(): array
    {
        return [
            'RO prefix'              => ['RO', 'RO12345678', 12345678],
            'no prefix'              => ['RO', '12345678', 12345678],
            'RO leading zeros'       => ['RO', 'RO0012345678', 12345678],
            'DE'                     => ['DE', 'DE123456789', 123456789],
            'AT drops the U'         => ['AT', 'ATU12345678', 12345678],
            'NL longer than 10'      => ['NL', 'NL123456789B01', 345678901],
            'BE keeps value, no 0'   => ['BE', '0123.456.789', 123456789],
        ];
    }

    #[DataProvider('companyClientIds')]
    public function testACompanyWithACifIsIdentifiedByIt(string $country, string $typed, int $idExtern): void
    {
        foreach ([0, 7] as $userId) {
            $req = (new BillingMapper())->mapOrderInfo($this->baseOrder([
                'user_id' => $userId,
                'b_country' => $country,
                'company' => 'ACME SRL',
                'fgo_billing_cui' => $typed,
            ]));

            self::assertSame(Constants::TIP_COMPANY, $req->client->tip);
            self::assertSame($idExtern, $req->client->idExtern, "user_id {$userId}");
            self::assertSame($idExtern, $req->toFormFields()['Client[IdExtern]']);
        }
    }

    public function testTwoGuestCompaniesNoLongerShareAClientId(): void
    {
        $a = (new BillingMapper())->mapOrderInfo($this->baseOrder(['user_id' => 0, 'company' => 'A SRL', 'fgo_billing_cui' => 'RO12345678']));
        $b = (new BillingMapper())->mapOrderInfo($this->baseOrder(['user_id' => 0, 'company' => 'B SRL', 'fgo_billing_cui' => 'RO87654321']));

        self::assertNotSame($a->client->idExtern, $b->client->idExtern);
    }

    public function testIndividualsAndCompaniesWithoutACifKeepTheirUserId(): void
    {
        $person = (new BillingMapper())->mapOrderInfo($this->baseOrder([
            'user_id' => 7,
            'fgo_billing_cnp' => '1960101123456',
        ]));
        self::assertSame(Constants::TIP_PERSON, $person->client->tip);
        self::assertSame(7, $person->client->idExtern, 'never the CNP');

        $company = (new BillingMapper())->mapOrderInfo($this->baseOrder(['user_id' => 7, 'company' => 'ACME SRL']));
        self::assertSame(Constants::TIP_COMPANY, $company->client->tip);
        self::assertSame(7, $company->client->idExtern);
    }

    public function testRegComGoesToNrRegComForACompanyOnly(): void
    {
        $company = (new BillingMapper())->mapOrderInfo($this->baseOrder([
            'company' => 'ACME SRL',
            'fgo_billing_reg' => ' J40/12345/2020 ',
        ]));
        self::assertSame('J40/12345/2020', $company->client->nrRegCom);

        $person = (new BillingMapper())->mapOrderInfo($this->baseOrder(['fgo_billing_reg' => 'J40/12345/2020']));
        self::assertSame(Constants::TIP_PERSON, $person->client->tip);
        self::assertNull($person->client->nrRegCom);
    }

    /**
     * REGRESSION: fn_get_order_info() sets fgo_billing_company (a column the
     * add-on adds to ?:user_profiles) to '' on EVERY order. The old `??` chain
     * stopped at that blank and never reached CS-Cart's own `company`, so a
     * company that typed its name but no CIF was invoiced as an individual.
     */
    public function testCompanyNameFallsBackPastBlankKeysToTheOrderCompany(): void
    {
        $req = (new BillingMapper())->mapOrderInfo($this->baseOrder([
            'fgo_billing_company' => '',
            'fgo_billing_cui' => '',
            'fgo_billing_tip' => '',
            'company' => 'ACME SRL',
        ]));

        self::assertSame(Constants::TIP_COMPANY, $req->client->tip);
        self::assertSame('ACME SRL', $req->client->denumire);
        self::assertNull($req->client->codUnic);
    }

    public function testPlaceholderLineWhenOrderHasNoProductsOrShippingOrDiscount(): void
    {
        $req = (new BillingMapper())->mapOrderInfo($this->baseOrder([
            'products' => [],
            'shipping_cost' => 0,
            'subtotal' => 0,
        ]));
        self::assertCount(1, $req->continut);
        self::assertSame('Comanda #1234', $req->continut[0]->denumire);
        self::assertSame(0.0, $req->continut[0]->pretTotal);
    }

    public function testProductWithHtmlInNameIsSanitized(): void
    {
        $req = (new BillingMapper())->mapOrderInfo($this->baseOrder([
            'products' => [
                [
                    'product' => '<b>Pernă</b><script>alert(1)</script>',
                    'product_code' => 'SKU-1',
                    'amount' => 1,
                    'subtotal' => 100.0,
                    'tax_value' => 21.0,
                ],
            ],
        ]));
        self::assertSame('Pernăalert(1)', $req->continut[0]->denumire);
    }
}
