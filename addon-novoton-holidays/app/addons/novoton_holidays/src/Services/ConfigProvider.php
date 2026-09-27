<?php

declare(strict_types=1);

/**
 * Novoton Holidays - Configuration Provider
 *
 * Read-only access to addon settings from Registry with typed getters.
 * Single Responsibility: settings retrieval and type coercion only.
 *
 * @package NovotonHolidays
 * @since 3.3.0
 */

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Addons\NovotonHolidays\Constants;
use Tygh\Addons\TravelCore\Cron\CronKeyService;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\AbstractConfigProvider;
use Tygh\Addons\TravelCore\Services\TravelCoreConfig;
use Tygh\Registry;

class ConfigProvider extends AbstractConfigProvider
{
    public const string ADDON_ID = 'novoton_holidays';

    #[\Override]
    protected static function addonId(): string
    {
        return self::ADDON_ID;
    }

    // API rate limiting
    public const int API_DELAY_MS = 100;

    // Batch processing bounds (for clamping admin input)
    public const int MIN_BATCH_SIZE = 10;
    public const int MAX_BATCH_SIZE = 500;
    public const int MIN_EXECUTION_TIME = 60;
    public const int MAX_EXECUTION_TIME = 3600;

    public const int MAX_IMAGES_PER_HOTEL = 10;

    // Stale threshold for incremental sync (hours)
    public const int STALE_HOURS = 24;

    /** @var self|null Instance-based singleton. */
    private static ?self $instance = null;

    /** @var string|null Cached addon version, loaded once per request. */
    private static ?string $version = null;

    /** @var array<string, mixed>|null Instance-level settings (for injected/test instances). */
    private ?array $instanceSettings;

    /**
     * Instance constructor for DI / testing.
     *
     * @param array<string, mixed>|null $settings If provided, this instance uses these settings
     *                                            instead of the Registry. Pass null to use Registry.
     */
    public function __construct(?array $settings = null)
    {
        $this->instanceSettings = $settings;
    }

    /**
     * Get or create the singleton instance.
     */
    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Replace the singleton instance (for testing or multi-store).
     */
    public static function setInstance(?self $instance): void
    {
        self::$instance = $instance;
    }

    /**
     * Instance method: get settings array.
     * @return array<string, mixed>
     */
    public function getSettings(): array
    {
        if ($this->instanceSettings !== null) {
            return $this->instanceSettings;
        }
        return self::settings();
    }

    /**
     * Cached addon settings array (shared base plumbing).
     *
     * @return array<string, mixed>
     */
    public static function settings(): array
    {
        return static::loadSettings();
    }

    public static function reset(): void
    {
        static::resetSettingsCache();
        self::$version = null;
        self::$instance = null;
    }

    // ── Boolean Settings ──

    public static function isDebugMode(): bool
    {
        return (self::settings()['debug_mode'] ?? 'N') === 'Y';
    }

    public static function isDebugLogging(): bool
    {
        return (self::settings()['debug_logging'] ?? 'Y') === 'Y';
    }

    public static function isApiDisabled(): bool
    {
        return (self::settings()['disable_api_submission'] ?? 'N') === 'Y';
    }

    public static function isRoundPrices(): bool
    {
        return Registry::get('addons.travel_core.round_prices') === 'Y';
    }

    public static function isTestBooking(): bool
    {
        return (self::settings()['test_booking'] ?? 'N') === 'Y';
    }

    public static function isDeleteProductsOnUninstall(): bool
    {
        return (self::settings()['delete_products_on_uninstall'] ?? 'N') === 'Y';
    }

    public static function isShowCalendarPrices(): bool
    {
        return (self::settings()['show_calendar_prices'] ?? 'Y') === 'Y';
    }

    public static function isPreorderPriceCheckEnabled(): bool
    {
        return (self::settings()['enable_preorder_price_check'] ?? 'Y') === 'Y';
    }

    // ── Price Discrepancy Settings ──

    // NOTE: the "price higher than API" alert threshold moved to travel_core's
    // shared checkout price guard (TravelCore\Services\CheckoutPriceGuard,
    // setting `checkout_alert_percent`) so novoton and sphinx share ONE policy.
    // The former novoton-only `price_higher_threshold` setting + getter were
    // removed here to avoid a dead, misleading dial in the admin UI.

    /**
     * TTL (seconds) for the session-cached API price ("Silent Sync").
     * If the add_to_cart price verification is younger than this, the
     * pre_place_order hook trusts the cache and skips the API call.
     * Default: 180 seconds (3 minutes).
     */
    public static function getPreorderCacheTtl(): int
    {
        return max(0, TypeCoerce::toInt(self::settings()['preorder_cache_ttl'] ?? 180));
    }

    // ── Float Settings ──

    public static function getCommission(): float
    {
        return max(0.0, TypeCoerce::toFloat(self::settings()['commission'] ?? 0));
    }

    // ── String Settings ──

    public static function getApiCurrency(): string
    {
        $val = TypeCoerce::toString(self::settings()['api_currency'] ?? 'EUR');
        return $val !== '' ? $val : 'EUR';
    }

    public static function getApiUrl(): string
    {
        return TypeCoerce::toString(self::settings()['api_url'] ?? '');
    }

    public static function getApiUser(): string
    {
        return TypeCoerce::toString(self::settings()['api_user'] ?? '');
    }

    public static function getApiPassword(): string
    {
        return TypeCoerce::toString(self::settings()['api_password'] ?? '');
    }

    public static function getApiKey(): string
    {
        return TypeCoerce::toString(self::settings()['api_key'] ?? '');
    }

    /**
     * Travel Core owns this one.
     *
     * It authenticates OUR OWN cron endpoint, not us to the provider — we
     * issue it, we rotate it, it goes in one crontab — so it is store policy
     * and lives in Core, shared with the other travel add-ons. The provider's
     * own API credentials are the opposite case and stay here.
     *
     * The delegate keeps this method name so existing code goes on reading its
     * own ConfigProvider. getFor() falls back to this add-on's legacy
     * `cron_access_key` until the Core key is set; see CronKeyService.
     */
    public static function getCronAccessKey(): string
    {
        return CronKeyService::getFor('novoton_holidays');
    }

    public static function isCronReportEmailEnabled(): bool
    {
        return (self::settings()['send_cron_report_email'] ?? 'Y') === 'Y';
    }

    public static function getDefaultCountry(): string
    {
        return TypeCoerce::toString(self::settings()['default_country'] ?? 'BULGARIA');
    }

    // ── Geocoding (OSM Nominatim) Settings ──

    // Travel Core owns these settings outright: Settings -> Travel Core ->
    // Geocoding is the only place an admin configures them. There is no
    // novoton copy to fall back to — the Nominatim usage policy caps requests
    // per APPLICATION, both provider addons share GeocodeBacklogRunner, and a
    // per-addon switch would be a second control for one behaviour (worse
    // still, a stale novoton row could silently keep geocoding on after the
    // shared one was turned off). These thin delegates exist only so novoton
    // code keeps reading its own ConfigProvider.

    public static function isGeocodingEnabled(): bool
    {
        return TravelCoreConfig::isGeocodingEnabled();
    }

    /**
     * Contact email carried in the Nominatim User-Agent — the public
     * instance's usage policy requires identifying the application.
     */
    public static function getGeocodingContactEmail(): string
    {
        return TravelCoreConfig::getGeocodingContactEmail();
    }

    public static function getGeocodingEndpoint(): string
    {
        return TravelCoreConfig::getGeocodingEndpoint();
    }

    public static function getLastExchangeRateUpdate(): string
    {
        return TypeCoerce::toString(db_get_field(
            "SELECT MAX(sync_date) FROM ?:novoton_sync_log WHERE sync_type = 'exchange_rates' AND status = 'completed'",
        ) ?: '');
    }

    public static function getVersion(): string
    {
        if (self::$version === null) {
            self::$version = TypeCoerce::toString(db_get_field(
                'SELECT version FROM ?:addons WHERE addon = ?s',
                self::ADDON_ID,
            )) ?: 'unknown';
        }

        return self::$version;
    }

    // ── Array Settings ──

    /** @return string[] */
    public static function getSelectedCountries(): array
    {
        $val = self::settings()['selected_countries'] ?? '';
        $countries = [];

        // CS-Cart stores "multiple checkboxes" as ['KEY' => 'Y', 'KEY2' => 'N', ...]
        if (is_array($val)) {
            foreach ($val as $key => $enabled) {
                if ($enabled === 'Y') {
                    $countries[] = (string) $key;
                }
            }
        } elseif ($val !== '' && is_string($val)) {
            $countries = array_filter(array_map('trim', explode(',', $val)));
        }

        // Fallback: if nothing selected, return all available countries
        if (empty($countries)) {
            return Constants::COUNTRIES;
        }

        return $countries;
    }

    /** @return string[] */
    public static function getProductCodePrefixes(): array
    {
        $val = self::settings()['product_code_prefixes'] ?? 'NVT';
        return array_map('trim', explode(',', TypeCoerce::toString($val)));
    }

    public static function getFirstProductCodePrefix(): string
    {
        return self::getProductCodePrefixes()[0] ?? 'NVT';
    }

    public static function getDefaultProductQuantity(): int
    {
        return max(0, TypeCoerce::toInt(self::settings()['default_product_quantity'] ?? 555));
    }

    /**
     * Get the configured category ID for a country.
     *
     * Reads the country_category_map setting (one "COUNTRY:id" per line).
     * Returns the mapped category_id, or 0 if no mapping exists.
     */
    public static function getCategoryForCountry(string $country): int
    {
        $raw = trim(TypeCoerce::toString(self::settings()['country_category_map'] ?? ''));
        if ($raw === '') {
            return 0;
        }

        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }
            [$key, $value] = explode(':', $line, 2);
            if (strtoupper(trim($key)) === strtoupper($country)) {
                $id = (int) trim($value);
                return max($id, 0);
            }
        }

        return 0;
    }

    /** @return string[] Resorts that are internal-only and hidden from all UI listings. */
    public static function getHiddenResorts(): array
    {
        return Constants::HIDDEN_RESORTS;
    }

    /** @return list<string> */
    public static function getExcludedResorts(): array
    {
        return self::parseResortList(TypeCoerce::toString(self::settings()['excluded_resorts'] ?? ''));
    }

    /**
     * Resorts no product may be created for: the dashboard's excluded
     * resorts, any extra names a run passes (&exclude_resorts=a,b adds to
     * the saved list, never replaces it), and the internal HIDDEN_RESORTS.
     *
     * Every product-creation path reads this one list. add_hotels_as_products
     * used to be the only one that honoured the dashboard; offers_update and
     * the legacy admin run_cron path created products in excluded resorts.
     *
     * @param list<string> $extra
     * @return list<string>
     */
    public static function getProductExclusions(array $extra = []): array
    {
        $out = [];
        foreach ([...self::getExcludedResorts(), ...$extra, ...self::getHiddenResorts()] as $name) {
            $name = trim($name);
            if ($name !== '' && !in_array($name, $out, true)) {
                $out[] = $name;
            }
        }

        return $out;
    }

    /**
     * TRUE when a hotel's resort (novoton_hotels.city) is in the list,
     * compared trimmed and case-insensitively, as the dashboard groups them.
     *
     * @param list<string> $exclusions
     */
    public static function isResortExcluded(?string $city, array $exclusions): bool
    {
        $city = mb_strtoupper(trim((string) $city));
        if ($city === '') {
            return false;
        }
        foreach ($exclusions as $name) {
            if (mb_strtoupper(trim($name)) === $city) {
                return true;
            }
        }

        return false;
    }

    /**
     * The stored excluded-resorts value as a list of resort names.
     *
     * The dashboard saves it as a JSON array (`["ALBENA","VARNA"]`, default
     * `[]`); this getter used to split it on commas, which turned that into
     * `["ALBENA"` and `"VARNA"]` — names no hotel has, so add_hotels_as_products
     * excluded nothing while the dashboard showed the resorts as excluded.
     * Comma-separated text is still read, for values saved before the JSON form.
     *
     * @return list<string>
     */
    public static function parseResortList(string $stored): array
    {
        $stored = trim($stored);
        if ($stored === '') {
            return [];
        }

        $decoded = json_decode($stored, true);
        $names = is_array($decoded) ? TypeCoerce::toStringList(array_values($decoded)) : explode(',', $stored);

        $out = [];
        foreach ($names as $name) {
            $name = trim($name);
            if ($name !== '' && !in_array($name, $out, true)) {
                $out[] = $name;
            }
        }

        return $out;
    }

    // ── Advanced Settings (Cache, Sync, Rate Limits) ──

    public static function getCacheTtlRoomPrice(): int
    {
        return max(0, TypeCoerce::toInt(self::settings()['cache_ttl_room_price'] ?? 300));
    }

    public static function getCacheTtlAvailability(): int
    {
        return max(0, TypeCoerce::toInt(self::settings()['cache_ttl_availability'] ?? 180));
    }

    public static function getCacheTtlSearch(): int
    {
        return max(0, TypeCoerce::toInt(self::settings()['cache_ttl_search'] ?? 300));
    }

    /** Returns the hotel info full sync interval in seconds. */
    public static function getSyncIntervalHotelInfo(): int
    {
        $days = max(1, TypeCoerce::toInt(self::settings()['sync_interval_hotel_info_days'] ?? 180));
        return $days * 86400;
    }

    /** Returns the price info full sync interval in seconds. */
    public static function getSyncIntervalPriceInfo(): int
    {
        $days = max(1, TypeCoerce::toInt(self::settings()['sync_interval_price_info_days'] ?? 7));
        return $days * 86400;
    }

    /** Returns the facilities sync interval in seconds. */
    public static function getSyncIntervalFacilities(): int
    {
        $days = max(1, TypeCoerce::toInt(self::settings()['sync_interval_facilities_days'] ?? 30));
        return $days * 86400;
    }

    public static function getCronBatchSize(): int
    {
        $val = TypeCoerce::toInt(self::settings()['cron_batch_size'] ?? 100);
        return max(self::MIN_BATCH_SIZE, min(self::MAX_BATCH_SIZE, $val));
    }

    public static function getCronMaxExecutionTime(): int
    {
        $val = TypeCoerce::toInt(self::settings()['cron_max_execution_time'] ?? 300);
        return max(self::MIN_EXECUTION_TIME, min(self::MAX_EXECUTION_TIME, $val));
    }

    public static function getSlowItemWarningMs(): int
    {
        return max(1000, TypeCoerce::toInt(self::settings()['slow_item_warning_ms'] ?? 30000));
    }

    public static function getRateLimitRequestsPerMin(): int
    {
        return max(1, TypeCoerce::toInt(self::settings()['rate_limit_requests_per_min'] ?? 100));
    }

    public static function getRateLimitBookingsPerHour(): int
    {
        return max(1, TypeCoerce::toInt(self::settings()['rate_limit_bookings_per_hour'] ?? 20));
    }

    // ── Raw Access ──

    /**
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        return self::settings();
    }

    /**
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return self::settings()[$key] ?? $default;
    }

    // ── Environment ──

    /**
     * CS-Cart currency catalogue: code => per-currency metadata.
     *
     * Entries come from CS-Cart's `?:currencies` table and conventionally
     * carry at least `coefficient` (float) and `symbol` (string), but the
     * underlying registry value is typed `mixed` by PHPStan — downstream
     * callers should coerce per-field.
     *
     * @return array<string, mixed>
     */
    public static function getCurrencies(): array
    {
        return \Tygh\Addons\TravelCore\Helpers\TypeCoerce::toStringMap(Registry::get('currencies'));
    }

    public static function getTimezone(): string
    {
        return TypeCoerce::toString(Registry::get('settings.Appearance.timezone') ?: 'Europe/Bucharest');
    }

    public static function getAdminEmail(): string
    {
        $email = Registry::get('settings.Company.company_orders_email');

        if (empty($email)) {
            $email = Registry::get('settings.Company.company_site_administrator');
        }

        if (empty($email)) {
            $email = db_get_field(
                "SELECT email FROM ?:users WHERE user_type = 'A' AND status = 'A' ORDER BY user_id LIMIT 1",
            );
        }

        return TypeCoerce::toString($email ?: '');
    }

    public static function getCompanyId(): int
    {
        return TypeCoerce::toInt(Registry::get('runtime.company_id') ?: 1);
    }

    /**
     * Absolute filesystem path to the CS-Cart `app/addons/` directory.
     */
    public static function getAddonsDir(): string
    {
        $dir = Registry::get('config.dir.addons');
        return is_string($dir) ? $dir : '';
    }

    /**
     * Absolute filesystem path to CS-Cart's misc-cache directory
     * (runtime cache storage for addons).
     */
    public static function getCacheMiscDir(): string
    {
        $dir = Registry::get('config.dir.cache_misc');
        return is_string($dir) ? $dir : '';
    }

    /**
     * CS-Cart's global encryption key (`config.crypt_key`), used for
     * reversible tokenisation of addon secrets.
     */
    public static function getCryptKey(): string
    {
        $key = Registry::get('config.crypt_key');
        return is_string($key) ? $key : '';
    }

    /**
     * CS-Cart's admin-configured display date format
     * (e.g. '%d %b %Y'). Falls back to a sensible default when missing.
     */
    public static function getDateFormat(): string
    {
        $fmt = Registry::get('settings.Appearance.date_format');
        return is_string($fmt) && $fmt !== '' ? $fmt : '%d %b %Y';
    }

    // ── SEO Templates ──

    public static function getSeoProductName(): string
    {
        return TypeCoerce::toString(self::settings()['seo_product_name'] ?? '{{name}}');
    }

    public static function getSeoPageTitle(): string
    {
        return TypeCoerce::toString(self::settings()['seo_page_title'] ?? '{{name}} - {{city}}, {{country}} {{year}}');
    }

    public static function getSeoMetaDescription(): string
    {
        return TypeCoerce::toString(self::settings()['seo_meta_description'] ?? 'Rezervă cazare la {{name}} în {{city}}, {{country}}. Hotel de {{star_rating}} stele cu {{facilities}}. Vezi tarife și disponibilitate.');
    }

    public static function getSeoMetaKeywords(): string
    {
        return TypeCoerce::toString(self::settings()['seo_meta_keywords'] ?? '{{name}}, {{city}}, {{country}}, {{property_type}}, {{star_rating}} stele');
    }

    public static function getSeoNameSlug(): string
    {
        return TypeCoerce::toString(self::settings()['seo_name_slug'] ?? '{{name}}-{{city}}-{{country}}');
    }

    public static function getSeoFullDescription(): string
    {
        return TypeCoerce::toString(self::settings()['seo_full_description'] ?? '');
    }
}
