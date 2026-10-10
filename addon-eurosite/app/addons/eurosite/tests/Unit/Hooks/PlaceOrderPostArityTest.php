<?php

declare(strict_types=1);

namespace {
    // func.php holds the fn_* functions CS-Cart dispatches to; load it in the
    // GLOBAL namespace, as CS-Cart does.
    if (!defined('BOOTSTRAP')) {
        define('BOOTSTRAP', true);
    }

    // The eurosite bootstrap has no fn_get_order_info: this stand-in hands
    // the call to the test below, so a test sees WHICH order ids the hook read.
    if (!function_exists('fn_get_order_info')) {
        function fn_get_order_info(mixed $order_id): mixed
        {
            return \Tygh\Addons\Eurosite\Tests\Unit\Hooks\PlaceOrderPostArityTest::orderInfo($order_id);
        }
    }

    require_once dirname(__DIR__, 3) . '/func.php';
}

namespace Tygh\Addons\Eurosite\Tests\Unit\Hooks {

    use PHPUnit\Framework\Attributes\CoversNothing;
    use PHPUnit\Framework\TestCase;

    /**
     * REGRESSION: fn_eurosite_place_order_post was declared ($order_id, $cart)
     * after the 'place_order' hook's argument order. CS-Cart 4.20 fires
     * place_order_post as
     *
     *     ($cart, $auth, $action, $issuer_id, $parent_order_id, $order_id,
     *      $order_status, $short_order_data, $notification_rules)
     *
     * so the hook took the cart for the order id and walked the cart's VALUES
     * as order ids: the placed order's bookings were never submitted. PHPStan
     * cannot see fn_set_hook's dynamic dispatch, so the signature and both
     * argument orders are pinned here.
     */
    #[CoversNothing]
    final class PlaceOrderPostArityTest extends TestCase
    {
        /** @var list<mixed> order ids fn_get_order_info() was asked for */
        private static array $orderInfoCalls = [];

        /** @var list<int> order ids whose lookup throws */
        private static array $failingOrders = [];

        /**
         * fn_get_order_info() stand-in: records the id and answers "no such
         * order", so the hook stops before the submission service.
         */
        public static function orderInfo(mixed $orderId): mixed
        {
            self::$orderInfoCalls[] = $orderId;
            if (in_array($orderId, self::$failingOrders, true)) {
                throw new \RuntimeException('database gone');
            }

            return [];
        }

        protected function setUp(): void
        {
            self::$orderInfoCalls = [];
            self::$failingOrders = [];
        }

        protected function tearDown(): void
        {
            self::$failingOrders = [];
        }

        /**
         * fn_set_hook passes the core's arguments by reference and as many as
         * the build has: every slot must be optional and by-reference, and
         * there must be room for all nine of 4.20's.
         */
        public function testSignatureAcceptsEitherArgumentOrder(): void
        {
            $fn = new \ReflectionFunction('fn_eurosite_place_order_post');

            self::assertSame(0, $fn->getNumberOfRequiredParameters(), 'no argument may be required');
            self::assertGreaterThanOrEqual(9, $fn->getNumberOfParameters(), 'CS-Cart 4.20 passes nine arguments');
            foreach ($fn->getParameters() as $param) {
                self::assertTrue($param->isPassedByReference(), "\${$param->getName()} must be by-reference");
                self::assertTrue($param->isOptional(), "\${$param->getName()} must be optional");
            }
        }

        public function testCsCart420OrderLooksUpOnlyTheOrderInTheSixthArgument(): void
        {
            // user_id and a non-empty products array are exactly the values
            // the old signature mistook for order ids (7, and (int) [..] = 1).
            $cart = ['products' => [3044526915 => ['product_id' => 12]], 'user_id' => 7];
            $auth = ['user_id' => 7];
            $action = '';
            $issuerId = null;
            $parentOrderId = 0;
            $orderId = 1234;
            $orderStatus = 'O';
            $shortOrderData = ['status' => 'O'];
            $notificationRules = [];

            \fn_eurosite_place_order_post(
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

            self::assertSame([1234], self::$orderInfoCalls);
        }

        /**
         * The old order with Multi-Vendor's id list: every order is handled,
         * parent first, as before.
         */
        public function testOldArgumentOrderHandlesEveryOrderOfAMultiVendorList(): void
        {
            $orderId = [50, 51];
            $action = '';
            $orderStatus = 'O';
            $cart = null;
            $auth = [];

            \fn_eurosite_place_order_post($orderId, $action, $orderStatus, $cart, $auth);

            self::assertSame([50, 51], self::$orderInfoCalls);
        }

        public function testNoOrderIdDoesNothing(): void
        {
            \fn_eurosite_place_order_post();

            $cart = ['products' => []];
            $auth = [];
            \fn_eurosite_place_order_post($cart, $auth);

            self::assertSame([], self::$orderInfoCalls);
        }

        /**
         * This runs inside the customer's "Place order" request: nothing may
         * escape into checkout, and one failing order does not stop the next.
         */
        public function testAFailureNeverEscapesAndTheNextOrderStillRuns(): void
        {
            self::$failingOrders = [50];
            $orderId = [50, 51];
            $action = '';
            $orderStatus = 'O';

            \fn_eurosite_place_order_post($orderId, $action, $orderStatus);

            self::assertSame([50, 51], self::$orderInfoCalls);
        }
    }
}
