<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\FgoInvoicing\Services\OrderInfoSource;

#[CoversClass(OrderInfoSource::class)]
final class OrderInfoSourceTest extends TestCase
{
    public function testWithoutCsCartThereIsNoOrder(): void
    {
        self::assertFalse(function_exists('fn_get_order_info'), 'precondition: the unit bootstrap has no core');

        self::assertNull(OrderInfoSource::core()(5));
    }

    /**
     * Separate process: the fn_get_order_info() stand-in is a global function.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCoreOrdersAreReturnedAndMissingOnesAreNull(): void
    {
        require_once dirname(__DIR__, 2) . '/Fixtures/fn_get_order_info_stub.php';
        $GLOBALS['fgo_test_orders'] = [5 => ['order_id' => 5, 'total' => 10], 6 => []];

        $load = OrderInfoSource::core();

        self::assertSame(['order_id' => 5, 'total' => 10], $load(5));
        self::assertNull($load(6), 'CS-Cart returns an empty array for some missing orders');
        self::assertNull($load(7));
        self::assertNull($load(0));
    }
}
