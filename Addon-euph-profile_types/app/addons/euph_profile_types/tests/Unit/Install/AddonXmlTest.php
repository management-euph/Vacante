<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphProfileTypes\Tests\Unit\Install;

use PHPUnit\Framework\TestCase;

/**
 * What the add-on promises in addon.xml: its id, its supplier, the editions
 * and versions it runs on, no licence key and no add-on manager.
 */
final class AddonXmlTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function xml(): \SimpleXMLElement
    {
        $xml = simplexml_load_file(self::root() . '/addon.xml');
        self::assertNotFalse($xml);

        return $xml;
    }

    public function testIdentityAndSupplier(): void
    {
        $xml = self::xml();

        self::assertSame('euph_profile_types', (string) $xml->id);
        self::assertSame('3.0', (string) $xml['scheme']);
        self::assertSame('VacanteLitoral.ro', (string) $xml->authors->author->name);
        self::assertSame('office@VacanteLitoral.ro', (string) $xml->authors->author->email);
        self::assertSame('https://vacantelitoral.ro', (string) $xml->authors->author->url);
        self::assertSame('VacanteLitoral.ro', (string) $xml->supplier);
        self::assertSame('https://vacantelitoral.ro', (string) $xml->supplier_link);
    }

    public function testItRunsOnEveryEditionOfCsCart4212AndPhp83(): void
    {
        $xml = self::xml();

        self::assertSame('4.21.2', (string) $xml->compatibility->core_version->min);
        self::assertSame('', (string) $xml->compatibility->core_version->max, 'newer cores are not refused');
        self::assertSame('', (string) $xml->compatibility->core_edition, 'CS-Cart, Ultimate, Multi-Vendor and Multi-Vendor Plus/Ultimate');
        self::assertSame('8.3.0', (string) $xml->compatibility->php_version->min);
    }

    public function testNoLicenceKeyAndNoAddonManager(): void
    {
        $source = (string) file_get_contents(self::root() . '/addon.xml');

        self::assertStringNotContainsString('licensekey', $source);
        self::assertStringNotContainsString('cp_addons_manager', $source);
        self::assertSame('', (string) self::xml()->compatibility->dependencies);
    }

    public function testEverySettingIsReadBySettings(): void
    {
        $settingsClass = (string) file_get_contents(self::root() . '/src/Settings.php');
        foreach (self::xml()->settings->sections->section->items->item as $item) {
            if ((string) $item->type === 'header') {
                continue;
            }
            self::assertStringContainsString("'" . $item['id'] . "'", $settingsClass, (string) $item['id'] . ' is not read');
        }
    }

    public function testTablesAreTheAddonsOwnAndFunctionsExist(): void
    {
        $xml = self::xml();
        foreach ($xml->queries->item as $query) {
            if (preg_match('/(?:CREATE TABLE IF NOT EXISTS|DROP TABLE IF EXISTS) `\?:([a-z_]+)`/', (string) $query, $match) === 1) {
                self::assertStringStartsWith('euph_profile_type', $match[1]);
            }
        }
        $install = (string) file_get_contents(self::root() . '/functions/install.php');
        foreach ($xml->functions->item as $function) {
            self::assertStringContainsString('function ' . $function . '(', $install);
        }
    }

    public function testNothingOfTheOldAddonRemainsOutsideTheImporter(): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(self::root(), 3), \FilesystemIterator::SKIP_DOTS));
        $allowed = ['LegacyImporter.php', 'LegacyImporterTest.php', 'AddonXmlTest.php', 'README.md'];
        foreach ($iterator as $file) {
            $path = (string) $file;
            if (preg_match('~/(vendor|\.phpunit\.cache|docs)/~', $path) === 1 || in_array(basename($path), $allowed, true) || !preg_match('/\.(php|tpl|js|xml|po|less)$/', $path)) {
                continue;
            }
            $source = (string) file_get_contents($path);
            self::assertDoesNotMatchRegularExpression('/\bcp_[a-z_]+|fn_cp_|Cart-Power/', $source, $path . ' still names the old add-on');
        }
    }
}
