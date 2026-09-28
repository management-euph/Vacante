<?php

declare(strict_types=1);

namespace Netopia\CsCart\Tests\Payment;

use Netopia\CsCart\Payment\PayloadBuilder;
use Netopia\CsCart\Tests\Support\FakeClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PayloadBuilder::class)]
final class PayloadBuilderResolveCurrencyTest extends TestCase
{
    public function testEmptyConfiguredCurrencyFallsBackToPrimary(): void
    {
        $builder = $this->buildBuilder(primaryCurrency: 'RON');

        [$currency, $amount] = $builder->resolveCurrency(
            processorParams: ['currency' => ''],
            orderInfo:       ['total' => 343.44],
        );

        self::assertSame('RON', $currency);
        self::assertSame(343.44, $amount, 'no conversion when configured currency is empty');
    }

    public function testOrderCurrencySentinelFallsBackToPrimary(): void
    {
        $builder = $this->buildBuilder(primaryCurrency: 'RON');

        [$currency, $amount] = $builder->resolveCurrency(
            processorParams: ['currency' => 'order_currency'],
            orderInfo:       ['total' => 343.44],
        );

        self::assertSame('RON', $currency);
        self::assertSame(343.44, $amount);
    }

    public function testConfiguredCurrencyMatchingPrimarySkipsConversion(): void
    {
        $builder = $this->buildBuilder(primaryCurrency: 'RON');

        [$currency, $amount] = $builder->resolveCurrency(
            processorParams: ['currency' => 'RON'],
            orderInfo:       ['total' => 343.44],
        );

        self::assertSame('RON', $currency);
        self::assertSame(343.44, $amount);
    }

    public function testIgnoresOrderInfoSecondaryCurrencyField(): void
    {
        // Regression guard for the payment-link email bug: secondary_currency
        // is CS-Cart's *display* currency (whatever the customer or admin was
        // browsing in), NOT the NETOPIA processing currency. resolveCurrency
        // must use processor_params.currency as the source of truth and ignore
        // this field entirely — otherwise the email body would show converted
        // amounts mislabelled with the primary-currency code.
        $builder = $this->buildBuilder(primaryCurrency: 'RON');

        [$currency, $amount] = $builder->resolveCurrency(
            processorParams: [], // no `currency` configured
            orderInfo:       [
                'total' => 343.44,
                'secondary_currency' => 'EUR',
            ],
        );

        self::assertSame(
            'RON',
            $currency,
            'storefront secondary_currency must NOT influence the NETOPIA charge currency',
        );
        self::assertSame(343.44, $amount, 'no conversion when no charge currency is configured');
    }

    private function buildBuilder(string $primaryCurrency): PayloadBuilder
    {
        return new PayloadBuilder(
            clock:           new FakeClock(1746522480),
            notifyUrl:       'https://example/notify',
            redirectUrl:     'https://example/return',
            primaryCurrency: $primaryCurrency,
        );
    }
}
