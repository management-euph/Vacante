<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Install;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Install\LanguageSeeder;
use Tygh\Addons\TravelCore\Support\PoFile;

/**
 * Most of Novoton's labels live only in its .po files, which CS-Cart
 * imports once, at install. The runtime seeder read only lang_keys.php and
 * addon.xml, so a label added to a .po never reached an upgraded store. It
 * now reads the .po files too, and a changed .po reseeds the store.
 */
final class LanguageSeederPoTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    public function testBothLanguagesPoFilesAreFound(): void
    {
        self::assertSame(['en', 'ro'], array_keys(LanguageSeeder::poFiles()));
    }

    public function testEveryPoLabelIsDeliveredInEveryLanguage(): void
    {
        $vars = LanguageSeeder::variables();
        foreach (LanguageSeeder::poFiles() as $lang => $path) {
            foreach (PoFile::languages($path) as $name => $value) {
                self::assertArrayHasKey($name, $vars);
                self::assertArrayHasKey($lang, $vars[$name], $name);
            }
        }
    }

    public function testALabelOnlyInThePoReachesTheStore(): void
    {
        $keys = require self::root() . '/lang_keys.php';
        self::assertArrayNotHasKey('novoton_holidays.location_show_map', $keys, 'only in the .po');

        self::assertSame(
            ['en' => 'Location - show map', 'ro' => 'Locație - arată pe hartă'],
            LanguageSeeder::variables()['novoton_holidays.location_show_map'],
            'the RO label an older .po glued to the line before it, now delivered by the seeder',
        );
    }

    public function testAChangedPoReseedsTheStore(): void
    {
        $seeder = (string) file_get_contents(self::root() . '/src/Install/LanguageSeeder.php');

        self::assertStringContainsString("self::addonRoot() . '/lang_keys.php', ...array_values(self::poFiles())", $seeder);
    }

    public function testNoOtherCodeWritesLabels(): void
    {
        $init = (string) file_get_contents(self::root() . '/init.php');
        self::assertStringNotContainsString('INSERT INTO ?:language_values', $init, 'no per-request label repair');

        $install = (string) file_get_contents(self::root() . '/functions/install.php');
        self::assertStringNotContainsString('$lang_vars', $install, 'no labels written inline at install');
    }
}
