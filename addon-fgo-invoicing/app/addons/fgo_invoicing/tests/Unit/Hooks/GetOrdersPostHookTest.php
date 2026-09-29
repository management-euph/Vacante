<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Tests\Unit\Hooks;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\FgoInvoicing\Services\Container;
use Tygh\Addons\FgoInvoicing\Tests\Support\DbStub;
use Tygh\Addons\FgoInvoicing\Tests\Support\InMemoryInvoiceRepository;
use Tygh\Addons\FgoInvoicing\Tests\Support\LogStub;

/**
 * get_orders_post feeds the FGO column of the admin orders list. CS-Cart
 * fires it for EVERY fn_get_orders() call, storefront included, so it must
 * stay inert outside the admin area, cost one query in it, and never take
 * the orders list down.
 *
 * AREA is a constant: the admin-area cases run in a separate process.
 */
#[CoversNothing]
final class GetOrdersPostHookTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/../../../hooks/order_hooks.php';
        DbStub::reset();
        LogStub::reset();
        Container::reset();
    }

    protected function tearDown(): void
    {
        Container::reset();
        DbStub::reset();
        LogStub::reset();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function orders(): array
    {
        return [['order_id' => 1, 'total' => 5], ['order_id' => 2, 'total' => 6]];
    }

    public function testOutsideTheAdminAreaNothingHappens(): void
    {
        self::assertFalse(defined('AREA'), 'precondition: the unit bootstrap defines no AREA');
        $params = [];
        $orders = self::orders();

        fn_fgo_invoicing_get_orders_post($params, $orders);

        self::assertSame(self::orders(), $orders);
        self::assertSame([], DbStub::calls());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTheStorefrontIsLeftAlone(): void
    {
        define('AREA', 'C');
        $params = [];
        $orders = self::orders();

        fn_fgo_invoicing_get_orders_post($params, $orders);

        self::assertSame(self::orders(), $orders);
        self::assertSame([], DbStub::calls());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTheAdminListGetsTheColumnFromOneLookup(): void
    {
        define('AREA', 'A');
        $repo = (new InMemoryInvoiceRepository())->put(1, ['status' => 'issued', 'invoice_series' => 'F', 'invoice_number' => '0002']);
        Container::getInstance()->withRepository($repo);
        $params = [];
        $orders = self::orders();

        fn_fgo_invoicing_get_orders_post($params, $orders);

        self::assertSame([[1, 2]], $repo->batchLookups);
        self::assertIsArray($orders[0]['fgo_invoice'] ?? null);
        self::assertSame('issued', $orders[0]['fgo_invoice']['status']);
        self::assertSame('F', $orders[0]['fgo_invoice']['invoice_series']);
        self::assertIsArray($orders[1]['fgo_invoice'] ?? null);
        self::assertSame('none', $orders[1]['fgo_invoice']['status']);
        self::assertSame(5, $orders[0]['total']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAFailingLookupNeverBreaksTheOrdersList(): void
    {
        define('AREA', 'A');
        Container::getInstance()->withRepository(new class () extends InMemoryInvoiceRepository {
            #[\Override]
            public function findByOrderIds(array $orderIds): array
            {
                throw new \RuntimeException("Table 'cscart_fgo_invoices' doesn't exist");
            }
        });
        $params = [];
        $orders = self::orders();

        fn_fgo_invoicing_get_orders_post($params, $orders);

        self::assertSame(self::orders(), $orders, 'the column shows "—", the list still renders');
        self::assertContains('[error] orders-list-column', LogStub::messages());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRestrictedAdminsAreSkipped(): void
    {
        define('AREA', 'A');
        define('RESTRICTED_ADMIN', true);
        $repo = new InMemoryInvoiceRepository();
        Container::getInstance()->withRepository($repo);
        $params = [];
        $orders = self::orders();

        fn_fgo_invoicing_get_orders_post($params, $orders);

        self::assertSame(self::orders(), $orders);
        self::assertSame([], $repo->batchLookups);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAnEmptyPageCostsNothing(): void
    {
        define('AREA', 'A');
        $repo = new InMemoryInvoiceRepository();
        Container::getInstance()->withRepository($repo);
        $params = [];
        $orders = [];

        fn_fgo_invoicing_get_orders_post($params, $orders);
        fn_fgo_invoicing_get_orders_post($params);

        self::assertSame([], $orders);
        self::assertSame([], $repo->batchLookups);
    }
}
