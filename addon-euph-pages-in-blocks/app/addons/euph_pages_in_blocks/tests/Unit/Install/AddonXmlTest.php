<?php

declare(strict_types=1);

namespace Tygh\Addons\EuphPagesInBlocks\Tests\Unit\Install;

use PHPUnit\Framework\TestCase;

/**
 * What the add-on promises in addon.xml: its id, its author and supplier,
 * the editions and versions it runs on, and no licence key.
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

    public function testIdentityAuthorAndSupplier(): void
    {
        $xml = self::xml();

        self::assertSame('euph_pages_in_blocks', (string) $xml->id);
        self::assertSame('3.0', (string) $xml['scheme']);
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', (string) $xml->version);
        self::assertSame('en', (string) $xml->default_language);
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
        self::assertSame('', (string) $xml->compatibility->dependencies, 'no other add-on is needed');
    }

    public function testNoLicenceKeyNorMarketplaceLicence(): void
    {
        $source = (string) file_get_contents(self::root() . '/addon.xml');

        foreach (['licensekey', 'license_key', 'marketplace_license_number', 'marketplace_product_id', 'auto_install'] as $forbidden) {
            self::assertStringNotContainsStringIgnoringCase($forbidden, $source);
        }
    }

    public function testTheIconIsTheAddonsOwn(): void
    {
        self::assertSame('Y', (string) self::xml()->has_icon);
        $icon = dirname(self::root(), 3) . '/design/backend/media/images/addons/euph_pages_in_blocks/icon.png';
        self::assertFileExists($icon);
        $size = getimagesize($icon);
        self::assertIsArray($size);
        self::assertSame([48, 48], [$size[0], $size[1]]);
    }

    public function testTheInfoSettingDrawsAShippedTemplate(): void
    {
        $items = self::xml()->settings->sections->section->items->item;
        self::assertCount(1, $items);
        self::assertSame('info', (string) $items[0]['id']);
        self::assertSame('template', (string) $items[0]->type);
        self::assertFileExists(dirname(self::root(), 3) . '/design/backend/templates/addons/euph_pages_in_blocks/settings/' . $items[0]->default_value);
    }

    public function testNoTablesAndTheUninstallFunctionExists(): void
    {
        $xml = self::xml();

        self::assertSame(0, $xml->queries->count(), 'the add-on keeps no data of its own');
        $functions = [];
        foreach ($xml->functions->item as $function) {
            $functions[(string) $function['for']] = (string) $function;
        }
        self::assertSame(['uninstall' => 'fn_euph_pages_in_blocks_uninstall'], $functions);
        self::assertTrue(function_exists('fn_euph_pages_in_blocks_uninstall'));
    }
}
