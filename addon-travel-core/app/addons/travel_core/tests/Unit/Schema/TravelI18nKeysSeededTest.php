<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Every label travel_i18n.tpl hands the storefront scripts must be seeded
 * (lang_keys.php of travel_core or novoton_holidays, whose historical ids it
 * uses). A missing key renders raw ("_novoton_holidays.invalid_year"): CS-Cart
 * returns the key itself, so the template's |default never applies.
 */
final class TravelI18nKeysSeededTest extends TestCase
{
    public function testEveryLabelTheScriptsGetIsSeeded(): void
    {
        $repo = dirname(__DIR__, 7);
        $seeded = [];
        foreach ([
            $repo . '/addon-travel-core/app/addons/travel_core/lang_keys.php',
            $repo . '/addon-novoton-holidays/app/addons/novoton_holidays/lang_keys.php',
        ] as $file) {
            $keys = require $file;
            $seeded += array_fill_keys(array_keys(is_array($keys) ? $keys : []), true);
        }
        $po = (string) file_get_contents($repo . '/addon-novoton-holidays/var/langs/en/addons/novoton_holidays.po')
            . (string) file_get_contents($repo . '/addon-travel-core/var/langs/en/addons/travel_core.po');

        $missing = [];
        foreach (['responsive', 'nova_theme'] as $theme) {
            $tpl = (string) file_get_contents(
                $repo . "/addon-travel-core/design/themes/{$theme}/templates/addons/travel_core/components/travel_i18n.tpl",
            );
            preg_match_all('/__\([\'"]((?:novoton_holidays|travel_core)\.[a-z0-9_]+)[\'"]/', $tpl, $m);
            foreach (array_unique($m[1]) as $key) {
                // Seeded, or in the .po corpus every store got at install.
                if (!isset($seeded[$key]) && !str_contains($po, 'Languages::' . $key . '"')) {
                    $missing[] = "{$theme}: {$key}";
                }
            }
        }

        self::assertSame([], $missing, "labels that would render raw:\n" . implode("\n", $missing));
    }
}
