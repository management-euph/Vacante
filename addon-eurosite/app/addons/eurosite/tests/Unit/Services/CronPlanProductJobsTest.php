<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\Eurosite\Cron\CronDispatcher;
use Tygh\Addons\Eurosite\Cron\Commands\AvailabilityCommand;
use Tygh\Addons\Eurosite\Services\CronPlanBuilder;

/**
 * The hotel-to-product jobs are scheduled like the rest, in the order they
 * depend on each other: details 04:00 → availability 04:15 → add products
 * 05:30 → update products 06:00.
 */
final class CronPlanProductJobsTest extends TestCase
{
    protected function setUp(): void
    {
        CronDispatcher::reset();
    }

    /** @return array<string, array<string, mixed>> mode => row, in the card's order */
    private static function rows(): array
    {
        $rows = [];
        foreach ((new CronPlanBuilder('http://shop', 'k'))->stages(CronDispatcher::getAvailableModes(), [], [], time()) as $stage) {
            foreach ($stage['jobs'] as $job) {
                $rows[(string) $job['mode']] = $job;
            }
        }

        return $rows;
    }

    public function testTheJobsRunAfterTheCatalogsInDependencyOrder(): void
    {
        $rows = self::rows();
        $at = array_flip(array_keys($rows));

        self::assertLessThan($at['product_info'], $at['tags']);
        self::assertLessThan($at['availability'], $at['product_info']);
        self::assertLessThan($at['add_products'], $at['availability']);
        self::assertLessThan($at['update_products'], $at['add_products']);
        self::assertLessThan($at['cleanup'], $at['update_products']);

        self::assertSame('15 4 * * *', $rows['availability']['cron']);
        self::assertSame('30 5 * * *', $rows['add_products']['cron']);
        self::assertSame('0 6 * * *', $rows['update_products']['cron']);
        foreach (['availability', 'add_products', 'update_products'] as $mode) {
            self::assertNotContains($mode, CronPlanBuilder::PIPELINE, "{$mode} is not run by full");
        }
    }

    public function testBothPlansScheduleThemBesideTheCatalogs(): void
    {
        $plan = new CronPlanBuilder('http://shop', 'k');
        foreach (CronPlanBuilder::PLANS as $key) {
            $text = $plan->crontabFor($key, CronDispatcher::getAvailableModes(), 'cli');
            foreach (['product_info', 'availability', 'add_products', 'update_products', 'booking_status', 'cleanup'] as $mode) {
                self::assertStringContainsString(' mode=' . $mode . "\n", $text . "\n", "{$key}: {$mode}");
            }
        }
    }

    public function testTheirRowsCountImmediateHotelsAndProducts(): void
    {
        $stages = CronPlanBuilder::withCounts(
            (new CronPlanBuilder('http://shop', 'k'))->stages(CronDispatcher::getAvailableModes(), [], [], time()),
            ['immediate' => 50, 'products' => 12],
        );
        $count = [];
        foreach ($stages as $stage) {
            foreach ($stage['jobs'] as $job) {
                $count[(string) $job['mode']] = $job['count'];
            }
        }

        self::assertSame(50, $count['availability']);
        self::assertSame(12, $count['add_products']);
        self::assertSame(12, $count['update_products']);
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
