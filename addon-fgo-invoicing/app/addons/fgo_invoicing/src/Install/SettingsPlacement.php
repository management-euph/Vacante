<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Install;

use Tygh\Addons\FgoInvoicing\Constants;
use Tygh\Addons\FgoInvoicing\Helpers\TypeCoerce;

/**
 * Puts settings that a self-heal created on an existing store back under the
 * header addon.xml declares them in.
 *
 * WHY: CS-Cart creates an add-on's settings only at install. On older stores
 * the settings heal (fn_fgo_invoicing_heal_settings_once, via travel_core's
 * SettingsMigrator) creates the missing rows, but always at the END of the
 * form — so "CIF profile field" would render under "Resilience &
 * Diagnostics". The settings page orders rows by ?:settings_objects.position,
 * which this class adjusts; nothing else about the setting is touched.
 *
 * A fresh install already has the addon.xml order (CS-Cart spaces positions
 * by 10), and then nothing moves: rows are moved only while at least one of
 * them sits outside the window between its anchor and the next header.
 *
 * Raw db_* on purpose, like LanguageSeeder: no Settings API, so it is safe at
 * any point of the request.
 */
final class SettingsPlacement
{
    /**
     * Settings added after the first release, grouped by the window they
     * belong in: [setting they follow, the header after them, [names in order]].
     *
     * @var list<array{0: string, 1: string, 2: list<string>}>
     */
    public const LATE_SETTINGS = [
        ['sandbox', 'behaviour_header', ['platform_url']],
        ['client_cnp_required', 'lines_header', ['cif_field', 'reg_com_field', 'cnp_field']],
    ];

    private function __construct()
    {
    }

    /**
     * @return int how many settings were moved
     */
    public static function apply(): int
    {
        $moved = 0;
        foreach (self::LATE_SETTINGS as [$after, $before, $names]) {
            $moved += self::placeBetween($after, $before, $names);
        }

        return $moved;
    }

    /**
     * Space $names evenly, in order, between the settings $after and $before.
     *
     * No-op when $after or $before is missing, when there is no room between
     * them, or when every present name already sits between them.
     *
     * @param list<string> $names
     *
     * @return int how many settings were moved
     */
    public static function placeBetween(string $after, string $before, array $names): int
    {
        if (!function_exists('db_get_array') || !function_exists('db_query') || $names === []) {
            return 0;
        }

        $rows = db_get_array(
            'SELECT name, position FROM ?:settings_objects
             WHERE section_id IN (SELECT section_id FROM ?:settings_sections WHERE name = ?s)
               AND name IN (?a)',
            Constants::ADDON_ID,
            array_merge([$after, $before], $names),
        );

        $positions = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row)) {
                $positions[TypeCoerce::toString($row['name'] ?? '')] = TypeCoerce::toInt($row['position'] ?? 0);
            }
        }

        if (!isset($positions[$after], $positions[$before])) {
            return 0;
        }
        $low = $positions[$after];
        $high = $positions[$before];

        $present = array_values(array_filter($names, static fn (string $name): bool => isset($positions[$name])));
        $step = intdiv($high - $low, count($present) + 1);
        if ($present === [] || $step < 1) {
            return 0;
        }

        $outside = array_filter(
            $present,
            static fn (string $name): bool => $positions[$name] <= $low || $positions[$name] >= $high,
        );
        if ($outside === []) {
            return 0;
        }

        $moved = 0;
        foreach ($present as $i => $name) {
            $target = $low + $step * ($i + 1);
            if ($positions[$name] === $target) {
                continue;
            }
            db_query(
                'UPDATE ?:settings_objects SET position = ?i
                 WHERE name = ?s
                   AND section_id IN (SELECT section_id FROM ?:settings_sections WHERE name = ?s)',
                $target,
                $name,
                Constants::ADDON_ID,
            );
            $moved++;
        }

        return $moved;
    }
}
