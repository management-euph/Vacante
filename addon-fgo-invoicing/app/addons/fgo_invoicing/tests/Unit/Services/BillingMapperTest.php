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
     * number without it). Only a Romanian id loses its leading zeros, and
     * only RO flags PlatitorTVA, as before.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function foreignVatIds(): array
    {
        return [
            'DE'                => ['DE', 'DE123456789', 'DE123456789'],
            'DE spaced'         => ['DE', 'DE 123 456 789', 'DE123456789'],
            'BG with zero'      => ['BG', 'BG0123456789', 'BG0123456789'],
            'BE without prefix' => ['BE', '0123.456.789', '0123456789'],
        ];
    }

    #[DataProvider('foreignVatIds')]
    public function testForeignVatIdsAreSentAsTyped(string $country, string $typed, string $codUnic): void
    {
        $req = (new BillingMapper())->mapOrderInfo($this->baseOrder([
            'b_country' => $country,
            'company' => 'Foreign Ltd',
            'fgo_billing_cui' => $typed,
        ]));

        self::assertSame(Constants::TIP_COMPANY, $req->client->tip);
        self::assertTrue($req->client->strain);
        self::assertSame($codUnic, $req->client->codUnic);
        self::assertFalse($req->client->platitorTva);
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
