<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\Eurosite\Services\ConfigProvider;

/**
 * Packages: the "Packages: nights offered" setting and the package routes'
 * table, on a new install and on a store that already has the add-on.
 */
final class PackageSettingsAndSchemaTest extends TestCase
{
    private const string ADDON = __DIR__ . '/../../..';

    public function testTheNightsAreWholeNumbersInOrderOnceEach(): void
    {
        self::assertSame([7, 10, 14], ConfigProvider::parseNights('7, 10, 14'));
        self::assertSame([7, 10, 14], ConfigProvider::parseNights(' 14,7 ; 10 10 '));
        self::assertSame([5, 30], ConfigProvider::parseNights('0, 31, x, 5, 30, 7.5, -3'));
    }

    public function testNothingUsableOffersSevenTenAndFourteen(): void
    {
        self::assertSame([7, 10, 14], ConfigProvider::parseNights(''));
        self::assertSame([7, 10, 14], ConfigProvider::parseNights('one week'));
        self::assertSame([7, 10, 14], ConfigProvider::parseNights('0, 45'));
    }

    /** A plain input, as the availability check's (the settings heal leaves stored variants unlabelled). */
    public function testTheSettingIsAPlainInputUnderItsOwnHeader(): void
    {
        $xml = simplexml_load_file(self::ADDON . '/addon.xml');
        self::assertNotFalse($xml);
        $items = [];
        foreach ($xml->xpath('//settings//item[type]') ?: [] as $item) {
            $items[(string) $item['id']] = $item;
        }
        $ids = array_keys($items);

        self::assertSame('header', (string) $items['packages_header']->type);
        self::assertSame('input', (string) $items['package_nights']->type);
        self::assertSame('7, 10, 14', (string) $items['package_nights']->default_value);
        self::assertSame(
            array_search('packages_header', $ids, true) + 1,
            array_search('package_nights', $ids, true),
            'the nights come right under the Packages header',
        );
        foreach (['en', 'ro'] as $lang) {
            $po = (string) file_get_contents(self::ADDON . "/../../../var/langs/{$lang}/addons/eurosite.po");
            self::assertStringContainsString('msgctxt "SettingsTooltips::eurosite::package_nights"', $po, $lang);
        }
    }

    /**
     * A store that has the add-on gets the table from SchemaMigrator, which
     * runs every install item that starts with CREATE TABLE IF NOT EXISTS.
     */
    public function testTheRoutesTableIsCreatedHealedAndDropped(): void
    {
        $xml = simplexml_load_file(self::ADDON . '/addon.xml');
        self::assertNotFalse($xml);
        $create = '';
        $drops = [];
        foreach ($xml->queries->item ?? [] as $item) {
            $sql = trim((string) $item);
            if ((string) $item['for'] === 'install' && str_contains($sql, '`?:eurosite_package_routes`')) {
                $create = $sql;
            }
            if ((string) $item['for'] === 'uninstall') {
                $drops[] = $sql;
            }
        }

        self::assertStringStartsWith('CREATE TABLE IF NOT EXISTS `?:eurosite_package_routes`', $create);
        self::assertStringContainsString('PRIMARY KEY (`transport`, `city_code`, `dep_city_code`)', $create);
        foreach (['dates_json', 'first_date', 'last_date', 'dates_count', 'zone_code', 'dep_city_name', 'last_synced_at'] as $column) {
            self::assertStringContainsString('`' . $column . '`', $create, $column);
        }
        self::assertContains('DROP TABLE IF EXISTS `?:eurosite_package_routes`', $drops);
    }
}
