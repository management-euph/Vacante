<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Install;

use Tygh\Addons\FgoInvoicing\Constants;
use Tygh\Addons\FgoInvoicing\Helpers\TypeCoerce;

/**
 * Keeps the settings self-heal ADD-ONLY.
 *
 * WHY: fn_fgo_invoicing_heal_settings_once() reuses travel_core's
 * SettingsMigrator to create settings an existing store lacks. For settings
 * that already exist, the migrator also "repairs": it writes the addon.xml
 * default into every one holding '' and re-applies the declared type. On an
 * FGO store that would silently put CodArticol SHIPPING / DISCOUNT back on
 * every invoice of a merchant who cleared those codes, and turn a cleared
 * api_max_retries (0 retries of a non-idempotent issue POST) back into 2.
 * The heal exists to ADD rows, so:
 *
 *   - missing() tells whether there is anything to add at all; when nothing
 *     is missing the migrator is never called;
 *   - take() before and restore() after put back the value and type of every
 *     setting that existed before, whatever the migrator did to it.
 *
 * Values only: fgo declares no edition_type, so every setting is ROOT-level
 * and CS-Cart keeps its value in ?:settings_objects.value, never in
 * ?:settings_vendor_values (Settings::getUpdateValueTable()). Labels are not
 * restored: the migrator only fills BLANK ones, and the heal re-mirrors all
 * of them from addon.xml right after.
 *
 * Raw db_* on purpose, like SettingsPlacement: no Settings API.
 */
final class SettingsSnapshot
{
    private function __construct()
    {
    }

    /**
     * Every setting row of this add-on's section: name => its type and value.
     * [] when the section does not exist (add-on not installed) or the
     * database layer is not there.
     *
     * @return array<string, array{type: string, value: string|null}>
     */
    public static function take(): array
    {
        if (!function_exists('db_get_array')) {
            return [];
        }

        $rows = db_get_array(
            'SELECT name, type, value FROM ?:settings_objects
             WHERE section_id IN (SELECT section_id FROM ?:settings_sections WHERE name = ?s)',
            Constants::ADDON_ID,
        );

        $snapshot = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = TypeCoerce::toString($row['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $value = $row['value'] ?? null;
            $snapshot[$name] = [
                'type' => TypeCoerce::toString($row['type'] ?? ''),
                'value' => $value === null ? null : TypeCoerce::toString($value),
            ];
        }

        return $snapshot;
    }

    /**
     * Setting ids addon.xml declares (headers included: they are rows too),
     * in document order. [] when the file is missing or unreadable.
     *
     * @return list<string>
     */
    public static function declaredNames(string $addonXmlPath): array
    {
        if (!is_file($addonXmlPath)) {
            return [];
        }
        $xml = @simplexml_load_file($addonXmlPath);
        if ($xml === false) {
            return [];
        }

        $names = [];
        foreach ($xml->xpath('/addon/settings/sections/section/items/item[@id]') ?: [] as $item) {
            $name = trim((string) $item['id']);
            if ($name !== '' && !in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Declared settings the store has no row for.
     *
     * @param list<string> $declared
     * @param array<string, array{type: string, value: string|null}> $snapshot
     *
     * @return list<string>
     */
    public static function missing(array $declared, array $snapshot): array
    {
        return array_values(array_filter(
            $declared,
            static fn (string $name): bool => !isset($snapshot[$name]),
        ));
    }

    /**
     * Put back the type and value of every setting present in $before that
     * $after shows changed. Rows only in $after (the ones just created) are
     * left alone.
     *
     * @param array<string, array{type: string, value: string|null}> $before
     * @param array<string, array{type: string, value: string|null}> $after
     *
     * @return list<string> names restored
     */
    public static function restore(array $before, array $after): array
    {
        if (!function_exists('db_query')) {
            return [];
        }

        $restored = [];
        foreach ($before as $name => $old) {
            $now = $after[$name] ?? null;
            if ($now === null || $now === $old) {
                continue;
            }

            $scope = 'WHERE name = ?s AND section_id IN (SELECT section_id FROM ?:settings_sections WHERE name = ?s)';
            if ($old['value'] === null) {
                db_query('UPDATE ?:settings_objects SET value = NULL, type = ?s ' . $scope, $old['type'], $name, Constants::ADDON_ID);
            } else {
                db_query('UPDATE ?:settings_objects SET value = ?s, type = ?s ' . $scope, $old['value'], $old['type'], $name, Constants::ADDON_ID);
            }
            $restored[] = $name;
        }

        return $restored;
    }
}
