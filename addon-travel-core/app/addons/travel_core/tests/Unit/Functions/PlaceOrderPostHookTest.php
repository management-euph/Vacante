<?php

declare(strict_types=1);

namespace {
    if (!defined('BOOTSTRAP')) {
        define('BOOTSTRAP', true);
    }

    // The travel_core bootstrap has no fn_get_order_info: this stand-in hands
    // the call to the test below, so a test sees WHICH order id the hook read.
    if (!function_exists('fn_get_order_info')) {
        function fn_get_order_info(mixed $order_id): mixed
        {
            return \Tygh\Addons\TravelCore\Tests\Unit\Functions\PlaceOrderPostHookTest::orderInfo($order_id);
        }
    }

    require_once dirname(__DIR__, 3) . '/hooks/order_hooks.php';
}

namespace Tygh\Addons\TravelCore\Tests\Unit\Functions {

    use PHPUnit\Framework\Attributes\CoversNothing;
    use PHPUnit\Framework\TestCase;
    use Tygh\Addons\TravelCore\Services\DepositCartLine;

    /**
     * REGRESSION: fn_travel_core_place_order_post was declared in the
     * 'place_order' hook's argument order ($order_id, $action, $order_status,
     * $cart, $auth). CS-Cart 4.20 fires place_order_post as
     *
     *     ($cart, $auth, $action, $issuer_id, $parent_order_id, $order_id,
     *      $order_status, $short_order_data, $notification_rules)
     *
     * so the hook read the cart as the order id, got 0, and a deposit order's
     * balance was never recorded. PHPStan cannot see fn_set_hook's dynamic
     * dispatch, so the signature and both argument orders are pinned here.
     */
    #[CoversNothing]
    final class PlaceOrderPostHookTest extends TestCase
    {
        /** @var list<mixed> order ids fn_get_order_info() was asked for */
        private static array $orderInfoCalls = [];

        private static ?\Throwable $orderInfoFailure = null;

        /**
         * fn_get_order_info() stand-in: records the id and answers "no such
         * order", so the hook stops before BalanceService touches the DB.
         */
        public static function orderInfo(mixed $orderId): mixed
        {
            self::$orderInfoCalls[] = $orderId;
            if (self::$orderInfoFailure !== null) {
                throw self::$orderInfoFailure;
            }

            return false;
        }

        protected function setUp(): void
        {
            self::$orderInfoCalls = [];
            self::$orderInfoFailure = null;
        }

        protected function tearDown(): void
        {
            self::$orderInfoFailure = null;
        }

        /** @return array<string, mixed> */
        private static function depositCart(): array
        {
            return [
                'products' => [
                    3044526915 => ['product_id' => 12, 'extra' => [DepositCartLine::EXTRA_KEY => ['percent' => 30]]],
                ],
            ];
        }

        /**
         * fn_set_hook passes the core's arguments by reference and as many as
         * the build has: every slot must be optional and by-reference, and
         * there must be room for all nine of 4.20's.
         */
        public function testSignatureAcceptsEitherArgumentOrder(): void
        {
            $fn = new \ReflectionFunction('fn_travel_core_place_order_post');

            self::assertSame(0, $fn->getNumberOfRequiredParameters(), 'no argument may be required');
            self::assertGreaterThanOrEqual(9, $fn->getNumberOfParameters(), 'CS-Cart 4.20 passes nine arguments');
            foreach ($fn->getParameters() as $param) {
                self::assertTrue($param->isPassedByReference(), "\${$param->getName()} must be by-reference");
                self::assertTrue($param->isOptional(), "\${$param->getName()} must be optional");
            }
        }

        public function testCsCart420OrderReadsTheOrderIdFromTheSixthArgument(): void
        {
            $cart = self::depositCart();
            $auth = ['user_id' => 7];
            $action = '';
            $issuerId = null;
            $parentOrderId = 0;
            $orderId = 1234;
            $orderStatus = 'O';
            $shortOrderData = ['status' => 'O'];
            $notificationRules = [];

            \fn_travel_core_place_order_post(
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
            self::assertSame(self::depositCart(), $cart, 'the hook must not rewrite the cart it observes');
        }

        public function testOldArgumentOrderStillWorks(): void
        {
            $orderId = 1234;
            $action = '';
            $orderStatus = 'O';
            $cart = self::depositCart();
            $auth = [];

            \fn_travel_core_place_order_post($orderId, $action, $orderStatus, $cart, $auth);

            self::assertSame([1234], self::$orderInfoCalls);
        }

        public function testAnOrderWithoutADepositIsNotLookedUp(): void
        {
            $cart = ['products' => [1 => ['product_id' => 12, 'extra' => []]]];
            $auth = [];
            $action = '';
            $issuerId = null;
            $parentOrderId = 0;
            $orderId = 1234;

            \fn_travel_core_place_order_post($cart, $auth, $action, $issuerId, $parentOrderId, $orderId);

            self::assertSame([], self::$orderInfoCalls);
        }

        /**
         * Payment callbacks re-fire the hook without a cart: nothing to record,
         * and a null cart must not reach the array-typed deposit check.
         */
        public function testNoCartAndNoArgumentsAreQuietNoOps(): void
        {
            $orderId = 1234;
            $action = '';
            $orderStatus = 'P';
            $cart = null;

            \fn_travel_core_place_order_post($orderId, $action, $orderStatus, $cart);
            \fn_travel_core_place_order_post();

            self::assertSame([], self::$orderInfoCalls);
        }

        /**
         * This runs inside the customer's "Place order" request: nothing may
         * escape into checkout.
         */
        public function testAFailureNeverEscapes(): void
        {
            self::$orderInfoFailure = new \RuntimeException('database gone');
            $cart = self::depositCart();
            $auth = [];
            $action = '';
            $issuerId = null;
            $parentOrderId = 0;
            $orderId = 1234;

            \fn_travel_core_place_order_post($cart, $auth, $action, $issuerId, $parentOrderId, $orderId);

            self::assertSame([1234], self::$orderInfoCalls, 'the lookup was reached and failed quietly');
        }
    }
}
