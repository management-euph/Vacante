<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Registry;

/**
 * "Keep photos added by hand": on for a new install and for a store that
 * has not saved it yet; only an unticked box turns it off.
 */
final class KeepManualImagesSettingTest extends TestCase
{
    protected function tearDown(): void
    {
        Registry::set('addons.novoton_holidays', []);
        ConfigProvider::reset();
    }

    public function testItIsOnUnlessUnticked(): void
    {
        foreach ([[], ['images_keep_manual' => 'Y'], ['images_keep_manual' => '']] as $settings) {
            Registry::set('addons.novoton_holidays', $settings);
            ConfigProvider::reset();
            self::assertTrue(ConfigProvider::keepManualImages(), json_encode($settings) ?: '');
        }

        Registry::set('addons.novoton_holidays', ['images_keep_manual' => 'N']);
        ConfigProvider::reset();
        self::assertFalse(ConfigProvider::keepManualImages());
    }

    public function testANewInstallShipsItOnWithLabelsInBothLanguages(): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 3) . '/addon.xml');
        self::assertNotFalse($xml);
        $items = $xml->xpath('//settings//item[@id="images_keep_manual"]') ?: [];
        self::assertCount(1, $items);
        self::assertSame(['checkbox', 'Y'], [trim((string) $items[0]->type), trim((string) $items[0]->default_value)]);

        foreach (['en', 'ro'] as $lang) {
            $po = (string) file_get_contents(dirname(__DIR__, 6) . "/var/langs/{$lang}/addons/novoton_holidays.po");
            foreach (['SettingsOptions::novoton_holidays::images_header', 'SettingsOptions::novoton_holidays::images_keep_manual', 'SettingsTooltips::novoton_holidays::images_keep_manual'] as $context) {
                self::assertStringContainsString('msgctxt "' . $context . '"', $po, "{$lang} {$context}");
            }
        }
    }
}
