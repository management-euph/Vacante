<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Services;

use Tygh\Addons\SphinxHolidays\Contracts\ConfigProviderInterface;
use Tygh\Addons\SphinxHolidays\Repository\DestinationWhitelistRepository;
use Tygh\Addons\TravelCore\Cron\CronKeyService;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\AbstractConfigProvider;
use Tygh\Registry;

/**
 * Sphinx Holidays configuration provider.
 *
 * Centralizes access to all addon settings with type-safe getters
 * and sensible defaults. Registry plumbing (cached settings array,
 * getSetting) comes from the shared travel_core base.
 */
class ConfigProvider extends AbstractConfigProvider implements ConfigProviderInterface
{
    private const string ADDON_ID = 'sphinx_holidays';

    #[\Override]
    protected static function addonId(): string
    {
        return self::ADDON_ID;
    }

    public static function getApiBaseUrl(): string
    {
        return rtrim(TypeCoerce::toString(self::getSetting('api_base_url')), '/');
    }

    public static function getApiKey(): string
    {
        return TypeCoerce::toString(self::getSetting('api_key'));
    }

    public static function isApiCacheEnabled(): bool
    {
        return self::getSetting('enable_api_cache') === 'Y';
    }

    public static function getCacheTtlSearch(): int
    {
        return max(0, TypeCoerce::toInt(self::getSetting('cache_ttl_search', 900)));
    }

    public static function getDefaultCurrency(): string
    {
        return TypeCoerce::toString(self::getSetting('default_currency', 'EUR'));
    }

    /** @return array<string, string> Currency code => display symbol */
    /**
     * @return array<string, mixed>
     */
    public static function getCurrencySymbols(): array
    {
        return ['EUR' => '€', 'USD' => '$', 'GBP' => '£', 'RON' => 'lei', 'BGN' => 'лв'];
    }

    public static function getIgnoreDomains(): string
    {
        return TypeCoerce::toString(self::getSetting('ignore_domains', ''));
    }

    public static function getSearchPollInterval(): int
    {
        return max(1, TypeCoerce::toInt(self::getSetting('search_poll_interval', 2)));
    }

    public static function getSearchMaxPolls(): int
    {
        return max(1, TypeCoerce::toInt(self::getSetting('search_max_polls', 30)));
    }

    public static function getCommission(): float
    {
        return TypeCoerce::toFloat(self::getSetting('commission', 0));
    }

    public static function shouldRoundPrices(): bool
    {
        return Registry::get('addons.travel_core.round_prices') === 'Y';
    }

    public static function getHotelsCategoryId(): int
    {
        return TypeCoerce::toInt(self::getSetting('hotels_category_id', 0));
    }

    /** CS-Cart runtime company context — 1 in single-store mode. */
    public static function getCompanyId(): int
    {
        $value = Registry::get('runtime.company_id');
        return is_numeric($value) && (int) $value > 0 ? (int) $value : 1;
    }

    public static function getPackagesCategoryId(): int
    {
        return TypeCoerce::toInt(self::getSetting('packages_category_id', 0));
    }

    public static function getCircuitsCategoryId(): int
    {
        return TypeCoerce::toInt(self::getSetting('circuits_category_id', 0));
    }

    public static function getExperiencesCategoryId(): int
    {
        return TypeCoerce::toInt(self::getSetting('experiences_category_id', 0));
    }

    public static function getMaxRetries(): int
    {
        return max(0, TypeCoerce::toInt(self::getSetting('api_max_retries', 3)));
    }

    public static function getRetryDelayMs(): int
    {
        return max(0, TypeCoerce::toInt(self::getSetting('api_retry_delay_ms', 500)));
    }

    public static function getRetryMultiplier(): float
    {
        return max(1.0, TypeCoerce::toFloat(self::getSetting('api_retry_multiplier', 2)));
    }

    public static function getCircuitBreakerThreshold(): int
    {
        return max(1, TypeCoerce::toInt(self::getSetting('circuit_breaker_threshold', 5)));
    }

    public static function getCircuitBreakerTimeout(): int
    {
        return max(1, TypeCoerce::toInt(self::getSetting('circuit_breaker_timeout', 60)));
    }

    public static function isDebugLogging(): bool
    {
        return self::getSetting('debug_logging') === 'Y';
    }

    /**
     * Whether to emit search-path timing / hit-rate metrics (SearchMetrics).
     *
     * A dedicated low-volume flag so metrics can run continuously in production
     * without the noise of full debug logging. Debug logging implies metrics.
     */
    public static function isSearchMetricsEnabled(): bool
    {
        return self::getSetting('search_metrics') === 'Y' || self::isDebugLogging();
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
        return CronKeyService::getFor('sphinx_holidays');
    }

    /**
     * Get the product code prefix for Sphinx hotel products (e.g. 'SPX').
     */
    public static function getProductCodePrefix(): string
    {
        return TypeCoerce::toString(self::getSetting('product_code_prefix', 'SPX'));
    }

    public static function getDefaultProductQuantity(): int
    {
        return max(0, TypeCoerce::toInt(self::getSetting('default_product_quantity', 777)));
    }

    /**
     * Whether to skip creating products for hotels without a description.
     */
    public static function shouldSkipNoDescription(): bool
    {
        return self::getSetting('skip_no_description', 'N') === 'Y';
    }

    /**
     * Whether to skip creating products for hotels without a star classification (0 stars).
     * Default: Y (enabled) — only hotels with 1-5 stars are imported.
     */
    public static function shouldSkipUnratedHotels(): bool
    {
        return self::getSetting('skip_unrated_hotels', 'Y') === 'Y';
    }

    /**
     * Whether immediate availability is required.
     * Default: Y (enabled).
     *
     * When enabled, the hotels cron only adds hotels that have at least one
     * search offer with confirmation=immediate, and the storefront search shows
     * only immediate-confirmation offers. The hotels cron honours a per-run
     * override (&availability_gate=0|1); this is the persistent default for both
     * the sync gate and the storefront filter.
     */
    public static function shouldRequireImmediateAvailability(): bool
    {
        return self::getSetting('require_immediate_availability', 'Y') === 'Y';
    }

    /**
     * Seconds to trust the add-to-cart verified price at Place Order
     * ("Silent Sync" — the same knob novoton's checkout has). Within the
     * TTL the pre-order verifier skips its API round-trip; 0 disables the
     * cache so every Place Order click re-verifies live.
     */
    public static function getPreorderCacheTtl(): int
    {
        return max(0, TypeCoerce::toInt(self::getSetting('preorder_cache_ttl', '180')));
    }

    /**
     * Get the configured languages for hotel product descriptions.
     *
     * CS-Cart stores "multiple checkboxes" values as comma-separated string.
     * Returns array of lang_code strings (e.g. ['ro', 'en']).
     * Defaults to 'ro' if no setting configured.
     *
     * @return string[]
     */
    public static function getProductLanguages(): array
    {
        $value = self::getSetting('product_languages', 'ro');

        // CS-Cart may return an array (multiple checkboxes) or a comma-separated string
        if (is_array($value)) {
            return array_values(array_filter(TypeCoerce::toStringList($value)));
        }

        $value = TypeCoerce::toString($value);
        if (empty($value)) {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }

    /**
     * Get selected country codes for hotel sync filtering.
     *
     * @return list<string> Uppercase country codes (e.g. ['GR', 'BG', 'TR'])
     */
    public static function getSelectedCountryCodes(): array
    {
        self::migrateFromLegacySetting();

        return (new DestinationWhitelistRepository())->getCountryCodes();
    }

    /**
     * Check if the addon is properly configured (has API key).
     */
    public static function isConfigured(): bool
    {
        $key = self::getApiKey();
        $url = self::getApiBaseUrl();

        if (empty($key) || empty($url)) {
            return false;
        }

        // Sphinx keys have format: digits|alphanumeric (e.g. 51|q3s6ZrK7...)
        if (preg_match('/^\d+\|[a-zA-Z0-9]+$/', $key) !== 1) {
            return false;
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false || !str_starts_with($url, 'https://')) {
            return false;
        }

        return true;
    }

    /**
     * Get the full set of allowed destination IDs from sync targets.
     *
     * Resolves country codes → all destination IDs for those countries,
     * merges with explicitly selected destination IDs + their children.
     * Used by circuit, experience, and package route sync services
     * for client-side filtering.
     *
     * @return list<int> Destination IDs allowed by sync targets (empty = nothing configured)
     */
    public static function getAllowedDestinationIds(): array
    {
        static $cached = null;
        if ($cached !== null) {
            return TypeCoerce::toIntList($cached);
        }

        self::migrateFromLegacySetting();

        $whitelistEntries = (new DestinationWhitelistRepository())->findAll();
        if (!empty($whitelistEntries)) {
            $cached = self::resolveWhitelistEntries($whitelistEntries);
        } else {
            $cached = [];
        }

        return TypeCoerce::toIntList($cached);
    }

    /**
     * Resolve whitelist table entries into a flat set of destination IDs.
     *
     * For entries with selection_type='all' (country-level), includes all
     * destinations under that country. For 'specific', includes only the
     * explicitly listed destinations.
     *
     * @param list<array<string, mixed>> $entries Rows from sphinx_destination_whitelist
     * @return list<int> Deduplicated destination IDs
     */
    private static function resolveWhitelistEntries(array $entries): array
    {
        $allIds = [];
        $countryLookupIds = [];

        // Collect all destination IDs and identify which need country expansion
        foreach ($entries as $entry) {
            $destId = TypeCoerce::toInt($entry['destination_id']);
            $allIds[] = $destId;

            if ($entry['selection_type'] === 'all') {
                $countryLookupIds[] = $destId;
            }
        }

        // Batch-fetch country codes for all "all" entries (1 query instead of N)
        if (!empty($countryLookupIds)) {
            $wlRepo = new DestinationWhitelistRepository();
            $destToCountry = $wlRepo->getCountryCodesForDestinations($countryLookupIds);

            $countryCodes = array_unique(array_filter(array_values($destToCountry)));
            if (!empty($countryCodes)) {
                // Batch-fetch all child destinations for these countries (1 query instead of N)
                $childIds = $wlRepo->getDestinationIdsByCountry($countryCodes);
                $allIds = array_merge($allIds, $childIds);
            }
        }

        return array_values(array_unique($allIds));
    }

    /**
     * One-time migration: if the whitelist table is empty but the legacy
     * selected_destinations textarea setting has values, populate the
     * whitelist table with country-level entries (selection_type='all').
     */
    private static function migrateFromLegacySetting(): void
    {
        static $migrated = false;
        if ($migrated) {
            return;
        }
        $migrated = true;

        $wlRepo = new DestinationWhitelistRepository();

        if ($wlRepo->count() > 0) {
            return; // Whitelist already has data, no migration needed
        }

        $val = TypeCoerce::toString(self::getSetting('selected_destinations', ''));
        $tokens = array_filter(array_map('trim', explode(',', $val)));
        if (empty($tokens)) {
            return;
        }

        foreach ($tokens as $token) {
            if (strlen($token) === 2 && ctype_alpha($token)) {
                $countryCode = strtoupper($token);
                $countryDestId = $wlRepo->findCountryDestination($countryCode);
                if ($countryDestId !== null && $countryDestId > 0) {
                    $wlRepo->insertIgnore($countryDestId);
                }
            }
        }
    }
}
