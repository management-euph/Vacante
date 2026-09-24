<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Services;

use Tygh\Addons\TravelCore\Cron\CronKeyService;
use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\AbstractConfigProvider;

/**
 * Eurosite configuration provider.
 *
 * Type-safe getters over the addon settings with sensible defaults. Registry
 * plumbing (cached settings array, getSetting) comes from the shared
 * travel_core base — this is the designated Registry-reader for the addon
 * (to be allowlisted in phpstan-disallowed-calls.neon when the addon joins
 * the repo's PHPStan paths).
 */
class ConfigProvider extends AbstractConfigProvider
{
    private const string ADDON_ID = 'eurosite';

    #[\Override]
    protected static function addonId(): string
    {
        return self::ADDON_ID;
    }

    public static function getApiUrl(): string
    {
        return rtrim(TypeCoerce::toString(self::getSetting('api_url')), '/');
    }

    public static function getApiUser(): string
    {
        return TypeCoerce::toString(self::getSetting('api_user'));
    }

    public static function getApiPassword(): string
    {
        return TypeCoerce::toString(self::getSetting('api_password'));
    }

    public static function getTourOpCode(): string
    {
        $code = TypeCoerce::toString(self::getSetting('tourop_code'));
        return $code !== '' ? $code : 'EU';
    }

    /**
     * Travel Core owns this one.
     *
     * It authenticates OUR OWN cron endpoint, not us to Eurosite — we issue
     * it, we rotate it, it goes in one crontab — so it is store policy and
     * lives in Core, shared with the other travel add-ons. `api_password`
     * above is the opposite case and stays here.
     *
     * The delegate keeps this method name so eurosite code goes on reading its
     * own ConfigProvider. getFor() falls back to this add-on's legacy
     * `cron_access_key` until the Core key is set; see CronKeyService.
     */
    public static function getCronAccessKey(): string
    {
        return CronKeyService::getFor('eurosite');
    }

    public static function getPaymentTermsText(): string
    {
        return TypeCoerce::toString(self::getSetting('payment_terms_text'));
    }

    public static function getDefaultCurrency(): string
    {
        $cur = TypeCoerce::toString(self::getSetting('default_currency'));
        return $cur !== '' ? $cur : 'EUR';
    }

    public static function getDefaultLanguage(): string
    {
        $lang = TypeCoerce::toString(self::getSetting('default_language'));
        return $lang !== '' ? $lang : 'RO';
    }

    /** Root category for hotel products; 0 = not set (no product is created). */
    public static function getHotelsCategoryId(): int
    {
        return max(0, TypeCoerce::toInt(self::getSetting('hotels_category_id', 0)));
    }

    /**
     * "Create products for hotels without images". Off by default: a product
     * page with no picture sells nothing, so those hotels wait until
     * pictures arrive.
     */
    public static function allowProductsWithoutImages(): bool
    {
        return self::getSetting('products_without_images', 'N') === 'Y';
    }

    /** Hide a product when none of the checked dates has an Immediate offer. */
    public static function hideUnavailableProducts(): bool
    {
        return self::getSetting('hide_unavailable_products', 'Y') === 'Y';
    }

    /** @return list<int> days ahead to check, e.g. [14, 30, 60] */
    public static function getAvailabilityNearDays(): array
    {
        return AvailabilityPlan::parseDays(TypeCoerce::toString(self::getSetting('availability_near_days', '14, 30, 60')));
    }

    /** Raw peak-season dates ("07-15, 08-15"); AvailabilityPlan resolves them. */
    public static function getAvailabilitySeasonDates(): string
    {
        return TypeCoerce::toString(self::getSetting('availability_season_dates', '07-15, 08-15'));
    }

    public static function getAvailabilityNights(): int
    {
        $n = TypeCoerce::toInt(self::getSetting('availability_nights', 7));

        return $n >= 1 && $n <= 21 ? $n : 7;
    }

    /** CS-Cart runtime company context — 1 in single-store mode. */
    public static function getCompanyId(): int
    {
        $value = \Tygh\Registry::get('runtime.company_id');

        return is_numeric($value) && (int) $value > 0 ? (int) $value : 1;
    }

    public static function allowInsecureApi(): bool
    {
        return self::getSetting('allow_insecure_api', 'Y') === 'Y';
    }

    public static function getMaxRetries(): int
    {
        return max(1, TypeCoerce::toInt(self::getSetting('api_max_retries', 3)));
    }

    public static function getTimeout(): int
    {
        return max(5, TypeCoerce::toInt(self::getSetting('api_timeout', 60)));
    }

    public static function getCircuitBreakerThreshold(): int
    {
        return max(1, TypeCoerce::toInt(self::getSetting('circuit_breaker_threshold', 5)));
    }

    public static function getCircuitBreakerTimeout(): int
    {
        return max(1, TypeCoerce::toInt(self::getSetting('circuit_breaker_timeout', 60)));
    }

    /**
     * All settings as a plain map, ready to hand to EurositeHttpClient.
     *
     * @return array<string, mixed>
     */
    public static function toClientSettings(): array
    {
        return [
            'api_url' => self::getApiUrl(),
            'api_user' => self::getApiUser(),
            'api_password' => self::getApiPassword(),
            'tourop_code' => self::getTourOpCode(),
            'default_currency' => self::getDefaultCurrency(),
            'default_language' => self::getDefaultLanguage(),
            'allow_insecure_api' => self::allowInsecureApi(),
            'api_max_retries' => self::getMaxRetries(),
            'api_timeout' => self::getTimeout(),
            'circuit_breaker_threshold' => self::getCircuitBreakerThreshold(),
            'circuit_breaker_timeout' => self::getCircuitBreakerTimeout(),
        ];
    }
}
