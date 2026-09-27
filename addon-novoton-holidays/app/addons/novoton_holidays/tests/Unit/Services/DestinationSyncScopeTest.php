<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Addons\NovotonHolidays\Services\DestinationScope;
use Tygh\Registry;

/**
 * Stage 2: the per-hotel API syncs (hotelinfo, priceinfo, facilities,
 * room_price) spend calls only on the destinations we sell — plus any hotel
 * still linked to an active product, so a live product never goes stale.
 */
final class DestinationSyncScopeTest extends TestCase
{
    protected function setUp(): void
    {
        Registry::set('addons.novoton_holidays', ['selected_countries' => ['BULGARIA' => 'Y', 'GREECE' => 'Y']]);
        ConfigProvider::reset();
    }

    protected function tearDown(): void
    {
        Registry::set('addons.novoton_holidays', []);
        ConfigProvider::reset();
    }

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    public function testUntilAWhitelistIsSavedTheSyncsKeepTheSelectedCountries(): void
    {
        $scope = DestinationScope::fromRows([]);
        DestinationScope::setCurrent($scope);

        self::assertSame("h.country IN (BULGARIA,GREECE)", $scope->syncWhere('h'));
        self::assertSame("country IN (BULGARIA,GREECE)", $scope->syncWhere());
    }

    public function testASavedWhitelistSyncsItsResortsAndEveryLiveProduct(): void
    {
        $scope = DestinationScope::fromRows([
            ['country' => 'BULGARIA', 'resort' => '', 'selection_type' => 'specific'],
            ['country' => 'BULGARIA', 'resort' => 'SUNNY BEACH', 'selection_type' => 'specific'],
            ['country' => 'BULGARIA', 'resort' => 'ST.CONSTANTINE & ELENA', 'selection_type' => 'specific'],
            ['country' => 'ALBANIA', 'resort' => '', 'selection_type' => 'all'],
        ]);

        self::assertSame(
            "((("
            . "(h.country = 'BULGARIA' AND TRIM(h.city) IN (SUNNY BEACH,ST.CONSTANTINE & ELENA))"
            . " OR h.country = 'ALBANIA')"
            . " AND (h.city IS NULL OR h.city NOT IN (GIFT VOUCHER)))"
            . " OR h.product_id IN (SELECT product_id FROM ?:products WHERE status = 'A'))",
            $scope->syncWhere('h'),
        );
    }

    /** An "Only selected" country with nothing ticked syncs nothing — except its live products. */
    public function testNothingTickedSyncsOnlyTheLiveProducts(): void
    {
        $scope = DestinationScope::fromRows([
            ['country' => 'BULGARIA', 'resort' => '', 'selection_type' => 'specific'],
        ]);

        self::assertStringStartsWith('((1 = 0 AND', $scope->syncWhere());
        self::assertStringEndsWith("OR product_id IN (SELECT product_id FROM ?:products WHERE status = 'A'))", $scope->syncWhere());
    }

    /** @return iterable<string, array{string, string}> */
    public static function syncs(): iterable
    {
        yield 'hotelinfo (full + incremental)' => ['src/Helpers/BatchedHotelInfoSyncV2.php', 'DestinationScope::current()->syncWhere()'];
        yield 'priceinfo' => ['src/Helpers/BatchedPriceInfoSyncV2.php', "DestinationScope::current()->syncWhere('h')"];
        yield 'facilities' => ['src/Helpers/BatchedHotelFacilitiesSyncV2.php', "\$scope->syncWhere('h')"];
        yield 'room_price' => ['src/Cron/Commands/RoomPriceCheckCommand.php', 'DestinationScope::current()->syncWhere()'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('syncs')]
    public function testEveryPerHotelSyncPicksItsHotelsThroughTheScope(string $file, string $needle): void
    {
        $src = (string) file_get_contents(self::root() . '/' . $file);

        self::assertStringContainsString($needle, $src);
    }

    /** Incremental hotelinfo drops known out-of-scope hotels; unknown new ones stay. */
    public function testIncrementalHotelInfoDropsOnlyKnownOutOfScopeHotels(): void
    {
        $src = (string) file_get_contents(self::root() . '/src/Helpers/BatchedHotelInfoSyncV2.php');

        self::assertStringContainsString("'SELECT hotel_id FROM ?:novoton_hotels WHERE hotel_id IN (?a) AND NOT ' . \$scope", $src);
        self::assertStringContainsString('array_diff($changed, $outside)', $src);
    }
}
