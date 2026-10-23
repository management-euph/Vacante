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

    if (!function_exists('fn_set_notification')) {
        /** Records CS-Cart notifications (same recorder as CalculateCartItemsTest). */
        function fn_set_notification(string $type, string $title, string $message = ''): void
        {
            $GLOBALS['__nvt_test_notifications'][] = [$type, $title, $message];
        }
    }
}

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Hooks {

    use PHPUnit\Framework\TestCase;
    use Tygh\Addons\NovotonHolidays\Helpers\BookingRoomAssembler;
    use Tygh\Addons\NovotonHolidays\Repository\BookingRepositoryInterface;
    use Tygh\Addons\NovotonHolidays\Services\CartBookingGuard;
    use Tygh\Addons\NovotonHolidays\Services\Container;
    use Tygh\Addons\NovotonHolidays\Services\PreOrderPriceVerifier;
    use Tygh\Addons\TravelCore\Contracts\PreOrderPriceVerifierInterface;
    use Tygh\Addons\TravelCore\Services\CurrencyService;

    /**
     * fn_novoton_holidays_pre_place_order applies the verifier's checkout
     * corrections:
     *   - each room's corrected price goes into rooms_data, on the cart line
     *     and on the booking row, next to total/base (novoton-money-9): the
     *     submission builds the booking records from those rooms, and left
     *     at the add_to_cart prices it wrote the pre-correction sum back, so
     *     a payment retry found the line and its booking out of step;
     *   - the booking row is written first; when that fails the line is left
     *     as it was and the order click is refused;
     *   - a line the cart guard removed during this same request refuses the
     *     click as well.
     */
    final class PrePlaceOrderTest extends TestCase
    {
        /** @var array<string, mixed> the verifier's answer */
        private array $verdict = [];

        /** @var list<array<string, mixed>> update() calls on the booking repository */
        private array $updates = [];

        private bool $updateFails = false;

        protected function setUp(): void
        {
            $_SESSION = [];
            $GLOBALS['__nvt_test_notifications'] = [];
            $this->updates = [];
            $this->updateFails = false;
            $this->verdict = ['allow' => true, 'corrections' => [], 'notifications' => [], 'reconfirm' => false];
            CartBookingGuard::takeRemoval();

            $verifier = new class ($this->verdict) implements PreOrderPriceVerifierInterface {
                /** @param array<string, mixed> $verdict */
                public function __construct(private array &$verdict)
                {
                }

                /**
                 * @param array<string, mixed> $cart
                 * @return array<string, mixed>
                 */
                public function verify(array $cart): array
                {
                    return $this->verdict;
                }
            };
            $bookings = $this->createStub(BookingRepositoryInterface::class);
            $bookings->method('update')->willReturnCallback(function (int $id, array $data): bool {
                if ($this->updateFails) {
                    throw new \RuntimeException('deadlock');
                }
                $this->updates[] = ['booking_id' => $id] + $data;

                return true;
            });

            Container::setInstance(null);
            $container = Container::getInstance();
            $container->override('preOrderPriceVerifier', static fn (): PreOrderPriceVerifierInterface => $verifier);
            $container->override('bookingRepository', static fn (): BookingRepositoryInterface => $bookings);
            $container->override('currencyService', static fn (): CurrencyService => new CurrencyService('EUR'));
        }

        protected function tearDown(): void
        {
            $_SESSION = [];
            Container::setInstance(null);
            CartBookingGuard::takeRemoval();
            unset($GLOBALS['__nvt_test_notifications']);
        }

        public function testACorrectionWritesEachRoomsPriceIntoTheLineAndTheBooking(): void
        {
            // Two rooms added at 700 + 95 (a stale price for room 2); the
            // live prices are 795 + 675.
            $cart = ['products' => [42 => $this->line(795.0, [700.0, 95.0])]];
            $this->verdict['corrections'] = ['42' => [
                'api_price' => 1470.0,
                'api_price_raw' => 1336.36,
                'room_prices' => [['price' => 795.0, 'price_raw' => 722.73], ['price' => 675.0, 'price_raw' => 613.63]],
            ]];
            $this->verdict['reconfirm'] = true;
            $allow = true;
            $groups = [];

            fn_novoton_holidays_pre_place_order($cart, $allow, $groups);

            $extra = $cart['products'][42]['extra'];
            self::assertSame(1470.0, $extra['total_price']);
            self::assertSame([795.0, 675.0], array_column($extra['rooms_data'], 'price'));
            self::assertSame([722.73, 613.63], array_column($extra['rooms_data'], 'price_raw'));
            self::assertSame(1470.0, $cart['products'][42]['price']);

            self::assertCount(1, $this->updates);
            self::assertSame(9, $this->updates[0]['booking_id']);
            self::assertSame(1470.0, $this->updates[0]['total_price']);
            self::assertSame(1336.36, $this->updates[0]['base_price']);
            $rooms = json_decode((string) $this->updates[0]['rooms_data'], true);
            self::assertIsArray($rooms);
            self::assertEquals([795.0, 675.0], array_column($rooms, 'price')); // JSON writes 795.0 as 795
            self::assertSame('DBL', $rooms[0]['room_id'], 'the rooms themselves are unchanged');
            self::assertFalse($allow, 'the customer re-confirms the corrected total');

            // Submission (BookingRecordBuilder) totals the booking from these
            // rooms: on a payment retry the corrected line still matches it.
            $submitted = 0.0;
            /** @var list<array<string, mixed>> $rooms */
            foreach ($rooms as $index => $room) {
                [, , , $groupTotal] = (new BookingRoomAssembler())->buildGroupGuestsAndRooms(
                    ['rooms' => [$room + ['original_index' => $index]]],
                    [],
                    [],
                    10.0,
                );
                $submitted += $groupTotal;
            }
            self::assertSame(1470.0, $submitted);
            $line = $cart['products'][42];
            $guard = new CartBookingGuard(
                static fn (array $ids): array => [9 => ['booking_id' => 9, 'product_id' => 7, 'total_price' => $submitted, 'order_id' => 812]],
                static fn (float $amount): float => $amount,
            );
            $products = $cart['products'];
            $retry = ['products' => $products, 'processed_order_id' => [812]];
            self::assertSame([42], $guard->reconcile($retry, $products)['kept']);
            self::assertSame($line, $retry['products'][42]);
        }

        public function testAOneRoomCorrectionWritesTheLinePriceIntoItsRoom(): void
        {
            $cart = ['products' => [42 => $this->line(700.0, [700.0])]];
            $this->verdict['corrections'] = ['42' => ['api_price' => 795.0, 'api_price_raw' => 722.73]];
            $allow = true;
            $groups = [];

            fn_novoton_holidays_pre_place_order($cart, $allow, $groups);

            self::assertSame(795.0, $cart['products'][42]['extra']['rooms_data'][0]['price']);
            self::assertSame(722.73, $cart['products'][42]['extra']['rooms_data'][0]['price_raw']);
            $rooms = json_decode((string) $this->updates[0]['rooms_data'], true);
            self::assertIsArray($rooms);
            self::assertEquals(795.0, $rooms[0]['price']);
        }

        public function testAFailedBookingUpdateLeavesTheLineAndBlocksTheOrder(): void
        {
            $line = $this->line(700.0, [700.0]);
            $cart = ['products' => [42 => $line]];
            $this->verdict['corrections'] = ['42' => ['api_price' => 795.0, 'api_price_raw' => 722.73]];
            $this->verdict['reconfirm'] = true;
            $this->updateFails = true;
            $allow = true;
            $groups = [];

            fn_novoton_holidays_pre_place_order($cart, $allow, $groups);

            self::assertSame($line, $cart['products'][42], 'line and booking stay in step');
            self::assertFalse($allow);
            self::assertArrayNotHasKey(PreOrderPriceVerifier::PRICE_CACHE_SESSION_KEY, $_SESSION, 'the next click re-checks the price');
            $notices = $GLOBALS['__nvt_test_notifications'];
            self::assertIsArray($notices);
            self::assertSame(['E'], array_column($notices, 0), 'one error, no "review the updated total" for a total that did not change');
        }

        public function testALineRemovedByThisRequestsCartCalculationBlocksTheOrder(): void
        {
            // The order click's own cart calculation removed a line claiming
            // a booking it may not carry.
            $foreign = $this->line(700.0, [700.0]);
            $removedCart = ['products' => [42 => $foreign]];
            $removedRows = [42 => $foreign];
            (new CartBookingGuard(static fn (array $ids): array => [], static fn (float $amount): float => $amount))
                ->reconcile($removedCart, $removedRows);

            $cart = ['products' => []];
            $allow = true;
            $groups = [];
            fn_novoton_holidays_pre_place_order($cart, $allow, $groups);
            self::assertFalse($allow);

            // The re-confirmed click goes through
            $allow = true;
            fn_novoton_holidays_pre_place_order($cart, $allow, $groups);
            self::assertTrue($allow);
        }

        /**
         * A line as add_to_cart writes it (booking 9, hotel product 7).
         *
         * @param list<float> $roomPrices
         * @return array<string, mixed>
         */
        private function line(float $price, array $roomPrices): array
        {
            $rooms = [];
            foreach ($roomPrices as $index => $roomPrice) {
                $rooms[] = [
                    'room_id' => $index === 0 ? 'DBL' : 'FAM', 'board_id' => 'BB', 'package_name' => 'P1',
                    'adults' => 2, 'children' => 0, 'childrenAges' => [], 'price' => $roomPrice, 'price_raw' => round($roomPrice / 1.1, 2),
                ];
            }

            return [
                'product_id' => 7,
                'amount' => 1,
                'price' => $price,
                'base_price' => $price,
                'original_price' => $price,
                'stored_price' => 'Y',
                'extra' => [
                    'novoton_booking' => true,
                    'novoton_booking_id' => 9,
                    'hotel_id' => '476',
                    'room_id' => 'DBL',
                    'board_id' => 'BB',
                    'package_name' => 'P1',
                    'check_in' => '2026-10-05',
                    'check_out' => '2026-10-11',
                    'adults' => 2 * count($rooms),
                    'children' => 0,
                    'rooms_data' => $rooms,
                    'total_price' => $price,
                ],
            ];
        }
    }
}
