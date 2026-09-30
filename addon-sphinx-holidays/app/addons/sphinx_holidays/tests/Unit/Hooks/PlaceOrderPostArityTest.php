<?php

declare(strict_types=1);

namespace {
    // func.php holds the fn_* shell CS-Cart dispatches to; load it in the
    // GLOBAL namespace, as CS-Cart does.
    if (!defined('BOOTSTRAP')) {
        define('BOOTSTRAP', true);
    }

    // The sphinx bootstrap has no fn_get_order_info: this stand-in hands the
    // call to the test below, so a test sees WHICH order id the hook read.
    if (!function_exists('fn_get_order_info')) {
        function fn_get_order_info(mixed $order_id): mixed
        {
            return \Tygh\Addons\SphinxHolidays\Tests\Unit\Hooks\PlaceOrderPostArityTest::orderInfo($order_id);
        }
    }

    require_once dirname(__DIR__, 3) . '/func.php';
}

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Hooks {

    use PHPUnit\Framework\Attributes\CoversNothing;
    use PHPUnit\Framework\TestCase;
    use Tygh\Addons\SphinxHolidays\Tests\Support\DbStub;

    /**
     * REGRESSION: fn_sphinx_holidays_place_order_post was declared in the
     * 'place_order' hook's argument order ($order_id, $action, $order_status,
     * $cart, $auth). CS-Cart 4.20 fires place_order_post as
     *
     *     ($cart, $auth, $action, $issuer_id, $parent_order_id, $order_id,
     *      $order_status, $short_order_data, $notification_rules)
     *
     * so the hook read the cart as the order id, got 0 and returned: the
     * Sphinx booking was never submitted nor linked to the order. PHPStan
     * cannot see fn_set_hook's dynamic dispatch, so the signature and both
     * argument orders are pinned here.
     */
    #[CoversNothing]
    final class PlaceOrderPostArityTest extends TestCase
    {
        /** @var list<mixed> order ids fn_get_order_info() was asked for */
        private static array $orderInfoCalls = [];

        private static ?\Throwable $orderInfoFailure = null;

        /**
         * fn_get_order_info() stand-in: records the id and answers with an
         * order without products, so the link self-heal links nothing.
         */
        public static function orderInfo(mixed $orderId): mixed
        {
            self::$orderInfoCalls[] = $orderId;
            if (self::$orderInfoFailure !== null) {
                throw self::$orderInfoFailure;
            }

            return [];
        }

        protected function setUp(): void
        {
            DbStub::reset();
            self::$orderInfoCalls = [];
            self::$orderInfoFailure = null;
        }

        protected function tearDown(): void
        {
            DbStub::reset();
            self::$orderInfoFailure = null;
        }

        /**
         * fn_set_hook passes the core's arguments by reference and as many as
         * the build has: every slot must be optional and by-reference, and
         * there must be room for all nine of 4.20's.
         */
        public function testSignatureAcceptsEitherArgumentOrder(): void
        {
            $fn = new \ReflectionFunction('fn_sphinx_holidays_place_order_post');

            self::assertSame(0, $fn->getNumberOfRequiredParameters(), 'no argument may be required');
            self::assertGreaterThanOrEqual(9, $fn->getNumberOfParameters(), 'CS-Cart 4.20 passes nine arguments');
            foreach ($fn->getParameters() as $param) {
                self::assertTrue($param->isPassedByReference(), "\${$param->getName()} must be by-reference");
                self::assertTrue($param->isOptional(), "\${$param->getName()} must be optional");
            }
        }

        /**
         * A cart with no sphinx line takes the submission path without an API
         * call; its closing self-heal shows which order the hook worked on.
         */
        public function testCsCart420OrderWorksOnTheOrderInTheSixthArgument(): void
        {
            $cart = ['products' => [3044526915 => ['product_id' => 12, 'extra' => []]], 'user_data' => []];
            $auth = ['user_id' => 7];
            $action = '';
            $issuerId = null;
            $parentOrderId = 0;
            $orderId = 1234;
            $orderStatus = 'O';
            $shortOrderData = ['status' => 'O'];
            $notificationRules = [];

            \fn_sphinx_holidays_place_order_post(
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
         * Payment callbacks and status re-triggers come without a cart: the
         * bookings are still linked from the stored order.
         */
        public function testCsCart420OrderWithoutACartStillLinks(): void
        {
            $cart = [];
            $auth = [];
            $action = '';
            $issuerId = null;
            $parentOrderId = 0;
            $orderId = 1234;

            \fn_sphinx_holidays_place_order_post($cart, $auth, $action, $issuerId, $parentOrderId, $orderId);

            self::assertSame([1234], self::$orderInfoCalls);
        }

        public function testOldArgumentOrderWithAMultiVendorIdListUsesTheParentOrder(): void
        {
            $orderId = [50, 51];
            $action = '';
            $orderStatus = 'O';
            $cart = null;
            $auth = [];

            \fn_sphinx_holidays_place_order_post($orderId, $action, $orderStatus, $cart, $auth);

            self::assertSame([50], self::$orderInfoCalls);
        }

        public function testNoOrderIdDoesNothing(): void
        {
            \fn_sphinx_holidays_place_order_post();

            $cart = ['products' => []];
            $auth = [];
            \fn_sphinx_holidays_place_order_post($cart, $auth);

            self::assertSame([], self::$orderInfoCalls);
        }

        /**
         * This runs inside the customer's "Place order" request: nothing may
         * escape into checkout.
         */
        public function testAFailureNeverEscapes(): void
        {
            self::$orderInfoFailure = new \RuntimeException('database gone');
            $cart = [];
            $auth = [];
            $action = '';
            $issuerId = null;
            $parentOrderId = 0;
            $orderId = 1234;

            \fn_sphinx_holidays_place_order_post($cart, $auth, $action, $issuerId, $parentOrderId, $orderId);

            self::assertSame([1234], self::$orderInfoCalls, 'the lookup was reached and failed quietly');
        }
    }
}
