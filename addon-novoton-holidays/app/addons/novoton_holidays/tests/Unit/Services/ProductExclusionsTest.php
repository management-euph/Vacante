<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
use Tygh\Registry;

/**
 * No product in an excluded resort, whichever path creates it.
 *
 * add_hotels_as_products was the only creator that read the dashboard's
 * excluded resorts. offers_update (every 2h) and the legacy admin run_cron
 * path created products in excluded resorts — and in the GIFT VOUCHER
 * pseudo-resort — so "never made into products" was not true.
 */
final class ProductExclusionsTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    protected function setUp(): void
    {
        Registry::set('addons.novoton_holidays', ['excluded_resorts' => '["BANSKO","PAMPOROVO"]']);
        ConfigProvider::reset();
    }

    protected function tearDown(): void
    {
        Registry::set('addons.novoton_holidays', []);
        ConfigProvider::reset();
    }

    public function testTheListIsTheDashboardsPlusTheHiddenResorts(): void
    {
        self::assertSame(['BANSKO', 'PAMPOROVO', 'GIFT VOUCHER'], ConfigProvider::getProductExclusions());
    }

    public function testExtraNamesAddToTheSavedListInsteadOfReplacingIt(): void
    {
        self::assertSame(
            ['BANSKO', 'PAMPOROVO', 'VARNA', 'GIFT VOUCHER'],
            ConfigProvider::getProductExclusions([' VARNA ', 'BANSKO', '']),
        );
    }

    public function testAResortMatchesTrimmedAndCaseInsensitively(): void
    {
        $list = ['BANSKO', 'ST.CONSTANTINE & ELENA'];

        self::assertTrue(ConfigProvider::isResortExcluded('Bansko ', $list));
        self::assertTrue(ConfigProvider::isResortExcluded('st.constantine & elena', $list));
        self::assertFalse(ConfigProvider::isResortExcluded('SUNNY BEACH', $list));
        self::assertFalse(ConfigProvider::isResortExcluded('', $list));
        self::assertFalse(ConfigProvider::isResortExcluded(null, $list));
    }

    /** @return iterable<string, array{string, string}> */
    public static function creators(): iterable
    {
        yield 'add_hotels_as_products' => ['src/Cron/Commands/AddProductsCommand.php', 'ConfigProvider::getProductExclusions('];
        yield 'legacy admin run_cron' => ['src/Services/AdminCronService.php', 'ConfigProvider::getProductExclusions()'];
        yield 'offers_update' => ['src/Cron/Commands/OffersUpdateCommand.php', 'ConfigProvider::isResortExcluded($existingDto->city, $exclusions)'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('creators')]
    public function testEveryProductCreatorReadsTheOneList(string $file, string $needle): void
    {
        $src = (string) file_get_contents(self::root() . '/' . $file);

        self::assertStringContainsString($needle, $src);
        self::assertStringNotContainsString('getHiddenResorts()), $limit', $src);
    }

    /** The resort check comes before the product is built, not after. */
    public function testOffersUpdateSkipsExcludedResortsBeforeCreatingTheProduct(): void
    {
        $src = (string) file_get_contents(self::root() . '/src/Cron/Commands/OffersUpdateCommand.php');

        $check = strpos($src, 'ConfigProvider::isResortExcluded(');
        $create = strpos($src, 'fn_update_product(');
        self::assertIsInt($check);
        self::assertIsInt($create);
        self::assertLessThan($create, $check);
    }
}
