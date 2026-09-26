<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Services;

use Tygh\Addons\TravelCore\Helpers\TypeCoerce;
use Tygh\Addons\TravelCore\Services\Geocoding\NominatimClient;
use Tygh\Registry;

/**
 * Designated settings-reader boundary for travel_core's own config +
 * the CS-Cart globals it legitimately needs (currencies, date format).
 *
 * Plays the same role the addon-level `ConfigProvider` classes play for
 * novoton_holidays and sphinx_holidays — the single place inside
 * travel_core where `\Tygh\Registry::get(...)` is legal. Allowlisted in
 * `phpstan-disallowed-calls.neon` at the repo root.
 *
 * All methods are typed + narrow. New travel_core services should read
 * settings through this class instead of reaching into Registry directly.
 */
final class TravelCoreConfig
{
    /**
     * CS-Cart's admin-configured display date format (e.g. `'%d %b %Y'`).
     * Falls back to a numeric European default when the admin setting is
     * missing.
     */
    public static function getDateFormat(): string
    {
        $fmt = Registry::get('settings.Appearance.date_format');
        return is_string($fmt) && $fmt !== '' ? $fmt : '%d.%m.%Y';
    }

    /**
     * CS-Cart currency catalogue (code => per-currency metadata). The
     * inner shape is left as `array<string, mixed>`; individual callers
     * coerce `coefficient`/`symbol` per-field with explicit guards.
     *
     * @return array<string, mixed>
     */
    public static function getCurrencies(): array
    {
        $currencies = Registry::get('currencies');
        if (!is_array($currencies)) {
            return [];
        }
        $out = [];
        foreach ($currencies as $k => $v) {
            if (is_string($k)) {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    /**
     * Read an arbitrary `addons.travel_core.{key}` setting. Returns the
     * raw mixed value; callers are responsible for coercing to the
     * expected type (use TypeCoerce / ValidationHelpers).
     */
    public static function getSetting(string $key): mixed
    {
        return Registry::get('addons.travel_core.' . $key);
    }

    /**
     * Convenience accessor for the `addons.travel_core.feature_id_*`
     * product-feature IDs shared across provider addons
     * (region / city / meals). Returns 0 when the setting is missing
     * or non-numeric so callers can treat "no mapping" uniformly.
     */
    public static function getFeatureId(string $key): int
    {
        $value = Registry::get('addons.travel_core.feature_id_' . $key);
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Whether the booking form is injected on hotel product pages — the ONE
     * switch for all provider addons (novoton + sphinx). Defaults to true
     * when the setting is missing so the form never silently disappears.
     * Its sibling `booking_form_position` is read directly in the hook
     * templates via `$addons.travel_core.booking_form_position`.
     */
    public static function isShowBookingForm(): bool
    {
        return Registry::get('addons.travel_core.show_booking_form') !== 'N';
    }

    /** Settings -> Travel Core -> "Round prices": whole currency units only. */
    public static function isRoundPrices(): bool
    {
        return Registry::get('addons.travel_core.round_prices') === 'Y';
    }

    /**
     * How many facility chips the booking sidebar shows before "+N more".
     * Falls back to 6 (the old hard cap) when unset or not a positive number.
     */
    public static function getBookingSidebarMaxFeatures(): int
    {
        $value = Registry::get('addons.travel_core.booking_sidebar_max_features');

        return is_numeric($value) && (int) $value > 0 ? (int) $value : 6;
    }

    // ── Reverse geocoding (OSM Nominatim) ──
    //
    // One switch, one contact, one endpoint for every provider addon: the
    // Nominatim usage policy limits an APPLICATION to 1 request/second, so
    // per-addon copies of these settings would let two crons double the real
    // rate against the public instance.

    // Read through fn_travel_core_get_geocoding_settings(), whose source of
    // truth is Settings -> Travel Core -> Geocoding. Those rows only exist on
    // stores installed AFTER the section was declared (CS-Cart never re-reads
    // addon.xml on a deploy or cache clear), so SettingsMigrator creates them
    // at runtime; ?:storage_data answers only as a legacy fallback for the
    // window when the values were captured on the Tools page.

    public static function isGeocodingEnabled(): bool
    {
        return (bool) self::geocoding()['enabled'];
    }

    /**
     * Contact email carried in the Nominatim User-Agent — the public
     * instance's usage policy requires identifying the application.
     */
    public static function getGeocodingContactEmail(): string
    {
        return TypeCoerce::toString(self::geocoding()['contact_email']);
    }

    public static function getGeocodingEndpoint(): string
    {
        $value = TypeCoerce::toString(self::geocoding()['endpoint']);

        return $value !== '' ? $value : NominatimClient::DEFAULT_ENDPOINT;
    }

    /**
     * @return array<string, mixed>
     */
    private static function geocoding(): array
    {
        if (!function_exists('fn_travel_core_get_geocoding_settings')) {
            // Functions file not loaded (unit tests): fall back to Registry.
            $endpoint = trim(TypeCoerce::toString(Registry::get('addons.travel_core.geocoding_endpoint')));

            return [
                'enabled' => Registry::get('addons.travel_core.geocoding_enabled') === 'Y',
                'contact_email' => trim(TypeCoerce::toString(Registry::get('addons.travel_core.geocoding_contact_email'))),
                'endpoint' => $endpoint !== '' ? $endpoint : NominatimClient::DEFAULT_ENDPOINT,
            ];
        }

        return fn_travel_core_get_geocoding_settings();
    }
}
