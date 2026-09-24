<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * Pins the reverse-geocoding schema end to end: the street_address /
 * geocoded_at columns exist for fresh installs (addon.xml CREATE) AND for
 * upgrades (install.php $migrations), the dynamic-query whitelist knows
 * them, the PDP provider actually selects the street and feeds it to the
 * shared HotelSeoData->address (which flips HotelLocationLine to postal
 * style), the settings are declared opt-in, and the admin cron page lists
 * the new mode.
 */
final class GeocodeSchemaTest extends TestCase
{
    private static function addonRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function read(string $relative): string
    {
        $path = self::addonRoot() . '/' . $relative;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function testFreshInstallCreateTableHasBothColumns(): void
    {
        $xml = self::read('addon.xml');

        self::assertStringContainsString('`street_address` varchar(255) DEFAULT NULL', $xml);
        self::assertStringContainsString('`geocoded_at` datetime DEFAULT NULL', $xml);
    }

    public function testUpgradeMigrationsAddBothColumns(): void
    {
        $install = self::read('functions/install.php');

        self::assertStringContainsString(
            'ALTER TABLE ?:novoton_hotels ADD COLUMN `street_address`',
            $install,
        );
        self::assertStringContainsString(
            'ALTER TABLE ?:novoton_hotels ADD COLUMN `geocoded_at`',
            $install,
        );
    }

    public function testDynamicQueryWhitelistKnowsTheNewColumns(): void
    {
        $helper = self::read('src/Helpers/DatabaseHelper.php');

        self::assertStringContainsString("'street_address', 'geocoded_at',", $helper);
    }

    public function testPdpProviderSelectsStreetAndFeedsDtoAddress(): void
    {
        $provider = self::read('src/Providers/NovotonHotelProductProvider.php');

        self::assertStringContainsString('street_address', $provider);
        self::assertStringContainsString("address: self::optString(\$row['street_address']", $provider);
    }

    /**
     * Geocoding is configured ONCE, in Travel Core.
     *
     * The Nominatim usage policy caps requests per APPLICATION and both
     * provider addons share GeocodeBacklogRunner, so a per-addon switch would
     * be a second control for one behaviour — and a stale novoton row could
     * silently keep geocoding running after the shared one was turned off.
     */
    public function testGeocodingSettingsLiveInTravelCoreOnly(): void
    {
        $xml = self::read('addon.xml');

        foreach (['geocoding_enabled', 'geocoding_contact_email', 'geocoding_endpoint', 'geocoding_header'] as $name) {
            self::assertStringNotContainsString('<item id="' . $name . '">', $xml);
        }

        // Nor may the labels linger in the .po: they route to
        // ?:settings_descriptions for rows that no longer exist.
        foreach (['en', 'ro'] as $lang) {
            $po = self::read('../../../var/langs/' . $lang . '/addons/novoton_holidays.po');
            self::assertStringNotContainsString('::novoton_holidays::geocoding', $po);
        }

        $core = self::addonRoot() . '/../../../../addon-travel-core/app/addons/travel_core/addon.xml';
        self::assertFileExists($core);
        $coreXml = (string) file_get_contents($core);
        foreach (['geocoding_enabled', 'geocoding_contact_email', 'geocoding_endpoint'] as $name) {
            self::assertStringContainsString('<item id="' . $name . '">', $coreXml);
        }

        // The config getters delegate — no novoton fallback that could win.
        $config = self::read('src/Services/ConfigProvider.php');
        self::assertStringContainsString(
            'return TravelCoreConfig::isGeocodingEnabled();',
            $config,
        );
        self::assertStringNotContainsString("settings()['geocoding_", $config);
    }

    /** The settings page points at the dashboard's jobs table, which lists every job once. */
    public function testAdminCronPageListsTheMode(): void
    {
        $tpl = self::read('../../../design/backend/templates/addons/novoton_holidays/settings/cron_info.tpl');

        self::assertStringContainsString('"novoton_holidays.manage"|fn_url}#novoton-cron-jobs', $tpl);
    }

    public function testDashboardSchedulesTheMode(): void
    {
        self::assertContains('geocode_addresses', array_merge(...array_values(\Tygh\Addons\NovotonHolidays\Services\CronPlanBuilder::STAGES)));
        self::assertNotSame('', \Tygh\Addons\NovotonHolidays\Services\CronPlanBuilder::cron('geocode_addresses'));
    }
}
