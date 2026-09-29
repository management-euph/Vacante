<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Tests\Unit\Hooks;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\FgoInvoicing\Constants;
use Tygh\Addons\FgoInvoicing\Repository\InvoiceRepository;
use Tygh\Addons\FgoInvoicing\Services\ConfigProvider;
use Tygh\Addons\FgoInvoicing\Services\Container;
use Tygh\Addons\FgoInvoicing\Tests\Support\DbStub;

/**
 * REGRESSION: a hook must never require more arguments than CS-Cart passes it.
 *
 * fn_fgo_invoicing_change_order_status() was declared with seven mandatory
 * parameters, but CS-Cart 4.x calls
 *
 *     fn_set_hook('change_order_status', $status_to, $status_from, $order_info,
 *                 $force_notification, $order_statuses, $place_order);
 *
 * with six. The mismatch is invisible until an order actually changes status,
 * and then it is fatal inside fn_place_order():
 *
 *     ArgumentCountError: Too few arguments to function
 *     fn_fgo_invoicing_change_order_status(), 6 passed in
 *     app/functions/fn.control.php on line 124 and exactly 7 expected
 *
 * — i.e. every customer checkout dies on "Place order" after the order row has
 * already been written. PHPStan cannot catch it (it never sees fn_set_hook's
 * dynamic dispatch), so the arity is pinned here instead.
 *
 * The expected counts below are the CS-Cart 4.x core call sites. Passing MORE
 * arguments than a user function declares is legal in PHP, so a hook only has
 * to keep its required count at or below them.
 */
#[CoversNothing]
final class OrderHooksArityTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/../../../hooks/order_hooks.php';
        require_once __DIR__ . '/../../../hooks/profile_hooks.php';
        require_once __DIR__ . '/../../../hooks/settings_hooks.php';
        require_once __DIR__ . '/../../../functions/settings_heal.php';

        // Anything but 'onOrder'/'onPayment'/'onCompleted' for the status under
        // test, so the hooks return before reaching the service container.
        ConfigProvider::seed(['api_call' => 'manual']);
    }

    protected function tearDown(): void
    {
        ConfigProvider::reset();
        Container::reset();
    }

    /**
     * Every hook name init.php passes to fn_register_hooks(), with the number
     * of arguments the CS-Cart 4.x core call site passes (place_order_post:
     * 4.20.1 fn_place_order(), $cart first and $order_id sixth). The two profile
     * hooks are listed at 1: their core arity is not pinned down for every
     * build, so they are simply required to tolerate a one-argument call.
     * dispatch_before_display is fired with no arguments at all;
     * get_orders_post with ($params, $orders) by 4.20.1 fn_get_orders().
     *
     * @return array<string, array{string, int}> hook fn => args the core passes
     */
    public static function coreArities(): array
    {
        return [
            'place_order_post'          => ['fn_fgo_invoicing_place_order_post', 9],
            'change_order_status'       => ['fn_fgo_invoicing_change_order_status', 6],
            'get_order_info'            => ['fn_fgo_invoicing_get_order_info', 2],
            'get_orders_post'           => ['fn_fgo_invoicing_get_orders_post', 2],
            'profile_fields_get_fields' => ['fn_fgo_invoicing_profile_fields_get_fields', 1],
            'update_profile_fields_post' => ['fn_fgo_invoicing_update_profile_fields_post', 1],
            'dispatch_before_display'   => ['fn_fgo_invoicing_dispatch_before_display', 0],
        ];
    }

    /**
     * The table above must not drift from what the addon actually registers:
     * a hook added to init.php without an entry here goes unchecked.
     */
    public function testEveryRegisteredHookIsCovered(): void
    {
        $init = (string) file_get_contents(__DIR__ . '/../../../init.php');
        self::assertSame(
            1,
            preg_match('/fn_register_hooks\((.*?)\);/s', $init, $m),
            'fn_register_hooks() call not found in init.php',
        );
        preg_match_all("/'([a-z0-9_]+)'/", $m[1], $names);

        $checked = array_keys(self::coreArities());
        sort($checked);
        $registered = $names[1];
        sort($registered);

        self::assertSame($registered, $checked, 'registered hooks and the arity table have drifted apart');
    }

    #[DataProvider('coreArities')]
    public function testHookDoesNotRequireMoreArgumentsThanTheCorePasses(
        string $function,
        int $coreArgs,
    ): void {
        self::assertTrue(function_exists($function), "{$function} is not defined");

        $required = (new \ReflectionFunction($function))->getNumberOfRequiredParameters();

        self::assertLessThanOrEqual(
            $coreArgs,
            $required,
            "{$function} requires {$required} arguments but CS-Cart passes {$coreArgs} — "
            . 'this is fatal at runtime, not a warning',
        );
    }

    /**
     * The reflection check above proves the signature; this proves the call.
     * Every argument is a variable because the hook takes them by reference.
     */
    public function testChangeOrderStatusSurvivesTheSixArgumentCoreCall(): void
    {
        $statusTo = 'P';
        $statusFrom = 'O';
        $orderInfo = [];           // no order_id → the hook returns before any I/O
        $forceNotification = [];
        $orderStatuses = [];
        $placeOrder = true;

        fn_fgo_invoicing_change_order_status(
            $statusTo,
            $statusFrom,
            $orderInfo,
            $forceNotification,
            $orderStatuses,
            $placeOrder,
        );

        self::assertSame('P', $statusTo, 'the hook must not rewrite the status it observes');
    }

    /**
     * Newer builds append $reason; the extra argument must still be accepted.
     */
    public function testChangeOrderStatusAlsoAcceptsTheSevenArgumentCall(): void
    {
        $statusTo = 'C';
        $statusFrom = 'P';
        $orderInfo = [];
        $forceNotification = [];
        $orderStatuses = [];
        $placeOrder = false;
        $reason = 'manual';

        fn_fgo_invoicing_change_order_status(
            $statusTo,
            $statusFrom,
            $orderInfo,
            $forceNotification,
            $orderStatuses,
            $placeOrder,
            $reason,
        );

        self::assertSame('C', $statusTo);
    }

    public function testPlaceOrderPostSurvivesTheCoreCall(): void
    {
        $cart = ['products' => []];
        $auth = [];
        $action = '';
        $issuerId = null;
        $parentOrderId = 0;
        $orderId = 0;              // no order → returns before any I/O
        $orderStatus = 'O';
        $shortOrderData = [];
        $notificationRules = [];

        fn_fgo_invoicing_place_order_post(
            $cart,
            $auth,
            $action,
            $issuerId,
            $parentOrderId,
            $orderId,
            $orderStatus,
            $shortOrderData,
            $notificationRules,
        );

        self::assertSame(0, $orderId);
    }

    /**
     * REGRESSION: the hook was declared ($order_id, $action, ...) — the
     * 'place_order' hook's order — so the CART arrived as $order_id, read 0,
     * and "Place order" mode never issued. 4.20 passes $order_id sixth.
     */
    public function testPlaceOrderPostIssuesForTheOrderIdInTheSixthArgument(): void
    {
        ConfigProvider::seed(['api_call' => 'onOrder']);
        $repo = self::recordingRepository();
        Container::getInstance()->withRepository($repo);

        $cart = ['order_id' => 999, 'products' => []];
        $auth = ['user_id' => 7];
        $action = '';
        $issuerId = null;
        $parentOrderId = 0;
        $orderId = 1234;
        $orderStatus = 'O';
        $shortOrderData = ['status' => 'O'];
        $notificationRules = [];

        fn_fgo_invoicing_place_order_post(
            $cart,
            $auth,
            $action,
            $issuerId,
            $parentOrderId,
            $orderId,
            $orderStatus,
            $shortOrderData,
            $notificationRules,
        );

        // No fn_get_order_info in the unit bootstrap: the issuer records the
        // attempt and fails it, which is enough to see which order it was for.
        self::assertSame([1234], $repo->pendingFor);
    }

    /**
     * This runs inside the customer's "Place order" request: nothing the
     * issuer throws may escape into checkout.
     */
    public function testPlaceOrderPostSwallowsAnIssuerFailure(): void
    {
        ConfigProvider::seed(['api_call' => 'onOrder']);
        $repo = self::recordingRepository(throwOnInsert: true);
        Container::getInstance()->withRepository($repo);

        $cart = [];
        $auth = [];
        $action = '';
        $issuerId = null;
        $parentOrderId = 0;
        $orderId = 1234;

        fn_fgo_invoicing_place_order_post($cart, $auth, $action, $issuerId, $parentOrderId, $orderId);

        self::assertSame([1234], $repo->pendingFor, 'the issuer was reached and failed');
    }

    private static function recordingRepository(bool $throwOnInsert = false): InvoiceRepository
    {
        return new class ($throwOnInsert) extends InvoiceRepository {
            /** @var list<int> */
            public array $pendingFor = [];

            public function __construct(private readonly bool $throwOnInsert)
            {
            }

            public function findByOrderId(int $orderId): ?array
            {
                return null;
            }

            public function insertPending(int $orderId, ?int $cartId = null): array
            {
                $this->pendingFor[] = $orderId;
                if ($this->throwOnInsert) {
                    throw new \RuntimeException('table ?:fgo_invoices is missing');
                }

                return ['id' => 1, 'isExisting' => false, 'status' => Constants::STATUS_PENDING];
            }

            public function markFailed(int $orderId, string $err, array $form, ?array $raw = null): void
            {
            }
        };
    }

    /**
     * The settings heal hangs off this hook on EVERY page, storefront included.
     * Outside the admin area (and here: no AREA, no travel_core) it must return
     * before touching the database or the Settings API.
     */
    public function testDispatchBeforeDisplayIsInertOutsideTheAdminArea(): void
    {
        DbStub::reset();

        fn_fgo_invoicing_dispatch_before_display();

        self::assertFalse(defined('AREA'), 'precondition: the unit bootstrap defines no AREA');
        self::assertSame([], DbStub::calls());
    }

    public function testGetOrderInfoSurvivesTheCoreCall(): void
    {
        $order = ['order_id' => 0];
        $additionalData = [];

        fn_fgo_invoicing_get_order_info($order, $additionalData);

        self::assertArrayNotHasKey('fgo_invoice', $order);
    }
}
