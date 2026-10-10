<?php

declare(strict_types=1);

namespace Netopia\CsCart\Tests\Payment;

use Netopia\CsCart\Dto\ThreeDsData;
use Netopia\CsCart\Payment\PayloadBuilder;
use Netopia\CsCart\Tests\Support\FakeClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * "Payment currency" setting of the payment method.
 *
 * CS-Cart keeps an order's total in the store's primary currency and stores
 * the currency the customer checked out in as `secondary_currency`. The
 * fixture store is the one that showed the bug: USD primary, EUR at 1.28
 * (1 EUR = 1.28 USD), an order of 1045.61 USD shown at checkout as €816.88.
 * With "Order currency" selected NETOPIA was sent 1045.61 USD.
 */
#[CoversClass(PayloadBuilder::class)]
final class PayloadBuilderResolveCurrencyTest extends TestCase
{
    private const array ORDER = ['order_id' => 16, 'total' => 1045.61, 'secondary_currency' => 'EUR'];

    public function testOrderCurrencyChargesWhatTheCustomerSawAtCheckout(): void
    {
        [$currency, $amount] = $this->builder()->resolveCurrency(['currency' => 'order_currency'], self::ORDER);

        self::assertSame('EUR', $currency);
        self::assertSame(816.88, $amount);
    }

    public function testUnsetSettingIsOrderCurrency(): void
    {
        // The settings form shows "Order currency" first, so an untouched
        // payment method must behave the same way.
        self::assertSame(
            $this->builder()->resolveCurrency(['currency' => 'order_currency'], self::ORDER),
            $this->builder()->resolveCurrency([], self::ORDER),
        );
        self::assertSame(['EUR', 816.88], $this->builder()->resolveCurrency(['currency' => ''], self::ORDER));
    }

    public function testOrderCurrencyEqualToPrimaryIsNotConverted(): void
    {
        $order = ['secondary_currency' => 'USD'] + self::ORDER;

        self::assertSame(['USD', 1045.61], $this->builder()->resolveCurrency(['currency' => 'order_currency'], $order));
    }

    public function testOrderWithoutCheckoutCurrencyIsChargedInPrimary(): void
    {
        $order = self::ORDER;
        unset($order['secondary_currency']);

        self::assertSame(['USD', 1045.61], $this->builder()->resolveCurrency(['currency' => 'order_currency'], $order));
    }

    public function testNamedCurrencyWinsOverTheCheckoutCurrency(): void
    {
        $builder = $this->builder(['RON' => 0.21]); // 1 RON = 0.21 USD

        self::assertSame(['RON', 4979.1], $builder->resolveCurrency(['currency' => 'RON'], self::ORDER));
    }

    public function testPrimaryCurrencyChosenFromTheListIgnoresTheCheckoutCurrency(): void
    {
        self::assertSame(['USD', 1045.61], $this->builder()->resolveCurrency(['currency' => 'USD'], self::ORDER));
    }

    public function testWithoutARateTheOrderTotalIsChargedInPrimary(): void
    {
        // Never the primary-currency number labelled with another currency.
        $builder = $this->builder(convert: static fn (float $a, string $f, string $t): ?float => null);

        self::assertSame(['USD', 1045.61], $builder->resolveCurrency(['currency' => 'order_currency'], self::ORDER));
        self::assertSame(['USD', 1045.61], $builder->resolveCurrency(['currency' => 'GBP'], self::ORDER));
    }

    public function testStartRequestCarriesTheChargedAmountEverywhere(): void
    {
        $order = self::ORDER + [
            'email' => 'client@example.ro',
            'products' => [
                ['product' => 'ADMIRAL', 'product_code' => 'H1', 'price' => 1017.60],
                ['product' => 'Shipping', 'product_code' => 'S1', 'price' => 28.01],
            ],
        ];

        $json = $this->builder()->buildStartRequest(
            ['currency' => 'order_currency', 'order_description' => 'Order [order_id]: [total] [currency]'],
            $order,
            $this->threeDs(),
            1,
            null,
        );
        /** @var array{order: array{amount: float, currency: string, description: string, products: list<array{price: float}>}} $payload */
        $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(816.88, $payload['order']['amount']);
        self::assertSame('EUR', $payload['order']['currency']);
        self::assertSame('Order 16: 816.88 EUR', $payload['order']['description']);
        self::assertSame([795.0, 21.88], array_map(floatval(...), array_column($payload['order']['products'], 'price')));
    }

    /**
     * @param array<string, float> $usdPer one unit of each currency, in USD (CS-Cart's coefficient)
     * @param (\Closure(float, string, string): ?float)|null $convert
     */
    private function builder(array $usdPer = ['EUR' => 1.28], ?\Closure $convert = null): PayloadBuilder
    {
        $usdPer += ['USD' => 1.0];

        return new PayloadBuilder(
            clock:           new FakeClock(1746522480),
            notifyUrl:       'https://example/notify',
            redirectUrl:     'https://example/return',
            primaryCurrency: 'USD',
            convert:         $convert ?? static fn (float $amount, string $from, string $to): ?float
                => isset($usdPer[$from], $usdPer[$to]) ? round($amount * $usdPer[$from] / $usdPer[$to], 2) : null,
        );
    }

    private function threeDs(): ThreeDsData
    {
        return new ThreeDsData('ua', 'os', '1', 'false', '0', '0', '24', '800', '1200', 'none', 'false', 'ro', 'UTC', '0', '127.0.0.1');
    }
}
