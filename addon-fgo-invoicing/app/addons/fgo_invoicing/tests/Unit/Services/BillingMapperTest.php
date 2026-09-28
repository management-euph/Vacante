<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
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
            'currency' => 'RON',
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

    public function testExplicatiiContainsOrderNumberAndPaymentMethod(): void
    {
        $req = (new BillingMapper())->mapOrderInfo($this->baseOrder());
        self::assertNotNull($req->explicatii);
        self::assertStringContainsString('Comanda nr. 1234', $req->explicatii);
        self::assertStringContainsString('Modalitate plata: Card', $req->explicatii);
    }

    public function testCurrencyDefaultsToRonWhenMissing(): void
    {
        $order = $this->baseOrder();
        unset($order['currency']);
        $req = (new BillingMapper())->mapOrderInfo($order);
        self::assertSame('RON', $req->valuta);
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
