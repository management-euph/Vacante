<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Services;

use Tygh\Settings;

/**
 * Saves the dashboard's excluded resorts into the add-on setting.
 *
 * The dashboard used to write it with a raw
 * `UPDATE ?:addon_options SET value = … WHERE addon = … AND option_id = …`.
 * CS-Cart 4.x has no ?:addon_options table — add-on settings live in
 * ?:settings_objects behind Tygh\Settings — so every Save stopped with
 * "Table 'cscart_addon_options' doesn't exist (1146)".
 *
 * Written the way Travel Core's CronKeyService writes the cron key:
 *   - the setting row may be missing on a store installed before addon.xml
 *     declared it (CS-Cart imports <settings> only at install/upgrade), and
 *     updateValue() on a missing row is a silent no-op, so it is created
 *     first through Travel Core's settings heal;
 *   - the write is read back with Settings::getValue — not the Registry,
 *     which still holds this request's bootstrap snapshot.
 */
final class ExcludedResortsStore
{
    public const string SETTING = 'excluded_resorts';

    /**
     * The value stored: a JSON array of names, trimmed, blanks and repeats
     * dropped — the shape ConfigProvider::parseResortList() reads back.
     *
     * @param array<mixed> $submitted the posted excluded_resorts[] values
     */
    public static function encode(array $submitted): string
    {
        $names = [];
        foreach ($submitted as $name) {
            if (!is_scalar($name)) {
                continue;
            }
            $name = trim((string) $name);
            if ($name !== '' && !in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        return (string) json_encode($names, JSON_UNESCAPED_UNICODE);
    }

    /** TRUE when the value is in the setting afterwards. */
    public static function save(string $value): bool
    {
        $settings = Settings::instance();
        if (!$settings instanceof Settings) {
            return false;
        }

        self::ensureSettingExists();

        try {
            $settings->updateValue(self::SETTING, $value, ConfigProvider::ADDON_ID);

            return $settings->getValue(self::SETTING, ConfigProvider::ADDON_ID) === $value;
        } catch (\Throwable $e) {
            error_log('novoton_holidays: could not save the excluded resorts — ' . $e->getMessage());

            return false;
        }
    }

    private static function ensureSettingExists(): void
    {
        $settings = Settings::instance();
        if (!$settings instanceof Settings) {
            return;
        }
        if (!method_exists($settings, 'isExists') || $settings->isExists(self::SETTING, ConfigProvider::ADDON_ID)) {
            return;
        }
        if (!function_exists('fn_travel_core_ensure_settings')) {
            return;
        }

        $addonDir = dirname(__DIR__, 2);
        try {
            fn_travel_core_ensure_settings(ConfigProvider::ADDON_ID, $addonDir, dirname($addonDir, 3) . '/var/langs');
        } catch (\Throwable $e) {
            error_log('novoton_holidays: could not create the excluded_resorts setting — ' . $e->getMessage());
        }
    }
}
