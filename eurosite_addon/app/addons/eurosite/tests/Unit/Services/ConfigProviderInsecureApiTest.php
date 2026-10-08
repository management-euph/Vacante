<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\Eurosite\Services\ConfigProvider;

/**
 * allow_insecure_api (audit H13) is off unless the store turned it on: a
 * fresh install (addon.xml) and a store whose settings row lacks the key
 * (ConfigProvider) both refuse a plain http:// API URL.
 *
 * ConfigProvider reads Tygh\Registry, which the eurosite bootstrap does not
 * stub, so the Registry-backed cases run in their own process.
 */
final class ConfigProviderInsecureApiTest extends TestCase
{
    /**
     * @param array<string, mixed> $settings
     */
    private static function stubRegistry(array $settings): void
    {
        $GLOBALS['eurosite_test_settings'] = $settings;
        if (!class_exists(\Tygh\Registry::class, false)) {
            eval('namespace Tygh; class Registry {
                public static function get($k) { return $k === "addons.eurosite" ? $GLOBALS["eurosite_test_settings"] : null; }
            }');
        }
        ConfigProvider::resetSettingsCache();
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTheFlagDefaultsToOffWhenUnset(): void
    {
        self::stubRegistry(['api_url' => 'https://api.example.test/server.php']);

        self::assertFalse(ConfigProvider::allowInsecureApi());
        self::assertFalse(ConfigProvider::toClientSettings()['allow_insecure_api']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testOnlyYTurnsTheFlagOn(): void
    {
        self::stubRegistry(['allow_insecure_api' => 'N']);
        self::assertFalse(ConfigProvider::allowInsecureApi());

        self::stubRegistry(['allow_insecure_api' => 'Y']);
        self::assertTrue(ConfigProvider::allowInsecureApi());
    }

    public function testAFreshInstallShipsTheFlagOff(): void
    {
        $xml = simplexml_load_file(__DIR__ . '/../../../addon.xml');
        self::assertNotFalse($xml);
        $items = $xml->xpath('//settings//item[@id="allow_insecure_api"]/default_value') ?: [];

        self::assertCount(1, $items);
        self::assertSame('N', trim((string) $items[0]));
    }
}
