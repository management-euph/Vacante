<?php

declare(strict_types=1);

namespace {
    // Load the procedural functions-under-test in the GLOBAL namespace, as
    // CS-Cart loads them.
    if (!defined('BOOTSTRAP')) {
        define('BOOTSTRAP', true);
    }
    if (!function_exists('fn_log_event')) {
        function fn_log_event(string $type, string $action, array $data = []): void
        {
        }
    }

    require_once dirname(__DIR__, 3) . '/functions/exchange_rates.php';
}

namespace Tygh\Addons\TravelCore\Tests\Unit\Functions {

    use PHPUnit\Framework\TestCase;

    /**
     * Characterization coverage for the exchange-rate helpers, added with the
     * boundary-typing paydown (BNR/DB-sourced mixed values now coerced through
     * TypeCoerce). Pins the BNR XML parse (incl. multiplier + currency filter),
     * the primary-relative coefficient maths (and that string rates coerce identically),
     * and the plain-text output formatting.
     */
    final class ExchangeRatesTest extends TestCase
    {
        private const string XML = '<?xml version="1.0"?>'
            . '<DataSet><Body><Cube date="2026-06-15">'
            . '<Rate currency="EUR">4.9750</Rate>'
            . '<Rate currency="USD">4.5800</Rate>'
            . '<Rate currency="HUF" multiplier="100">1.2500</Rate>'
            . '</Cube></Body></DataSet>';

        public function testParseExtractsRequestedCurrencies(): void
        {
            $rates = fn_travel_core_parse_bnr_xml(self::XML, ['EUR', 'USD']);

            $this->assertSame(4.975, $rates['EUR']);
            $this->assertSame(4.58, $rates['USD']);
            $this->assertArrayNotHasKey('HUF', $rates); // not requested
        }

        public function testParseAppliesMultiplier(): void
        {
            $rates = fn_travel_core_parse_bnr_xml(self::XML, ['HUF']);

            $this->assertSame(0.0125, $rates['HUF']); // 1.25 / 100
        }

        public function testParseWithPublishingDate(): void
        {
            $parsed = fn_travel_core_parse_bnr_xml(self::XML, ['EUR'], true);

            $this->assertSame('2026-06-15', $parsed['publishing_date']);
            $this->assertSame(4.975, $parsed['rates']['EUR']);
        }

        public function testCoefficientsAreTheValueOfOneUnitInTheEurPrimary(): void
        {
            // CS-Cart divides a primary price by the coefficient to show it in
            // that currency, so on an EUR store 1 RON must be worth ~0.2 EUR.
            $coeff = fn_travel_core_calculate_currency_coefficients(
                ['EUR' => 4.975, 'USD' => 4.58, 'GBP' => 5.8],
                0,
                'EUR',
            );

            $this->assertSame(['RON', 'USD', 'GBP'], array_keys($coeff), 'the primary itself is not written');
            $this->assertSame(0.20101, $coeff['RON']); // 1 / 4.975
            $this->assertSame(0.92060, $coeff['USD']); // 4.58 / 4.975
            $this->assertSame(1.16583, $coeff['GBP']); // 5.8 / 4.975
        }

        public function testCoefficientsFollowAUsdPrimary(): void
        {
            // The dev store: USD primary. 1 EUR = 4.975 / 4.58 USD; before the
            // fix GBP got 0.8578 ("GBP per EUR") where CS-Cart needs USD per GBP.
            $coeff = fn_travel_core_calculate_currency_coefficients(
                ['EUR' => 4.975, 'USD' => 4.58, 'GBP' => 5.8],
                0,
                'USD',
            );

            $this->assertSame(['RON', 'EUR', 'GBP'], array_keys($coeff));
            $this->assertSame(1.08624, $coeff['EUR']);
            $this->assertSame(1.26638, $coeff['GBP']);
            $this->assertSame(0.21834, $coeff['RON']);
        }

        public function testARonPrimaryUsesTheBnrRatesAsTheyAre(): void
        {
            $coeff = fn_travel_core_calculate_currency_coefficients(['EUR' => 4.975, 'USD' => 4.58], 0, 'RON');

            $this->assertSame(['EUR' => 4.975, 'USD' => 4.58], $coeff);
        }

        public function testCalculateCoercesStringRatesAndAppliesCommission(): void
        {
            // String rates coerce identically; a 2% commission makes each
            // foreign currency worth 2% less, so prices shown in it are 2% higher.
            $coeff = fn_travel_core_calculate_currency_coefficients(['EUR' => '4.975'], 2, 'EUR');

            $this->assertSame(0.19706, $coeff['RON']); // 1 / 4.975 / 1.02
            $this->assertEqualsWithDelta(100 * 4.975 * 1.02, 100 / $coeff['RON'], 0.05, '100 EUR shows as 2% more RON');
        }

        public function testCalculateEmptyWithoutRatesOrWithoutThePrimary(): void
        {
            $this->assertSame([], fn_travel_core_calculate_currency_coefficients([], 0));
            $this->assertSame([], fn_travel_core_calculate_currency_coefficients(['EUR' => 4.975], 0, 'CHF'));
        }

        public function testFormatOutput(): void
        {
            $out = fn_travel_core_format_exchange_rate_output([
                'success' => true,
                'message' => 'Exchange rates updated successfully',
                'coefficients' => ['RON' => 4.975],
            ]);

            $this->assertStringContainsString('Status: SUCCESS', $out);
            $this->assertStringContainsString('Message: Exchange rates updated successfully', $out);
            $this->assertStringContainsString('RON: 4.975', $out);
        }

        public function testFormatOutputFailure(): void
        {
            $out = fn_travel_core_format_exchange_rate_output(['success' => false, 'message' => 'Failed to fetch']);

            $this->assertStringContainsString('Status: FAILED', $out);
            $this->assertStringContainsString('Message: Failed to fetch', $out);
        }
    }
}
