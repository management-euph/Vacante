<?php

declare(strict_types=1);

namespace Tygh\Addons\FgoInvoicing\Tests\Unit\Hooks;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\FgoInvoicing\Services\ConfigProvider;

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

        // Anything but 'onOrder'/'onPayment'/'onCompleted' for the status under
        // test, so the hooks return before reaching the service container.
        ConfigProvider::seed(['api_call' => 'manual']);
    }

    protected function tearDown(): void
    {
        ConfigProvider::reset();
    }

    /**
     * Every hook name init.php passes to fn_register_hooks(), with the number
     * of arguments the CS-Cart 4.x core call site passes. The two profile
     * hooks are listed at 1: their core arity is not pinned down for every
     * build, so they are simply required to tolerate a one-argument call.
     *
     * @return array<string, array{string, int}> hook fn => args the core passes
     */
    public static function coreArities(): array
    {
        return [
            'place_order_post'          => ['fn_fgo_invoicing_place_order_post', 5],
            'change_order_status'       => ['fn_fgo_invoicing_change_order_status', 6],
            'get_order_info'            => ['fn_fgo_invoicing_get_order_info', 2],
            'profile_fields_get_fields' => ['fn_fgo_invoicing_profile_fields_get_fields', 1],
            'update_profile_fields_post' => ['fn_fgo_invoicing_update_profile_fields_post', 1],
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
        $orderId = 0;              // no order → returns before any I/O
        $action = '';
        $orderStatus = 'O';
        $cart = [];
        $auth = [];

        fn_fgo_invoicing_place_order_post($orderId, $action, $orderStatus, $cart, $auth);

        self::assertSame(0, $orderId);
    }

    public function testGetOrderInfoSurvivesTheCoreCall(): void
    {
        $order = ['order_id' => 0];
        $additionalData = [];

        fn_fgo_invoicing_get_order_info($order, $additionalData);

        self::assertArrayNotHasKey('fgo_invoice', $order);
    }
}
