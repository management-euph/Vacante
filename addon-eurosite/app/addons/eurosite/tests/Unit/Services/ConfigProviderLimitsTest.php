<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\Eurosite\Api\RequestPacer;
use Tygh\Addons\Eurosite\Services\ConfigProvider;

/**
 * Scheduled runs: pace and limits. Eurosite set no numbers (28 Oct 2026);
 * the defaults are the ones we told them about, and the store can change
 * them without a release.
 *
 * ConfigProvider reads Tygh\Registry, which the eurosite bootstrap does not
 * stub, so the Registry-backed cases run in their own process.
 */
final class ConfigProviderLimitsTest extends TestCase
{
    private const array DEFAULTS = [
        'scheduled_searches_per_minute' => 10,
        'scheduled_requests_per_minute' => 60,
        'product_info_per_run' => 100,
        'update_products_per_run' => 200,
    ];

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

    /** @return array<string, int> */
    private static function limits(): array
    {
        return [
            'scheduled_searches_per_minute' => ConfigProvider::getScheduledSearchesPerMinute(),
            'scheduled_requests_per_minute' => ConfigProvider::getScheduledRequestsPerMinute(),
            'product_info_per_run' => ConfigProvider::getProductInfoPerRun(),
            'update_products_per_run' => ConfigProvider::getUpdateProductsPerRun(),
        ];
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAStoreWithoutTheSettingsKeepsTheDefaults(): void
    {
        self::stubRegistry([]);
        self::assertSame(self::DEFAULTS, self::limits());

        self::stubRegistry(array_fill_keys(array_keys(self::DEFAULTS), ''));
        self::assertSame(self::DEFAULTS, self::limits(), 'an emptied field');

        self::stubRegistry(array_fill_keys(array_keys(self::DEFAULTS), '0'));
        self::assertSame(self::DEFAULTS, self::limits(), 'zero would stop the runs');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTheStoresNumbersAreUsedUpToACeiling(): void
    {
        self::stubRegistry([
            'scheduled_searches_per_minute' => '6',
            'scheduled_requests_per_minute' => '100000',
            'product_info_per_run' => '250',
            'update_products_per_run' => '999999',
        ]);

        self::assertSame([
            'scheduled_searches_per_minute' => 6,
            'scheduled_requests_per_minute' => 600,
            'product_info_per_run' => 250,
            'update_products_per_run' => 5000,
        ], self::limits());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAScheduledRunTakesItsPaceFromTheSettings(): void
    {
        self::stubRegistry(['scheduled_searches_per_minute' => '5', 'scheduled_requests_per_minute' => '30']);
        RequestPacer::start();
        $pacer = RequestPacer::active();
        RequestPacer::stop();
        self::assertInstanceOf(RequestPacer::class, $pacer);

        $gaps = [];
        foreach (['searchGap', 'gap'] as $name) {
            $gaps[$name] = (new \ReflectionProperty($pacer, $name))->getValue($pacer);
        }
        self::assertEqualsWithDelta(['searchGap' => 12.0, 'gap' => 2.0], $gaps, 0.001);
    }

    public function testAFreshInstallShipsTheSameDefaults(): void
    {
        $xml = simplexml_load_file(__DIR__ . '/../../../addon.xml');
        self::assertNotFalse($xml);
        foreach (self::DEFAULTS as $id => $default) {
            $items = $xml->xpath('//settings//item[@id="' . $id . '"]') ?: [];
            self::assertCount(1, $items, $id);
            self::assertSame('input', trim((string) $items[0]->type), $id . ': a plain input, no stored variants to lose');
            self::assertSame((string) $default, trim((string) $items[0]->default_value), $id);
        }
    }

    /** &limit=N still wins for a one-off run; otherwise the setting caps it. */
    public function testTheNightlyJobsReadTheirCapsFromTheSettings(): void
    {
        $dir = dirname(__DIR__, 3) . '/src/Cron/Commands/';
        self::assertStringContainsString(
            "\$params['limit'] ?? ConfigProvider::getProductInfoPerRun()",
            (string) file_get_contents($dir . 'ProductInfoCacheCommand.php'),
        );
        self::assertStringContainsString(
            "\$params['limit'] ?? ConfigProvider::getUpdateProductsPerRun()",
            (string) file_get_contents($dir . 'UpdateProductsCommand.php'),
        );
    }
}
