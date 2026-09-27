<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Addons\NovotonHolidays\Services\DestinationScope;
use Tygh\Registry;

/**
 * The destination whitelist: per country, not sold / all resorts / only
 * selected. An empty whitelist is "not configured" and changes nothing.
 */
final class DestinationScopeTest extends TestCase
{
    protected function setUp(): void
    {
        Registry::set('addons.novoton_holidays', [
            'excluded_resorts' => '["BANSKO"]',
            'selected_countries' => ['BULGARIA' => 'Y', 'GREECE' => 'Y', 'TURKEY' => 'N'],
        ]);
        ConfigProvider::reset();
    }

    protected function tearDown(): void
    {
        Registry::set('addons.novoton_holidays', []);
        ConfigProvider::reset();
    }

    private static function whitelist(): DestinationScope
    {
        return DestinationScope::fromRows([
            ['country' => 'BULGARIA', 'resort' => '', 'selection_type' => 'specific', 'reviewed_at' => '2026-09-27 10:00:00'],
            ['country' => 'BULGARIA', 'resort' => 'SUNNY BEACH', 'selection_type' => 'specific'],
            ['country' => 'BULGARIA', 'resort' => 'ST.CONSTANTINE & ELENA', 'selection_type' => 'specific'],
            ['country' => 'ALBANIA', 'resort' => '', 'selection_type' => 'all'],
        ]);
    }

    public function testAnEmptyWhitelistKeepsTheOlderSettings(): void
    {
        $scope = DestinationScope::fromRows([]);
        DestinationScope::setCurrent($scope);

        self::assertFalse($scope->isConfigured());
        self::assertSame(['BULGARIA', 'GREECE'], ConfigProvider::getSelectedCountries());
        self::assertFalse($scope->allowsProduct('BULGARIA', 'Bansko'));
        self::assertFalse($scope->allowsProduct('BULGARIA', 'GIFT VOUCHER'));
        self::assertTrue($scope->allowsProduct('GREECE', 'THASSOS'));
        self::assertSame(
            ['skip' => false, 'exclude' => ['BANSKO', 'GIFT VOUCHER'], 'only' => null],
            $scope->productQuery('BULGARIA'),
        );
    }

    public function testOnceSavedTheWhitelistsCountriesAreTheSelectedCountries(): void
    {
        DestinationScope::setCurrent(self::whitelist());

        self::assertSame(['BULGARIA', 'ALBANIA'], ConfigProvider::getSelectedCountries());
        self::assertSame(['BULGARIA', 'GREECE'], ConfigProvider::getSettingCountries());
    }

    public function testEachCountryHasOneOfThreeModes(): void
    {
        $scope = self::whitelist();

        self::assertSame(DestinationScope::MODE_SPECIFIC, $scope->mode('bulgaria'));
        self::assertSame(DestinationScope::MODE_ALL, $scope->mode('ALBANIA'));
        self::assertSame(DestinationScope::MODE_OFF, $scope->mode('GREECE'));
        self::assertSame('2026-09-27 10:00:00', $scope->reviewedAt('BULGARIA'));
    }

    public function testOnlySelectedSellsTheTickedResortsAndNothingNew(): void
    {
        $scope = self::whitelist();

        self::assertTrue($scope->allows('BULGARIA', 'Sunny Beach '));
        self::assertTrue($scope->allows('BULGARIA', 'st.constantine & elena'));
        self::assertFalse($scope->allows('BULGARIA', 'KITEN'));
        self::assertFalse($scope->allows('BULGARIA', ''));
        self::assertSame(['SUNNY BEACH', 'ST.CONSTANTINE & ELENA'], $scope->selectedResorts('BULGARIA'));
    }

    public function testAllResortsSellsNewResortsButNeverTheHiddenOnes(): void
    {
        $scope = self::whitelist();

        self::assertTrue($scope->allows('ALBANIA', 'A RESORT ADDED TOMORROW'));
        self::assertFalse($scope->allows('ALBANIA', 'GIFT VOUCHER'));
        self::assertNull($scope->selectedResorts('ALBANIA'));
    }

    public function testACountryNotSoldIsSkipped(): void
    {
        $scope = self::whitelist();

        self::assertFalse($scope->allows('GREECE', 'THASSOS'));
        self::assertTrue($scope->productQuery('GREECE')['skip']);
    }

    /** Once saved, the older excluded_resorts no longer applies; the run's extras and the hidden ones do. */
    public function testTheProductQueryFollowsTheWhitelist(): void
    {
        $scope = self::whitelist();

        self::assertSame(
            ['skip' => false, 'exclude' => ['VARNA', 'GIFT VOUCHER'], 'only' => ['SUNNY BEACH', 'ST.CONSTANTINE & ELENA']],
            $scope->productQuery('BULGARIA', ['VARNA']),
        );
        self::assertSame(
            ['skip' => false, 'exclude' => ['GIFT VOUCHER'], 'only' => null],
            $scope->productQuery('ALBANIA'),
        );
    }

    public function testRowsThatAreNotWhitelistRowsAreIgnored(): void
    {
        $scope = DestinationScope::fromRows([
            ['country' => 'BULGARIA', 'resort' => '', 'selection_type' => ''],
            ['country' => '', 'resort' => 'X', 'selection_type' => 'all'],
        ]);

        self::assertFalse($scope->isConfigured());
    }
}
