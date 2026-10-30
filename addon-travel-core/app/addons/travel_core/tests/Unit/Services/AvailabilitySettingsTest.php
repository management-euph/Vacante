<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Enums\AvailabilityMode;
use Tygh\Addons\TravelCore\Services\TravelCoreConfig;
use Tygh\Registry;

/**
 * The hotel availability settings: a mode per supplier (Report only unless
 * the admin says otherwise) and the admin's months, declared in addon.xml
 * with labels that reach every store.
 */
final class AvailabilitySettingsTest extends TestCase
{
    private const array SETTINGS = [
        'availability_mode_novoton', 'availability_mode_eurosite', 'availability_mode_sphinx',
        'availability_dormant_months', 'availability_on_request',
    ];

    protected function tearDown(): void
    {
        foreach (self::SETTINGS as $name) {
            Registry::set('addons.travel_core.' . $name, null);
        }
    }

    public function testEachSupplierIsReportOnlyUntilTheAdminSwitchesIt(): void
    {
        Registry::set('addons.travel_core.availability_mode_eurosite', 'off');

        self::assertSame(AvailabilityMode::Report, TravelCoreConfig::getAvailabilityMode('novoton'));
        self::assertSame(AvailabilityMode::Off, TravelCoreConfig::getAvailabilityMode('eurosite'));
    }

    public function testAnUnknownModeReadsAsReportOnly(): void
    {
        self::assertSame(AvailabilityMode::Report, AvailabilityMode::fromSetting('hide-everything'));
        self::assertSame(AvailabilityMode::Report, AvailabilityMode::fromSetting(null));
    }

    /** Automatic only for a supplier Travel Core's gate acts for (Novoton); the others keep their own gates for now. */
    public function testAutomaticIsOfferedOnlyWhereTheSharedGateActs(): void
    {
        self::assertSame([AvailabilityMode::Off, AvailabilityMode::Report], AvailabilityMode::offered());
        self::assertSame([AvailabilityMode::Off, AvailabilityMode::Report, AvailabilityMode::Automatic], AvailabilityMode::offered(true));
    }

    public function testTheAdminsMonthsAndOnRequestChoiceReachTheRules(): void
    {
        Registry::set('addons.travel_core.availability_dormant_months', '15');
        Registry::set('addons.travel_core.availability_on_request', 'N');

        $limits = TravelCoreConfig::getAvailabilityThresholds();

        self::assertSame(15, $limits->dormantMonths);
        self::assertFalse($limits->onRequestCounts);
    }

    public function testTheSettingsAreDeclaredWithTheDecidedDefaults(): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 3) . '/addon.xml');
        self::assertNotFalse($xml);
        $defaults = [];
        foreach ($xml->xpath('//settings//items/item[@id]') ?: [] as $item) {
            $defaults[(string) $item['id']] = (string) $item->default_value;
        }

        self::assertSame('report', $defaults['availability_mode_novoton'] ?? null);
        self::assertSame('report', $defaults['availability_mode_eurosite'] ?? null);
        self::assertSame('report', $defaults['availability_mode_sphinx'] ?? null);
        self::assertSame('12', $defaults['availability_dormant_months'] ?? null);
        self::assertSame('Y', $defaults['availability_on_request'] ?? null);
    }

    /** The options come from a function with labelled language keys, so stores healed by SettingsMigrator get labels too. */
    public function testEveryModeSelectHasItsOptionsFunction(): void
    {
        $func = (string) file_get_contents(dirname(__DIR__, 3) . '/func.php');
        foreach (['novoton', 'eurosite', 'sphinx'] as $provider) {
            self::assertStringContainsString("function fn_settings_variants_addons_travel_core_availability_mode_{$provider}(): array", $func);
        }

        /** @var array<string, array<string, string>> $keys */
        $keys = require dirname(__DIR__, 3) . '/lang_keys.php';
        foreach (AvailabilityMode::offered(true) as $mode) {
            self::assertNotSame('', $keys['travel_core.availability_mode_' . $mode->value]['ro'] ?? '', $mode->value);
        }
    }
}
