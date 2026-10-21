<?php

declare(strict_types=1);

namespace {
    // CS-Cart's notification and order lookups, absent from the sphinx
    // bootstrap. fn_get_order_info answers like PlaceOrderPostArityTest's
    // stand-in (whichever file loads first defines it): an order without
    // products, so the closing link self-heal links nothing.
    if (!function_exists('fn_set_notification')) {
        function fn_set_notification(string $type, mixed $title, mixed $message): void
        {
            \Tygh\Addons\SphinxHolidays\Tests\Unit\Hooks\OrderHooksBindingTest::$notifications[] = [$type, (string) $message];
        }
    }
    if (!function_exists('fn_get_order_info')) {
        function fn_get_order_info(mixed $order_id): mixed
        {
            return \Tygh\Addons\SphinxHolidays\Tests\Unit\Hooks\PlaceOrderPostArityTest::orderInfo($order_id);
        }
    }
}

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\Hooks {

    use PHPUnit\Framework\Attributes\CoversClass;
    use PHPUnit\Framework\TestCase;
    use Tygh\Addons\SphinxHolidays\Hooks\OrderHooks;
    use Tygh\Addons\SphinxHolidays\Repository\SphinxBookingRepository;
    use Tygh\Addons\SphinxHolidays\Services\CheckoutBookingBinder;
    use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;
    use Tygh\Addons\SphinxHolidays\Services\Container;
    use Tygh\Addons\SphinxHolidays\Services\PreOrderPriceVerifier;
    use Tygh\Addons\SphinxHolidays\SphinxApi;
    use Tygh\Addons\TravelCore\Services\DepositCartLine;
    use Tygh\Registry;

    /**
     * Audit H2, hook side. pre_place_order removes a line not bound to its
     * booking (never deleting the row it names: it may be another visitor's)
     * and blocks the order; a price correction is written to the booking row
     * too. place_order_post books only a row that exists and is this
     * visitor's, with the row's own offer_id — a forged travel_booking_id
     * used to read order_id 0 from a null row and book extra.offer_id at
     * order creation, before any payment.
     */
    #[CoversClass(OrderHooks::class)]
    final class OrderHooksBindingTest extends TestCase
    {
        /** @var list<array{0: string, 1: string}> */
        public static array $notifications = [];

        /** @var list<array{string, mixed}> repository writes and API bookings, in order */
        public static array $log = [];

        /** @var array<int, array<string, mixed>> */
        public static array $rows = [];

        /** @var list<array<string, mixed>> book payloads sent to the API */
        public static array $payloads = [];

        protected function setUp(): void
        {
            $_SESSION = ['auth' => ['user_id' => 5]];
            self::$notifications = [];
            self::$log = [];
            self::$payloads = [];
            PlaceOrderPostArityTest::$orderInfoAnswer = [];
            self::$rows = [
                10 => [
                    'booking_id' => 10, 'order_id' => 0, 'user_id' => 5, 'session_id' => '',
                    'product_id' => 110, 'offer_id' => 'offer-luxury', 'status' => 'pending',
                    'total_price' => 1500.0, 'base_price' => 1500.0, 'currency' => 'EUR',
                    'room_type' => 'Double Room', 'guests_data' => '{"holder":"Ana Pop"}',
                ],
                20 => [
                    'booking_id' => 20, 'order_id' => 0, 'user_id' => 77, 'session_id' => 'victim',
                    'product_id' => 120, 'offer_id' => 'offer-victim', 'status' => 'pending',
                    'total_price' => 900.0, 'base_price' => 900.0, 'currency' => 'EUR',
                ],
            ];
            Registry::set('addons.sphinx_holidays', null);
            ConfigProvider::resetSettingsCache();
            Container::reset();
            Container::override('bookingRepository', static fn (): SphinxBookingRepository => new class extends SphinxBookingRepository {
                public function findById(int $booking_id): ?array
                {
                    return OrderHooksBindingTest::$rows[$booking_id] ?? null;
                }

                public function update(int $booking_id, array $data): bool
                {
                    OrderHooksBindingTest::$log[] = ['update:' . $booking_id, $data];

                    return true;
                }

                public function delete(int $booking_id): bool
                {
                    OrderHooksBindingTest::$log[] = ['delete:' . $booking_id, null];

                    return true;
                }
            });
            Container::override('api', static fn (): SphinxApi => new class extends SphinxApi {
                public function __construct()
                {
                }

                public function bookHotel(array $bookingData): ?array
                {
                    OrderHooksBindingTest::$log[] = ['bookHotel', $bookingData['offer_id'] ?? null];
                    OrderHooksBindingTest::$payloads[] = $bookingData;

                    return ['booking_confirmation_number' => 'V-1'];
                }

                public function bookCircuit(array $bookingData): ?array
                {
                    OrderHooksBindingTest::$log[] = ['bookCircuit', $bookingData['offer_id'] ?? null];
                    OrderHooksBindingTest::$payloads[] = $bookingData;

                    return ['booking_confirmation_number' => 'V-2'];
                }
            });
            // Fresh Silent Sync entry: bound lines need no provider call.
            $_SESSION['sphinx_price_cache'] = [md5('offer-luxury') => ['api_price_raw' => 1500.0, 'timestamp' => time()]];
        }

        protected function tearDown(): void
        {
            $_SESSION = [];
            PlaceOrderPostArityTest::$orderInfoAnswer = [];
            Container::reset();
            Registry::set('addons.sphinx_holidays', null);
            Registry::set('addons.travel_core.deposit_enabled', null);
            ConfigProvider::resetSettingsCache();
        }

        /**
         * @param array<string, mixed> $extra
         * @param array<string, mixed> $line
         * @return array<string, mixed>
         */
        private static function line(int $bookingId, string $offerId, float $price, array $extra = [], array $line = []): array
        {
            return $line + [
                'product_id' => 100 + $bookingId,
                'amount' => 1,
                'price' => $price,
                'stored_price' => 'Y',
                'extra' => $extra + [
                    'sphinx_booking' => 1,
                    'travel_booking_id' => $bookingId,
                    'offer_id' => $offerId,
                    'total_price' => $price,
                    'hotel_name' => 'Hotel ' . $offerId,
                    'currency' => 'EUR',
                ],
            ];
        }

        /** @return list<string> */
        private static function actions(): array
        {
            return array_map(static fn (array $entry): string => $entry[0], self::$log);
        }

        public function testAnUnboundLineIsRemovedTheOrderBlockedAndNoRowDeleted(): void
        {
            $cart = ['products' => [
                7 => self::line(10, 'offer-luxury', 1500.0),
                8 => self::line(20, 'offer-victim', 9.0, [], ['product_id' => 3]),
            ]];
            $allow = true;

            OrderHooks::prePlaceOrder($cart, $allow);

            self::assertFalse($allow);
            self::assertIsArray($cart['products']);
            self::assertSame([7], array_keys($cart['products']));
            self::assertSame([], self::actions(), "the victim's booking row stays");
            self::assertSame('W', self::$notifications[0][0] ?? null);
            // Not "no longer available": the offer may well be.
            self::assertSame('sphinx_holidays.booking_not_bound', self::$notifications[0][1] ?? null);
        }

        /**
         * An offer gone at the provider: the line leaves the cart and its
         * stranded cart-stage row is deleted — never a row on an order, which
         * is a live booking (a stale "unavailable" for a retried payment).
         */
        public function testAnUnavailableOfferDeletesOnlyAStrandedRow(): void
        {
            self::$rows[30] = ['booking_id' => 30, 'order_id' => 77, 'status' => 'confirmed'] + self::$rows[10];
            Container::override('preOrderPriceVerifier', static fn (): PreOrderPriceVerifier => new class extends PreOrderPriceVerifier {
                #[\Override]
                public function verify(array $cart): array
                {
                    return [
                        'allow' => true, 'corrections' => [], 'notifications' => [], 'rejected' => [], 'reconfirm' => false,
                        'unavailable' => [
                            7 => ['offer_id' => 'offer-luxury', 'hotel_name' => 'A', 'booking_id' => 10],
                            8 => ['offer_id' => 'offer-luxury', 'hotel_name' => 'B', 'booking_id' => 30],
                        ],
                    ];
                }
            });
            $cart = ['products' => [
                7 => self::line(10, 'offer-luxury', 1500.0),
                8 => self::line(30, 'offer-luxury', 1500.0),
                9 => ['product_id' => 1, 'amount' => 1, 'price' => 10.0, 'extra' => []],
            ]];
            $allow = true;

            OrderHooks::prePlaceOrder($cart, $allow);

            self::assertSame(['delete:10'], self::actions());
            self::assertIsArray($cart['products']);
            self::assertSame([9], array_keys($cart['products']));
        }

        public function testABoundLineAtItsBookingPriceGoesThrough(): void
        {
            $cart = ['products' => [7 => self::line(10, 'offer-luxury', 1500.0)]];
            $allow = true;

            OrderHooks::prePlaceOrder($cart, $allow);

            self::assertTrue($allow);
            self::assertSame([], self::$notifications);
        }

        public function testALineUnderchargingItsBookingIsRepricedFromTheRowAndReConfirmed(): void
        {
            // The line's own currency copy says USD; the row's (EUR) is used.
            $cart = ['products' => [7 => self::line(10, 'offer-luxury', 45.0, ['currency' => 'USD'], ['amount' => 3])]];
            $allow = true;

            OrderHooks::prePlaceOrder($cart, $allow);

            self::assertFalse($allow, 'the customer re-confirms the corrected total');
            self::assertIsArray($cart['products'][7] ?? null);
            self::assertSame(1500.0, $cart['products'][7]['price'] ?? null);
            self::assertSame(1, $cart['products'][7]['amount'] ?? null);
            self::assertSame([], self::$log, 'corrected TO the row price: the row is left as it is');

            // Re-confirmed, the corrected line now goes through.
            $allow = true;
            OrderHooks::prePlaceOrder($cart, $allow);
            self::assertTrue($allow);
        }

        /**
         * Review of H2, round 2: a deposit line saving balance 0 charged the
         * genuine deposit and passed; travel_core then recorded nothing owed.
         * It is repriced to the row's amounts (the balance owed is back) and
         * re-confirmed, and the row is left as it is.
         */
        public function testADepositLineSavingNoBalanceIsRepricedToTheRowsAmounts(): void
        {
            // 30% due today, the rest in 90 days: add_to_cart's 0.3 plan today.
            $today = date('Y-m-d');
            $due = date('Y-m-d', (int) strtotime($today . ' +90 days'));
            self::$rows[10]['payment_terms_json'] = '{"is_loaded":true,"rules":[{"until":"' . $today . '","value":450},{"until":"' . $due . '","value":1500}]}';
            $plan = ['ratio' => 0.3, 'balance_due' => $due, 'full' => 1500.0, 'deposit' => 450.0, 'balance' => 0.0];
            $cart = ['products' => [7 => self::line(10, 'offer-luxury', 450.0, [DepositCartLine::EXTRA_KEY => $plan])]];
            $allow = true;

            OrderHooks::prePlaceOrder($cart, $allow);

            self::assertFalse($allow, 'the customer re-confirms');
            self::assertIsArray($cart['products'][7] ?? null);
            self::assertSame(450.0, $cart['products'][7]['price'] ?? null);
            self::assertSame(
                ['ratio' => 0.3, 'balance_due' => $due, 'full' => 1500.0, 'deposit' => 450.0, 'balance' => 1050.0],
                DepositCartLine::amounts($cart['products'][7]),
            );
            self::assertSame([], self::$log, 'the row keeps its price');

            $allow = true;
            OrderHooks::prePlaceOrder($cart, $allow);
            self::assertTrue($allow);
        }

        /**
         * Deposits switched off while a deposit line sits in the cart: the
         * line is repriced to the full price, not removed.
         */
        public function testADepositLineIsRepricedToTheFullPriceWhenDepositsAreOff(): void
        {
            Registry::set('addons.travel_core.deposit_enabled', 'N');
            $plan = ['ratio' => 0.3, 'balance_due' => '2027-01-10', 'full' => 1500.0, 'deposit' => 450.0, 'balance' => 1050.0];
            $cart = ['products' => [7 => self::line(10, 'offer-luxury', 450.0, [DepositCartLine::EXTRA_KEY => $plan])]];
            $allow = true;

            OrderHooks::prePlaceOrder($cart, $allow);

            self::assertFalse($allow);
            self::assertIsArray($cart['products'][7] ?? null);
            self::assertSame(1500.0, $cart['products'][7]['price'] ?? null);
            self::assertNull(DepositCartLine::plan($cart['products'][7]), 'paid in full');
            self::assertNotContains('sphinx_holidays.booking_not_bound', array_column(self::$notifications, 1));

            $allow = true;
            OrderHooks::prePlaceOrder($cart, $allow);
            self::assertTrue($allow);
        }

        public function testAProviderCorrectionIsWrittenToTheBookingRow(): void
        {
            $_SESSION['sphinx_price_cache'] = [md5('offer-luxury') => ['api_price_raw' => 1800.0, 'timestamp' => time()]];
            $cart = ['products' => [7 => self::line(10, 'offer-luxury', 1500.0)]];
            $allow = true;

            OrderHooks::prePlaceOrder($cart, $allow);

            self::assertFalse($allow);
            self::assertSame([['update:10', ['total_price' => 1800.0, 'base_price' => 1800.0]]], self::$log);
        }

        public function testPlaceOrderPostBooksNothingForAForgedBookingId(): void
        {
            OrderHooks::placeOrderPost(501, ['products' => [7 => self::line(999, 'offer-luxury', 45.0)]]);

            self::assertSame([], self::actions(), 'no link, no booking');
        }

        public function testPlaceOrderPostBooksNothingForAnotherVisitorsBooking(): void
        {
            OrderHooks::placeOrderPost(501, ['products' => [7 => self::line(20, 'offer-victim', 900.0, [], ['product_id' => 120])]]);

            self::assertSame([], self::actions());
        }

        public function testPlaceOrderPostBooksTheBoundRowsOwnOffer(): void
        {
            OrderHooks::placeOrderPost(501, ['products' => [7 => self::line(10, 'offer-luxury', 1500.0)]]);

            self::assertContains(['bookHotel', 'offer-luxury'], self::$log);
            self::assertSame('update:10', self::$log[0][0] ?? null, 'linked to the order first');
        }

        /**
         * The travellers and the endpoint are the row's: a line whose extra
         * was written could otherwise send another traveller list, or have
         * a circuit booked as a hotel.
         */
        public function testPlaceOrderPostSendsTheRowsTravellersToTheRowsEndpoint(): void
        {
            self::$rows[10]['room_type'] = 'circuit';
            $line = self::line(10, 'offer-luxury', 1500.0, ['booking_type' => 'hotel', 'guests_data' => '{"holder":"Forged Name"}']);

            OrderHooks::placeOrderPost(501, ['products' => [7 => $line]]);

            self::assertContains(['bookCircuit', 'offer-luxury'], self::$log);
            self::assertNotContains(['bookHotel', 'offer-luxury'], self::$log);
            self::assertSame(['holder' => 'Ana Pop'], self::$payloads[0]['guests'] ?? null);
        }

        /**
         * The closing self-heal linked any order_id 0 row an order item named:
         * a line place_order_post refused to book had its (another visitor's)
         * row attached to the order all the same.
         */
        public function testTheSelfHealNeverLinksARowPlaceOrderPostRefused(): void
        {
            $line = self::line(20, 'offer-victim', 900.0, [], ['product_id' => 120]);
            PlaceOrderPostArityTest::$orderInfoAnswer = ['products' => [7 => $line]];

            OrderHooks::placeOrderPost(501, ['products' => [7 => $line]]);

            self::assertSame([], self::actions());
        }

        /**
         * Without a cart (payment callbacks, re-triggers) the self-heal links
         * a row only when it is the item's booking: same offer and product.
         */
        public function testTheCartlessSelfHealLinksOnlyTheItemsOwnBooking(): void
        {
            self::$rows[11] = ['booking_id' => 11] + self::$rows[10];
            self::$rows[12] = ['booking_id' => 12] + self::$rows[10];
            PlaceOrderPostArityTest::$orderInfoAnswer = ['products' => [
                1 => self::line(10, 'offer-luxury', 1500.0, [], ['product_id' => 110]),
                2 => self::line(11, 'offer-other', 1500.0, [], ['product_id' => 110]),
                3 => self::line(12, 'offer-luxury', 1500.0, [], ['product_id' => 3]),
                4 => self::line(404, 'offer-luxury', 1500.0, [], ['product_id' => 110]),
            ]];

            OrderHooks::placeOrderPost(501, null);

            self::assertSame([['update:10', ['order_id' => 501]]], self::$log);
        }

        public function testPlaceOrderPostBooksNothingWhenTheLineNamesAnotherOffer(): void
        {
            OrderHooks::placeOrderPost(501, ['products' => [7 => self::line(10, 'offer-other', 1500.0)]]);

            self::assertSame([], self::actions());
        }

        public function testTheCartCalculationRemembersTheBoundBookingsForTheLogin(): void
        {
            $cart = ['products' => [
                7 => self::line(10, 'offer-luxury', 1500.0, [], ['base_price' => 1500.0]),
                8 => self::line(20, 'offer-victim', 900.0, [], ['product_id' => 120, 'base_price' => 900.0]),
            ]];

            OrderHooks::preserveStoredPrices($cart);

            self::assertSame([10], $_SESSION[CheckoutBookingBinder::SESSION_KEY] ?? null);

            // After a login that regenerated the session id, a guest row binds
            // through the remembered id.
            self::$rows[10]['user_id'] = 0;
            self::$rows[10]['session_id'] = 'pre-login';
            $verifier = new PreOrderPriceVerifier();
            self::assertSame([], $verifier->verify(['products' => [7 => self::line(10, 'offer-luxury', 1500.0)]])['rejected']);
        }
    }
}
