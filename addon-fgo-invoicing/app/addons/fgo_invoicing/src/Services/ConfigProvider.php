<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Services;

use Tygh\Addons\FgoInvoicing\Constants;
use Tygh\Addons\FgoInvoicing\Helpers\TypeCoerce;
use Tygh\Registry;

/**
 * Read-only typed accessors over the addon's settings registry entry.
 *
 * Mirrors the pattern used by NovotonHolidays\Services\ConfigProvider —
 * static accessors, lazily-cached settings array, resettable for tests.
 */
final class ConfigProvider
{
    /** @var array<string, mixed>|null */
    private static ?array $settings = null;

    /**
     * @return array<string, mixed>
     */
    public static function settings(): array
    {
        if (self::$settings === null) {
            self::$settings = TypeCoerce::toAssocArray(Registry::get('addons.' . Constants::ADDON_ID));
        }
        return self::$settings;
    }

    public static function reset(): void
    {
        self::$settings = null;
    }

    /**
     * Test seam: inject settings without going through Registry.
     *
     * @param array<string, mixed> $settings
     */
    public static function seed(array $settings): void
    {
        self::$settings = $settings;
    }

    // ── Credentials ──────────────────────────────────────────────────────

    public static function clientCode(): string
    {
        return trim(TypeCoerce::toString(self::settings()['client_code'] ?? ''));
    }

    public static function privateKey(): string
    {
        return TypeCoerce::toString(self::settings()['private_key'] ?? '');
    }

    public static function isSandbox(): bool
    {
        return (self::settings()['sandbox'] ?? 'Y') === 'Y';
    }

    public static function apiBaseUrl(): string
    {
        return self::isSandbox() ? Constants::API_BASE_SANDBOX : Constants::API_BASE_PROD;
    }

    // ── Trigger / behaviour ──────────────────────────────────────────────

    public static function apiCall(): string
    {
        $val = TypeCoerce::toString(self::settings()['api_call'] ?? Constants::TRIGGER_ON_PAYMENT);
        $allowed = [
            Constants::TRIGGER_ON_ORDER,
            Constants::TRIGGER_ON_PAYMENT,
            Constants::TRIGGER_ON_COMPLETED,
            Constants::TRIGGER_MANUAL,
        ];
        return in_array($val, $allowed, true) ? $val : Constants::TRIGGER_ON_PAYMENT;
    }

    public static function invoiceType(): string
    {
        $val = TypeCoerce::toString(self::settings()['invoice_type'] ?? 'Factura');
        return $val !== '' ? $val : 'Factura';
    }

    public static function invoiceSeries(): string
    {
        return trim(TypeCoerce::toString(self::settings()['invoice_series'] ?? ''));
    }

    public static function verifyDuplicate(): bool
    {
        return (self::settings()['verify_duplicate'] ?? 'Y') === 'Y';
    }

    public static function autoEmailPdf(): bool
    {
        return (self::settings()['auto_email_pdf'] ?? 'Y') === 'Y';
    }

    public static function minCallIntervalMs(): int
    {
        return max(0, TypeCoerce::toInt(self::settings()['min_call_interval_ms'] ?? 1000));
    }

    // ── Customer / VAT ───────────────────────────────────────────────────

    public static function sanitizeVat(): bool
    {
        return (self::settings()['sanitize_vat'] ?? 'N') === 'Y';
    }

    /**
     * "Do not issue for company customers without a CIF" — InvoiceIssuer
     * refuses to call FGO for a PJ customer whose CIF resolved empty.
     */
    public static function clientVatRequired(): bool
    {
        return (self::settings()['client_vat_required'] ?? 'N') === 'Y';
    }

    /**
     * "Do not issue for individuals without a CNP" — InvoiceIssuer refuses to
     * call FGO for a Romanian PF customer with no CNP.
     */
    public static function clientCnpRequired(): bool
    {
        return (self::settings()['client_cnp_required'] ?? 'N') === 'Y';
    }

    /** Custom profile field holding the company CIF; 0 = auto-detect by field name. */
    public static function cifFieldId(): int
    {
        return self::profileFieldId('cif_field');
    }

    /** Custom profile field holding the Reg. Com. number; 0 = auto-detect by field name. */
    public static function regComFieldId(): int
    {
        return self::profileFieldId('reg_com_field');
    }

    /** Custom profile field holding the CNP; 0 = auto-detect by field name. */
    public static function cnpFieldId(): int
    {
        return self::profileFieldId('cnp_field');
    }

    /**
     * A profile-field selector: a positive field id, else 0 ("Auto-detect by
     * field name"). 0 is also what a store runs on when the setting row does
     * not exist — CS-Cart creates settings only at install, so every store
     * installed before these selectors must keep working without them.
     */
    private static function profileFieldId(string $key): int
    {
        $raw = self::settings()[$key] ?? '';
        if (is_int($raw)) {
            return max(0, $raw);
        }
        $value = trim(TypeCoerce::toString($raw));

        return preg_match('/^\d{1,9}$/', $value) === 1 ? (int) $value : 0;
    }

    // ── Lines / Codes ────────────────────────────────────────────────────

    public static function articleIdField(): string
    {
        $val = TypeCoerce::toString(self::settings()['article_id_field'] ?? 'sku');
        $allowed = ['none', 'sku', 'ean13', 'isbn', 'upc'];
        return in_array($val, $allowed, true) ? $val : 'sku';
    }

    public static function productDescription(): bool
    {
        return (self::settings()['product_description'] ?? 'N') === 'Y';
    }

    public static function additionalInfo(): bool
    {
        return (self::settings()['additional_info'] ?? 'Y') === 'Y';
    }

    public static function shippingTaxVat(): string
    {
        $val = TypeCoerce::toString(self::settings()['shipping_tax_vat'] ?? 'vat_not_included');
        $allowed = ['vat_included', 'vat_not_included', 'vat_zero'];
        return in_array($val, $allowed, true) ? $val : 'vat_not_included';
    }

    public static function shippingCode(): string
    {
        $val = trim(TypeCoerce::toString(self::settings()['shipping_code'] ?? ''));
        return $val !== '' ? $val : 'SHIPPING';
    }

    public static function discountCode(): string
    {
        $val = trim(TypeCoerce::toString(self::settings()['discount_code'] ?? ''));
        return $val !== '' ? $val : 'DISCOUNT';
    }

    public static function administrationCode(): string
    {
        return trim(TypeCoerce::toString(self::settings()['administration_code'] ?? ''));
    }

    // ── Platform metadata (read-through to global config) ────────────────

    /**
     * `PlatformaUrl` field on every FGO request — the storefront URL.
     * Reads `config.http_location` (or `current_location`) from the global
     * Registry. ConfigProvider is the addon's allowlisted Registry boundary,
     * so the static-analysis disallowed-calls rule permits this here.
     */
    public static function platformUrl(): string
    {
        $http = Registry::get('config.http_location');
        if (is_string($http) && $http !== '') {
            return $http;
        }
        $cur = Registry::get('config.current_location');
        return is_string($cur) ? $cur : '';
    }

    /** `Versiune` field — CS-Cart core version. */
    public static function platformVersion(): string
    {
        $v = Registry::get('config.product_version');
        return is_string($v) && $v !== '' ? $v : '4.20.1';
    }

    /**
     * The store's primary currency — the currency EVERY amount of a CS-Cart
     * order is stored in (totals, prices, shipping, discounts). Amounts are
     * only converted for display, so this, and not the currency the shopper
     * was browsing in, is the invoice's `Valuta`.
     *
     * CART_PRIMARY_CURRENCY is defined by fn_init_currency(), i.e. on every
     * real request after init; 'RON' covers partial bootstraps and tests.
     */
    public static function primaryCurrency(): string
    {
        $code = '';
        if (defined('CART_PRIMARY_CURRENCY')) {
            $code = strtoupper(trim(TypeCoerce::toString(CART_PRIMARY_CURRENCY)));
        }

        return $code !== '' ? $code : 'RON';
    }

    /** `VersiuneAddon` field — this addon's version (set in init.php). */
    public static function addonVersion(): string
    {
        return defined('FGO_INVOICING_VERSION') ? TypeCoerce::toString(FGO_INVOICING_VERSION) : '0.1.0';
    }

    // ── Resilience / Diagnostics ─────────────────────────────────────────

    public static function maxRetries(): int
    {
        return max(0, TypeCoerce::toInt(self::settings()['api_max_retries'] ?? 2));
    }

    public static function retryDelayMs(): int
    {
        return max(0, TypeCoerce::toInt(self::settings()['api_retry_delay_ms'] ?? 500));
    }

    public static function cbThreshold(): int
    {
        return max(1, TypeCoerce::toInt(self::settings()['api_cb_threshold'] ?? 5));
    }

    public static function cbTimeout(): int
    {
        return max(1, TypeCoerce::toInt(self::settings()['api_cb_timeout'] ?? 60));
    }

    public static function debugLogging(): bool
    {
        return (self::settings()['debug_logging'] ?? 'Y') === 'Y';
    }
}
