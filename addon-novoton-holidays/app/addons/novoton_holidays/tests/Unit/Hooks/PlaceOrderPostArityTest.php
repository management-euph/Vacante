<?php

declare(strict_types=1);

namespace {
    // Load the procedural hook functions under test in the GLOBAL namespace,
    // as CS-Cart loads them (pattern: LinkOrderBookingsTest).
    if (!defined('BOOTSTRAP')) {
        define('BOOTSTRAP', true);
    }

    require_once dirname(__DIR__, 3) . '/src/Services/ServiceLoader.php';
    require_once dirname(__DIR__, 3) . '/hooks/order_hooks.php';
}

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Hooks {

    use PHPUnit\Framework\Attributes\CoversNothing;
    use PHPUnit\Framework\TestCase;
    use Tygh\Addons\NovotonHolidays\Services\BookingSubmissionServiceInterface;
    use Tygh\Addons\NovotonHolidays\Services\Container;
    use Tygh\Addons\NovotonHolidays\Tests\Support\DbStub;

    /**
     * REGRESSION: fn_novoton_holidays_place_order_post was declared in the
     * 'place_order' hook's argument order ($order_id, $action, $order_status,
     * $cart, $auth). CS-Cart 4.20 fires place_order_post as
     *
     *     ($cart, $auth, $action, $issuer_id, $parent_order_id, $order_id,
     *      $order_status, $short_order_data, $notification_rules)
     *
     * so the hook read the cart as the order id, got 0 and returned: a paid
     * order's Novoton booking was never sent to the API nor linked to the
     * order. PHPStan cannot see fn_set_hook's dynamic dispatch, so the
     * signature and both argument orders are pinned here.
     */
    #[CoversNothing]
    final class PlaceOrderPostArityTest extends TestCase
    {
        /** @var list<array{int, array<string, mixed>}> submitOrder() calls */
        private array $submitted = [];

        /** @var list<int> order ids the link self-heal looked up */
        private array $linkedLookups = [];

        private bool $submitThrows = false;

        protected function setUp(): void
        {
            DbStub::reset();
            $this->submitted = [];
            $this->linkedLookups = [];
            $this->submitThrows = false;

            // The self-heal's only I/O entry point: record the id, answer
            // "no products" so it links nothing.
            DbStub::$getOrderInfo = function (int $order_id): array {
                $this->linkedLookups[] = $order_id;

                return [];
            };

            $test = $this;
            $service = new class ($test) implements BookingSubmissionServiceInterface {
                public function __construct(private readonly PlaceOrderPostArityTest $test)
                {
                }

                public function submitOrder(int $orderId, array $cart): void
                {
                    $this->test->recordSubmission($orderId, $cart);
                }
            };
            $container = new Container();
            $container->override('bookingSubmissionService', static fn (): BookingSubmissionServiceInterface => $service);
            Container::setInstance($container);
        }

        protected function tearDown(): void
        {
            Container::setInstance(null);
            DbStub::reset();
        }

        /**
         * @param array<string, mixed> $cart
         */
        public function recordSubmission(int $orderId, array $cart): void
        {
            $this->submitted[] = [$orderId, $cart];
            if ($this->submitThrows) {
                throw new \RuntimeException('Novoton API unreachable');
            }
        }

        /** @return array<string, mixed> */
        private static function cart(): array
        {
            return [
                'products' => [
                    3044526915 => ['product_id' => 12, 'extra' => ['novoton_booking' => true, 'novoton_booking_id' => 1]],
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
            $fn = new \ReflectionFunction('fn_novoton_holidays_place_order_post');

            self::assertSame(0, $fn->getNumberOfRequiredParameters(), 'no argument may be required');
            self::assertGreaterThanOrEqual(9, $fn->getNumberOfParameters(), 'CS-Cart 4.20 passes nine arguments');
            foreach ($fn->getParameters() as $param) {
                self::assertTrue($param->isPassedByReference(), "\${$param->getName()} must be by-reference");
                self::assertTrue($param->isOptional(), "\${$param->getName()} must be optional");
            }
        }

        public function testCsCart420OrderSubmitsAndLinksTheOrderInTheSixthArgument(): void
        {
            $cart = self::cart();
            $auth = ['user_id' => 7];
            $action = '';
            $issuerId = null;
            $parentOrderId = 0;
            $orderId = 1234;
            $orderStatus = 'O';
            $shortOrderData = ['status' => 'O'];
            $notificationRules = [];

            \fn_novoton_holidays_place_order_post(
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

            self::assertSame([[1234, self::cart()]], $this->submitted);
            self::assertSame([1234], $this->linkedLookups, 'the self-heal ran for the same order');
        }

        public function testOldArgumentOrderWithAMultiVendorIdListUsesTheParentOrder(): void
        {
            $orderId = [50, 51];
            $action = '';
            $orderStatus = 'O';
            $cart = self::cart();
            $auth = [];

            \fn_novoton_holidays_place_order_post($orderId, $action, $orderStatus, $cart, $auth);

            self::assertSame([[50, self::cart()]], $this->submitted);
            self::assertSame([50], $this->linkedLookups);
        }

        /**
         * Payment callbacks and status re-triggers come without a cart: no
         * submission, but the bookings are still linked from the stored order.
         */
        public function testWithoutACartTheBookingsAreOnlyLinked(): void
        {
            $cart = [];
            $auth = ['user_id' => 7];
            $action = '';
            $issuerId = null;
            $parentOrderId = 0;
            $orderId = 1234;

            \fn_novoton_holidays_place_order_post($cart, $auth, $action, $issuerId, $parentOrderId, $orderId);

            self::assertSame([], $this->submitted);
            self::assertSame([1234], $this->linkedLookups);
        }

        public function testNoOrderIdDoesNothing(): void
        {
            \fn_novoton_holidays_place_order_post();

            $cart = self::cart();
            $auth = [];
            \fn_novoton_holidays_place_order_post($cart, $auth);

            self::assertSame([], $this->submitted);
            self::assertSame([], $this->linkedLookups);
        }

        /**
         * This runs inside the customer's "Place order" request: a failing
         * submission may not escape into checkout, and the booking must still
         * be linked so the admin can resubmit it.
         */
        public function testASubmissionFailureNeverEscapesAndStillLinks(): void
        {
            $this->submitThrows = true;
            $cart = self::cart();
            $auth = [];
            $action = '';
            $issuerId = null;
            $parentOrderId = 0;
            $orderId = 1234;

            \fn_novoton_holidays_place_order_post($cart, $auth, $action, $issuerId, $parentOrderId, $orderId);

            self::assertCount(1, $this->submitted, 'the submission was reached and failed');
            self::assertSame([1234], $this->linkedLookups, 'the self-heal still ran');
        }

        public function testALinkFailureNeverEscapes(): void
        {
            DbStub::$getOrderInfo = function (int $order_id): array {
                $this->linkedLookups[] = $order_id;
                throw new \RuntimeException('database gone');
            };
            $orderId = 1234;
            $action = '';
            $orderStatus = 'P';
            $cart = null;

            \fn_novoton_holidays_place_order_post($orderId, $action, $orderStatus, $cart);

            self::assertSame([], $this->submitted);
            self::assertSame([1234], $this->linkedLookups, 'the link was reached and failed quietly');
        }
    }
}
