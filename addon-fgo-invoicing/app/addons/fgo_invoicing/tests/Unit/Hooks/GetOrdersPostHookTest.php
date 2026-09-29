<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Tests\Unit\Hooks;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\FgoInvoicing\Services\Container;
use Tygh\Addons\FgoInvoicing\Tests\Support\DbStub;
use Tygh\Addons\FgoInvoicing\Tests\Support\InMemoryInvoiceRepository;
use Tygh\Addons\FgoInvoicing\Tests\Support\LogStub;
use Tygh\Registry;

/**
 * get_orders_post feeds the FGO column of the admin orders list. CS-Cart
 * fires it for EVERY fn_get_orders() call, storefront and REST API
 * included, so it must stay inert outside the admin panel, cost one query
 * in it, and never take the orders list down. get_order_info (the order
 * details panel) answers to the same context, with the summary only.
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

    /**
     * fn_get_orders() also answers the REST API (AREA 'A' there too): FGO
     * data stays out of it.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTheApiIsLeftAlone(): void
    {
        define('AREA', 'A');
        define('API', true);
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
    public function testASelectedStorefrontIsLeftAlone(): void
    {
        define('AREA', 'A');
        Registry::set('runtime.company_id', 2);
        $repo = new InMemoryInvoiceRepository();
        Container::getInstance()->withRepository($repo);
        $params = [];
        $orders = self::orders();

        fn_fgo_invoicing_get_orders_post($params, $orders);

        self::assertSame(self::orders(), $orders);
        self::assertSame([], $repo->batchLookups);
    }

    // ── get_order_info: the order details panel ─────────────────────────

    /**
     * The panel gets the summary columns only: the stored request and
     * response (the customer's data as sent to FGO) never ride along with
     * fn_get_order_info().
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTheOrderDetailsGetTheSummaryOnly(): void
    {
        define('AREA', 'A');
        $repo = (new InMemoryInvoiceRepository())->put(1, [
            'status' => 'issued',
            'invoice_series' => 'F',
            'invoice_number' => '0002',
            'pdf_link' => 'https://api.fgo.ro/p/2',
            'request_payload' => '{"Client[CodUnic]":"19601*****56"}',
            'payload' => '{"Success":true}',
            'updated_at' => '2026-09-29 10:00:00',
        ]);
        Container::getInstance()->withRepository($repo);
        $order = ['order_id' => 1];

        fn_fgo_invoicing_get_order_info($order);

        self::assertSame([
            'status' => 'issued',
            'invoice_series' => 'F',
            'invoice_number' => '0002',
            'pdf_link' => 'https://api.fgo.ro/p/2',
            'last_error' => '',
            'updated_at' => '2026-09-29 10:00:00',
        ], $order['fgo_invoice'] ?? null);
    }

    /**
     * @return iterable<string, array{string, bool, bool, int}>
     */
    public static function contextsWithoutFgoData(): iterable
    {
        yield 'storefront' => ['C', false, false, 0];
        yield 'REST API' => ['A', true, false, 0];
        yield 'restricted admin' => ['A', false, true, 0];
        yield 'selected storefront' => ['A', false, false, 3];
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    #[DataProvider('contextsWithoutFgoData')]
    public function testTheOrderDetailsGetNothingOutsideTheAdminPanel(string $area, bool $api, bool $restricted, int $companyId): void
    {
        define('AREA', $area);
        if ($api) {
            define('API', true);
        }
        if ($restricted) {
            define('RESTRICTED_ADMIN', true);
        }
        Registry::set('runtime.company_id', $companyId);
        $repo = (new InMemoryInvoiceRepository())->put(1, ['status' => 'issued']);
        Container::getInstance()->withRepository($repo);
        $order = ['order_id' => 1];

        fn_fgo_invoicing_get_order_info($order);

        self::assertSame(['order_id' => 1], $order);
        self::assertSame([], $repo->batchLookups);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAFailingLookupNeverBreaksTheOrderPage(): void
    {
        define('AREA', 'A');
        Container::getInstance()->withRepository(new class () extends InMemoryInvoiceRepository {
            #[\Override]
            public function findByOrderIds(array $orderIds): array
            {
                throw new \RuntimeException("Table 'cscart_fgo_invoices' doesn't exist");
            }
        });
        $order = ['order_id' => 1];

        fn_fgo_invoicing_get_order_info($order);

        self::assertSame(['order_id' => 1], $order);
        self::assertContains('[error] order-details-panel', LogStub::messages());
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
