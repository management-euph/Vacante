<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\Eurosite\Cron\CronDispatcher;
use Tygh\Addons\Eurosite\Cron\Commands\AvailabilityCommand;
use Tygh\Addons\Eurosite\Services\CronPlanBuilder;

/**
 * The three hotel-to-product jobs are scheduled like the rest, in the order
 * they depend on each other: details 04:00 → availability 04:30 → add
 * products 05:30 → update products 06:00.
 */
final class CronPlanProductJobsTest extends TestCase
{
    protected function setUp(): void
    {
        CronDispatcher::reset();
    }

    public function testTheJobsRunAfterThePipelineInDependencyOrder(): void
    {
        $rows = (new CronPlanBuilder('http://shop', 'k'))->rows(CronDispatcher::getAvailableModes(), [], []);
        $modes = array_column($rows, 'mode');

        $at = array_flip($modes);
        self::assertLessThan($at['availability'], $at['product_info']);
        self::assertLessThan($at['add_products'], $at['availability']);
        self::assertLessThan($at['update_products'], $at['add_products']);
        self::assertLessThan($at['cleanup'], $at['update_products']);

        $byMode = array_column($rows, null, 'mode');
        self::assertSame('Daily 04:30', $byMode['availability']['schedule_human']);
        self::assertSame('Daily 05:30', $byMode['add_products']['schedule_human']);
        self::assertSame('Daily 06:00', $byMode['update_products']['schedule_human']);
        foreach (['availability', 'add_products', 'update_products'] as $mode) {
            self::assertTrue(CronPlanBuilder::hasSchedule($mode), "{$mode} has its own slot");
            self::assertFalse($byMode[$mode]['in_pipeline']);
        }
    }

    public function testTheNightlyPlanSchedulesThemBesideTheFullSync(): void
    {
        $planned = (new CronPlanBuilder('http://shop', 'k'))->plannedModes('full', CronDispatcher::getAvailableModes());

        self::assertSame(['full', 'product_info', 'availability', 'add_products', 'update_products', 'cleanup'], $planned);
    }

    public function testTheirRowsCountImmediateHotelsAndProducts(): void
    {
        $rows = (new CronPlanBuilder('http://shop', 'k'))->rows(CronDispatcher::getAvailableModes(), ['immediate' => 50, 'products' => 12], []);
        $byMode = array_column($rows, null, 'mode');

        self::assertSame(50, $byMode['availability']['count']);
        self::assertSame(12, $byMode['add_products']['count']);
        self::assertSame(12, $byMode['update_products']['count']);
    }

    public function testTheAvailabilityReportNamesEachDestination(): void
    {
        self::assertSame(
            '  Mamaia RO0101: 12 Immediate, 3 On request, 1 Stop sale, 80 no offer',
            AvailabilityCommand::cityLine('RO0101', 'Mamaia', ['IM' => 12, 'OR' => 3, 'ST' => 1, 'NONE' => 80]),
        );
        self::assertSame('  RO2M: 0 Immediate, 0 On request, 0 Stop sale, 5 no offer', AvailabilityCommand::cityLine('RO2M', '', ['NONE' => 5]));
    }

    /** The product page's booking form sends hotel_id; the search is that hotel's only. */
    public function testTheStorefrontSearchCanNarrowToOneHotel(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/controllers/frontend/eurosite_booking/search.php');

        self::assertStringContainsString("RequestCoerce::string(\$_REQUEST, 'hotel_id')", $src);
        self::assertStringContainsString('if (strtoupper($pc) !== $onlyHotel) {', $src);
        self::assertLessThan(strpos($src, '$whitelist->isCityAllowed($country, $city)'), strpos($src, '$onlyHotel = '), 'the hotel still has to be in a whitelisted city');
    }
}
