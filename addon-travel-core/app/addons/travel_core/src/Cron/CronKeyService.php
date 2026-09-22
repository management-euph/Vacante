<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Cron;

use Tygh\Addons\TravelCore\Helpers\RegistryCoerce;
use Tygh\Settings;

/**
 * The one secret that authenticates every travel add-on's cron endpoints.
 *
 * WHY IT LIVES IN CORE. `api_password` authenticates us TO a provider: it is
 * issued by Eurosite / Novoton / Sphinx, rotated on their schedule, and
 * meaningless to the others, so it belongs in that provider's add-on.
 * `cron_access_key` was never that. It authenticates OUR OWN endpoint — we
 * issue it, we rotate it, it goes in one crontab — and it ended up per-add-on
 * by sitting next to the provider credentials rather than by principle.
 *
 * WHAT THAT COST. Four copies meant four chances to leave one unset, and the
 * live store took all four: three still held the shipped `1234` and eurosite's
 * row did not exist at all, because the settings self-heal had never listed
 * that add-on. Nothing showed the state of all four in one place.
 *
 * WHAT THIS DOES NOT DO. It does not unify the cron ENDPOINTS. Each provider
 * keeps its own route and its own crontab interval — mode names collide
 * across add-ons (`full`, `cleanup`, `hotels`, `reassign_features` are each
 * registered more than once) and their schedules genuinely differ. Only the
 * secret is shared.
 *
 * Reads go through the Registry like every other setting, so they see the
 * bootstrap-time snapshot. Writes go through Tygh\Settings and are verified by
 * reading back through Settings, NOT through the Registry — the Registry
 * cannot see a write made in the same request. That trap cost a silent no-op
 * once already; see generate().
 */
final class CronKeyService
{
    public const SETTING = 'cron_key';

    public const ADDON = 'travel_core';

    /**
     * The configured key, or '' when the store has none.
     *
     * '' is a legitimate, fail-closed state: every cron entry point refuses an
     * empty key, so an unconfigured store rejects everything rather than
     * accepting a guess.
     */
    public static function get(): string
    {
        return RegistryCoerce::string('addons.' . self::ADDON . '.' . self::SETTING);
    }

    public static function isConfigured(): bool
    {
        return self::get() !== '';
    }

    /**
     * The key an add-on should authenticate against: Core's, else its own old one.
     *
     * TRANSITIONAL, and deliberately so. The settings self-heal that populates
     * the Core key runs on dispatch_before_display and only in the admin area,
     * so a store that deploys this code and then receives nothing but cron hits
     * and storefront traffic never gets it. Switching the readers over without
     * a fallback would take every scheduled job on such a store down at the
     * next tick, with the operator's existing crontab suddenly wrong and
     * nothing but a 403 to say why.
     *
     * So the old per-add-on `cron_access_key` keeps working until the Core key
     * is set. Only one key is ever accepted — Core's when present, the legacy
     * one otherwise — never both at once.
     *
     * Remove this, and the legacy rows, once the Core key is set everywhere.
     * The rotation UI is what makes that a single deliberate action.
     */
    public static function getFor(string $addon): string
    {
        $key = self::get();
        if ($key !== '') {
            return $key;
        }

        return RegistryCoerce::string('addons.' . $addon . '.cron_access_key');
    }

    /**
     * Mint a new key, store it, and return it — or '' if it did not land.
     *
     * Returns the value rather than a bool because the caller has to show it:
     * a rotation the operator cannot read is a crontab they cannot fix.
     *
     * Three failure modes are handled here because each has already happened
     * in this codebase:
     *
     *  - the settings ROW may not exist. CS-Cart imports <settings> only at
     *    install/upgrade, so a setting added by a code deploy is absent until
     *    the self-heal creates it — and updateValue() on a missing setting is
     *    a silent no-op that reports success.
     *  - the write may not land for other reasons, so it is read back.
     *  - the read-back must use Settings::getValue and NOT the Registry (or
     *    any ConfigProvider over it), because Registry holds the snapshot
     *    CS-Cart loaded at bootstrap and will still report the OLD value in
     *    this request — making a successful write look like a failure.
     */
    public static function generate(): string
    {
        $settings = Settings::instance();
        if (!$settings instanceof Settings) {
            return '';
        }

        self::ensureSettingExists($settings);

        $fresh = bin2hex(random_bytes(16));

        try {
            $settings->updateValue(self::SETTING, $fresh, self::ADDON);

            if ($settings->getValue(self::SETTING, self::ADDON) !== $fresh) {
                return '';
            }
        } catch (\Throwable $e) {
            error_log('travel_core: could not generate the cron key — ' . $e->getMessage());

            return '';
        }

        return $fresh;
    }

    /**
     * Create the settings row when a code deploy has outrun the self-heal.
     *
     * The heal runs on dispatch_before_display and only in the admin area, so
     * a store that deploys and then receives nothing but cron traffic never
     * gets it. Asking for the key from a page that can create it is the one
     * moment we can close that window.
     */
    private static function ensureSettingExists(Settings $settings): void
    {
        if (!method_exists($settings, 'isExists') || $settings->isExists(self::SETTING, self::ADDON)) {
            return;
        }

        if (!function_exists('fn_travel_core_ensure_settings')) {
            return;
        }

        $addonDir = dirname(__DIR__, 2);

        try {
            fn_travel_core_ensure_settings(
                self::ADDON,
                $addonDir,
                dirname($addonDir, 3) . '/var/langs',
            );
        } catch (\Throwable $e) {
            error_log('travel_core: could not create the cron_key setting — ' . $e->getMessage());
        }
    }
}
