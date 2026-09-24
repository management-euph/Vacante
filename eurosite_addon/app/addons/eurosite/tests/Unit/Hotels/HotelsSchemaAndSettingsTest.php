<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Tests\Unit\Hotels;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\Eurosite\Install\SchemaMigrator;

/**
 * The hotel columns and the settings reach both kinds of store: a fresh
 * install (addon.xml) and one installed before them (SchemaMigrator, and
 * Travel Core's settings heal, which reads the .po labels).
 */
final class HotelsSchemaAndSettingsTest extends TestCase
{
    private const ADDON = __DIR__ . '/../../..';

    private static function hotelsCreate(): string
    {
        $xml = (string) file_get_contents(self::ADDON . '/addon.xml');
        $start = (int) strpos($xml, 'CREATE TABLE IF NOT EXISTS `?:eurosite_hotels`');

        return substr($xml, $start, (int) strpos($xml, 'ENGINE=InnoDB', $start) - $start);
    }

    public function testEveryHealedColumnIsInTheInstallTable(): void
    {
        $create = self::hotelsCreate();
        foreach (array_keys(SchemaMigrator::HOTEL_COLUMNS) as $column) {
            self::assertStringContainsString('`' . $column . '`', $create, "{$column} is healed but a fresh install would not have it");
        }
        foreach (array_keys(SchemaMigrator::HOTEL_INDEXES) as $index) {
            self::assertStringContainsString('KEY `' . $index . '`', $create);
        }
    }

    public function testEveryColumnAddedAfterTheFirstReleaseIsHealed(): void
    {
        preg_match_all('/^\s+`([a-z_]+)`\s+[A-Z]/m', self::hotelsCreate(), $m);
        $original = ['tourop_code', 'product_code', 'name', 'city_code', 'country_code', 'rooms_json', 'category', 'product_id', 'sync_status', 'last_synced_at'];
        foreach (array_diff($m[1], $original) as $column) {
            self::assertArrayHasKey($column, SchemaMigrator::HOTEL_COLUMNS, "{$column}: stores installed before it would never get it");
        }
    }

    public function testTheHealedColumnsComeAfterColumnsThatExist(): void
    {
        $known = ['tourop_code', 'product_code', 'name', 'city_code', 'country_code', 'rooms_json', 'category', 'product_id', 'sync_status', 'last_synced_at'];
        foreach (SchemaMigrator::HOTEL_COLUMNS as $column => $ddl) {
            self::assertMatchesRegularExpression('/AFTER `([a-z_]+)`$/', $ddl);
            preg_match('/AFTER `([a-z_]+)`$/', $ddl, $after);
            self::assertContains($after[1], $known, "{$column} is added after a column that may not exist yet");
            $known[] = $column;
        }
    }

    public function testEverySettingHasALabelInBothLanguages(): void
    {
        $xml = simplexml_load_file(self::ADDON . '/addon.xml');
        self::assertNotFalse($xml);
        $ids = [];
        foreach ($xml->xpath('//settings//item[type]') ?: [] as $item) {
            $ids[] = (string) $item['id'];
        }
        foreach (['products_header', 'hotels_category_id', 'products_without_images', 'availability_near_days', 'availability_season_dates', 'availability_nights', 'hide_unavailable_products'] as $new) {
            self::assertContains($new, $ids);
        }
        foreach (['en', 'ro'] as $lang) {
            $po = (string) file_get_contents(self::ADDON . "/../../../var/langs/{$lang}/addons/eurosite.po");
            foreach ($ids as $id) {
                self::assertStringContainsString('msgctxt "SettingsOptions::eurosite::' . $id . '"', $po, "{$lang}: {$id} has no label");
            }
        }
    }

    /**
     * Travel Core's settings heal creates stored selectbox variants without
     * their labels, so on an existing store such options would show blank:
     * the numeric settings are plain inputs, and the category dropdown gets
     * its options from a variants function instead of stored variants.
     */
    public function testTheNewSettingsNeedNoVariantLabels(): void
    {
        $xml = simplexml_load_file(self::ADDON . '/addon.xml');
        self::assertNotFalse($xml);
        $types = [];
        foreach ($xml->xpath('//settings//item[type]') ?: [] as $item) {
            $types[(string) $item['id']] = $item;
        }
        foreach (['availability_near_days', 'availability_nights', 'availability_season_dates'] as $id) {
            self::assertSame('input', (string) $types[$id]->type, $id);
        }

        // Like Sphinx's "CS-Cart category ID for Sphinx hotels": every category, by path.
        self::assertSame('selectbox', (string) $types['hotels_category_id']->type);
        self::assertCount(0, $types['hotels_category_id']->variants->children(), 'options come from the function, not addon.xml');
        self::assertStringContainsString(
            'function fn_settings_variants_addons_eurosite_hotels_category_id(): array',
            (string) file_get_contents(self::ADDON . '/func.php'),
        );
        foreach (['en', 'ro'] as $lang) {
            $po = (string) file_get_contents(self::ADDON . "/../../../var/langs/{$lang}/addons/eurosite.po");
            self::assertStringContainsString("msgctxt \"SettingsOptions::eurosite::hotels_category_id\"\nmsgid \"CS-Cart category ID for Eurosite hotels\"", $po);
        }
    }
}
