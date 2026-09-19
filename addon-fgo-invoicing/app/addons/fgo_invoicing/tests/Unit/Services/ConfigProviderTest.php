<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\FgoInvoicing\Constants;
use Tygh\Addons\FgoInvoicing\Services\ConfigProvider;
use Tygh\Registry;

/**
 * ConfigProvider is the single read path for every addon setting, and its
 * defaults are what a freshly-installed (or half-configured) store runs on.
 * Two classes of behaviour are pinned here:
 *
 *   - Defaults + allowlists: an unknown or missing value must fall back to the
 *     documented default rather than reach the FGO API verbatim.
 *   - Clamps: retry/back-off/circuit-breaker numbers must stay in range, or a
 *     negative setting turns into an unbounded retry loop against a live
 *     invoicing API.
 */
#[CoversClass(ConfigProvider::class)]
final class ConfigProviderTest extends TestCase
{
    protected function tearDown(): void
    {
        ConfigProvider::reset();
        Registry::clear();
    }

    // ── Credentials ──────────────────────────────────────────────────────

    public function testCredentialsAreTrimmedAndDefaultToEmpty(): void
    {
        ConfigProvider::seed(['client_code' => "  CLI-1\n", 'private_key' => 'k-1']);

        self::assertSame('CLI-1', ConfigProvider::clientCode());
        self::assertSame('k-1', ConfigProvider::privateKey());

        ConfigProvider::seed([]);
        self::assertSame('', ConfigProvider::clientCode());
        self::assertSame('', ConfigProvider::privateKey());
    }

    public function testSandboxIsTheDefaultAndSelectsTheUatBaseUrl(): void
    {
        ConfigProvider::seed([]);
        self::assertTrue(ConfigProvider::isSandbox(), 'an unconfigured store must not invoice against production');
        self::assertSame(Constants::API_BASE_SANDBOX, ConfigProvider::apiBaseUrl());

        ConfigProvider::seed(['sandbox' => 'N']);
        self::assertFalse(ConfigProvider::isSandbox());
        self::assertSame(Constants::API_BASE_PROD, ConfigProvider::apiBaseUrl());
    }

    // ── Trigger / behaviour ──────────────────────────────────────────────

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function apiCallCases(): array
    {
        return [
            'onOrder'      => [Constants::TRIGGER_ON_ORDER, Constants::TRIGGER_ON_ORDER],
            'onPayment'    => [Constants::TRIGGER_ON_PAYMENT, Constants::TRIGGER_ON_PAYMENT],
            'onCompleted'  => [Constants::TRIGGER_ON_COMPLETED, Constants::TRIGGER_ON_COMPLETED],
            'manual'       => [Constants::TRIGGER_MANUAL, Constants::TRIGGER_MANUAL],
            'unknown'      => ['whenever', Constants::TRIGGER_ON_PAYMENT],
            'empty'        => ['', Constants::TRIGGER_ON_PAYMENT],
            'non-string'   => [['onOrder'], Constants::TRIGGER_ON_PAYMENT],
        ];
    }

    #[DataProvider('apiCallCases')]
    public function testApiCallFallsBackToOnPayment(mixed $seeded, string $expected): void
    {
        ConfigProvider::seed(['api_call' => $seeded]);

        self::assertSame($expected, ConfigProvider::apiCall());
    }

    public function testApiCallDefaultsToOnPaymentWhenUnset(): void
    {
        ConfigProvider::seed([]);

        self::assertSame(Constants::TRIGGER_ON_PAYMENT, ConfigProvider::apiCall());
    }

    public function testInvoiceTypeAndSeries(): void
    {
        ConfigProvider::seed(['invoice_type' => 'Proforma', 'invoice_series' => ' FGO ']);
        self::assertSame('Proforma', ConfigProvider::invoiceType());
        self::assertSame('FGO', ConfigProvider::invoiceSeries());

        ConfigProvider::seed(['invoice_type' => '']);
        self::assertSame('Factura', ConfigProvider::invoiceType(), 'empty type must not reach the API');
        self::assertSame('', ConfigProvider::invoiceSeries());
    }

    public function testDuplicateVerificationAndPdfEmailDefaultToOn(): void
    {
        ConfigProvider::seed([]);
        self::assertTrue(ConfigProvider::verifyDuplicate(), 'duplicate check must be on by default');
        self::assertTrue(ConfigProvider::autoEmailPdf());

        ConfigProvider::seed(['verify_duplicate' => 'N', 'auto_email_pdf' => 'N']);
        self::assertFalse(ConfigProvider::verifyDuplicate());
        self::assertFalse(ConfigProvider::autoEmailPdf());
    }

    // ── Customer / VAT ───────────────────────────────────────────────────

    public function testVatAndIdentityFlagsDefaultToOff(): void
    {
        ConfigProvider::seed([]);
        self::assertFalse(ConfigProvider::sanitizeVat());
        self::assertFalse(ConfigProvider::clientVatRequired());
        self::assertFalse(ConfigProvider::clientCnpRequired());

        ConfigProvider::seed([
            'sanitize_vat' => 'Y',
            'client_vat_required' => 'Y',
            'client_cnp_required' => 'Y',
        ]);
        self::assertTrue(ConfigProvider::sanitizeVat());
        self::assertTrue(ConfigProvider::clientVatRequired());
        self::assertTrue(ConfigProvider::clientCnpRequired());
    }

    // ── Lines / codes ────────────────────────────────────────────────────

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function articleIdFieldCases(): array
    {
        return [
            'none'     => ['none', 'none'],
            'sku'      => ['sku', 'sku'],
            'ean13'    => ['ean13', 'ean13'],
            'isbn'     => ['isbn', 'isbn'],
            'upc'      => ['upc', 'upc'],
            'unknown'  => ['barcode', 'sku'],
            'empty'    => ['', 'sku'],
        ];
    }

    #[DataProvider('articleIdFieldCases')]
    public function testArticleIdFieldIsAllowlisted(mixed $seeded, string $expected): void
    {
        ConfigProvider::seed(['article_id_field' => $seeded]);

        self::assertSame($expected, ConfigProvider::articleIdField());
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function shippingTaxVatCases(): array
    {
        return [
            'included'     => ['vat_included', 'vat_included'],
            'not included' => ['vat_not_included', 'vat_not_included'],
            'zero'         => ['vat_zero', 'vat_zero'],
            'unknown'      => ['vat_maybe', 'vat_not_included'],
            'unset'        => [null, 'vat_not_included'],
        ];
    }

    #[DataProvider('shippingTaxVatCases')]
    public function testShippingTaxVatIsAllowlisted(mixed $seeded, string $expected): void
    {
        ConfigProvider::seed(['shipping_tax_vat' => $seeded]);

        self::assertSame($expected, ConfigProvider::shippingTaxVat());
    }

    public function testLineCodesFallBackToTheirDefaults(): void
    {
        ConfigProvider::seed(['shipping_code' => '   ', 'discount_code' => '', 'administration_code' => ' ADM ']);

        self::assertSame('SHIPPING', ConfigProvider::shippingCode(), 'a blank code would emit an unnamed line');
        self::assertSame('DISCOUNT', ConfigProvider::discountCode());
        self::assertSame('ADM', ConfigProvider::administrationCode());

        ConfigProvider::seed(['shipping_code' => 'TRANSPORT', 'discount_code' => 'RED']);
        self::assertSame('TRANSPORT', ConfigProvider::shippingCode());
        self::assertSame('RED', ConfigProvider::discountCode());
        self::assertSame('', ConfigProvider::administrationCode(), 'administration code has no default');
    }

    // ── Resilience clamps ────────────────────────────────────────────────

    public function testResilienceNumbersUseDocumentedDefaults(): void
    {
        ConfigProvider::seed([]);

        self::assertSame(1000, ConfigProvider::minCallIntervalMs());
        self::assertSame(2, ConfigProvider::maxRetries());
        self::assertSame(500, ConfigProvider::retryDelayMs());
        self::assertSame(5, ConfigProvider::cbThreshold());
        self::assertSame(60, ConfigProvider::cbTimeout());
        self::assertTrue(ConfigProvider::debugLogging(), 'debug logging is opt-out, not opt-in');
    }

    public function testResilienceNumbersAreClampedAndCoercedFromStrings(): void
    {
        ConfigProvider::seed([
            'min_call_interval_ms' => '-5',
            'api_max_retries' => -1,
            'api_retry_delay_ms' => 'abc',
            // threshold/timeout are floored at 1: zero would trip the breaker
            // on every call, or expire it instantly.
            'api_cb_threshold' => 0,
            'api_cb_timeout' => -30,
            'debug_logging' => 'N',
        ]);

        self::assertSame(0, ConfigProvider::minCallIntervalMs());
        self::assertSame(0, ConfigProvider::maxRetries());
        self::assertSame(0, ConfigProvider::retryDelayMs());
        self::assertSame(1, ConfigProvider::cbThreshold());
        self::assertSame(1, ConfigProvider::cbTimeout());
        self::assertFalse(ConfigProvider::debugLogging());

        ConfigProvider::seed([
            'min_call_interval_ms' => '250',
            'api_max_retries' => '4',
            'api_retry_delay_ms' => '750',
            'api_cb_threshold' => '9',
            'api_cb_timeout' => '120',
        ]);

        self::assertSame(250, ConfigProvider::minCallIntervalMs());
        self::assertSame(4, ConfigProvider::maxRetries());
        self::assertSame(750, ConfigProvider::retryDelayMs());
        self::assertSame(9, ConfigProvider::cbThreshold());
        self::assertSame(120, ConfigProvider::cbTimeout());
    }

    // ── Platform metadata (read-through to the Registry) ─────────────────

    public function testPlatformUrlPrefersHttpLocationThenCurrentLocation(): void
    {
        Registry::set('config.http_location', 'https://shop.example.ro');
        Registry::set('config.current_location', 'https://fallback.example.ro');
        self::assertSame('https://shop.example.ro', ConfigProvider::platformUrl());

        Registry::set('config.http_location', '');
        self::assertSame('https://fallback.example.ro', ConfigProvider::platformUrl());

        Registry::set('config.current_location', null);
        self::assertSame('', ConfigProvider::platformUrl());
    }

    public function testPlatformAndAddonVersionsFallBackWhenUnknown(): void
    {
        Registry::set('config.product_version', '4.21.0');
        self::assertSame('4.21.0', ConfigProvider::platformVersion());

        Registry::set('config.product_version', '');
        self::assertSame('4.20.1', ConfigProvider::platformVersion());

        Registry::set('config.product_version', 420);
        self::assertSame('4.20.1', ConfigProvider::platformVersion(), 'a non-string version must not be sent');

        // FGO_INVOICING_VERSION is defined by init.php, which unit tests never
        // load — so this exercises the documented fallback.
        self::assertSame(
            defined('FGO_INVOICING_VERSION') ? (string) FGO_INVOICING_VERSION : '0.1.0',
            ConfigProvider::addonVersion(),
        );
    }

    // ── Cache lifecycle ──────────────────────────────────────────────────

    public function testSettingsAreCachedUntilResetThenReadFromTheRegistry(): void
    {
        Registry::set('addons.' . Constants::ADDON_ID, ['invoice_series' => 'REG']);

        ConfigProvider::seed(['invoice_series' => 'SEEDED']);
        self::assertSame('SEEDED', ConfigProvider::invoiceSeries());

        // Still the seeded copy: settings() caches and must not re-read.
        Registry::set('addons.' . Constants::ADDON_ID, ['invoice_series' => 'CHANGED']);
        self::assertSame('SEEDED', ConfigProvider::invoiceSeries());

        ConfigProvider::reset();
        self::assertSame(['invoice_series' => 'CHANGED'], ConfigProvider::settings());
        self::assertSame('CHANGED', ConfigProvider::invoiceSeries());
    }

    public function testSettingsDegradeToAnEmptyArrayWhenTheRegistryEntryIsMissing(): void
    {
        ConfigProvider::reset();

        self::assertSame([], ConfigProvider::settings());
        self::assertSame('Factura', ConfigProvider::invoiceType());
    }
}
