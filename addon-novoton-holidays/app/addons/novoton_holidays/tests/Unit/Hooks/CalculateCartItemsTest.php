<?php

declare(strict_types=1);

namespace {
    // Load the procedural hook functions under test in the GLOBAL namespace,
    // as CS-Cart loads them (pattern: LinkOrderBookingsTest).
    if (!defined('BOOTSTRAP')) {
        define('BOOTSTRAP', true);
    }

    require_once dirname(__DIR__, 3) . '/src/Services/ServiceLoader.php';
    require_once dirname(__DIR__, 3) . '/functions/formatting.php';
    require_once dirname(__DIR__, 3) . '/hooks/cart_hooks.php';

    if (!function_exists('fn_set_notification')) {
        /** Records CS-Cart notifications for CalculateCartItemsTest. */
        function fn_set_notification(string $type, string $title, string $message = ''): void
        {
            $GLOBALS['__nvt_test_notifications'][] = [$type, $title, $message];
        }
    }
}

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Hooks {

    use PHPUnit\Framework\TestCase;
    use Tygh\Addons\NovotonHolidays\Services\CartBookingGuard;
    use Tygh\Addons\NovotonHolidays\Services\ConfigProvider;
    use Tygh\Addons\NovotonHolidays\Services\Container;
    use Tygh\Addons\NovotonHolidays\Tests\Support\DbStub;

    /**
     * Audit H3, end to end through fn_novoton_holidays_calculate_cart_items
     * and CartBookingRepository::findCartStage (run against an in-memory
     * novoton_bookings table that evaluates the query's conditions).
     *
     * Account 42 owns pending booking 5 for hotel product 77 (EUR 1,500). A
     * plain line for that product - CS-Cart's own checkout.add, at the
     * catalogue "from" price - must stay plain: the old hook handed it the
     * booking (extra only), so the full reservation was submitted for EUR 45.
     * A line claiming a booking it may not carry is removed, not left behind
     * as a catalogue-priced hotel line.
     */
    final class CalculateCartItemsTest extends TestCase
    {
        /** @var list<array{0: string, 1: list<mixed>}> */
        private array $queries = [];

        /** @var array<int, array<string, mixed>> the novoton_bookings table */
        private array $bookings = [];

        protected function setUp(): void
        {
            DbStub::reset();
            Container::getInstance()->reset();
            ConfigProvider::reset();
            $this->queries = [];
            $GLOBALS['__nvt_test_notifications'] = [];
            unset($_SESSION[CartBookingGuard::SESSION_KEY]);

            $this->bookings = [5 => $this->booking(5)];
            DbStub::$getArray = fn (string $query, mixed ...$params): array => $this->select($query, array_values($params));
        }

        protected function tearDown(): void
        {
            DbStub::reset();
            Container::getInstance()->reset();
            ConfigProvider::reset();
            unset($_SESSION[CartBookingGuard::SESSION_KEY], $GLOBALS['__nvt_test_notifications']);
        }

        public function testPlainHotelLineIsNotGivenTheSessionsBooking(): void
        {
            $plain = ['product_id' => 77, 'amount' => 1, 'price' => 45.0, 'extra' => []];
            $cart = ['products' => ['c1' => $plain]];
            $cartProducts = ['c1' => $plain];

            fn_novoton_holidays_calculate_cart_items($cart, $cartProducts, ['user_id' => 42]);

            $this->assertSame($plain, $cart['products']['c1']);
            $this->assertSame($plain, $cartProducts['c1']);
            $this->assertSame([], $this->queries, 'no booking is looked up for a plain line');
        }

        public function testOwnBookingLineAtItsPriceKeepsTheBooking(): void
        {
            $line = $this->line(5);
            $cart = ['products' => ['c1' => $line]];
            $cartProducts = ['c1' => $line];

            fn_novoton_holidays_calculate_cart_items($cart, $cartProducts, ['user_id' => 42]);

            $this->assertSame($line, $cart['products']['c1']);
            $this->assertSame(5, $cartProducts['c1']['extra']['novoton_booking_id']);
            $this->assertSame('Y', $cartProducts['c1']['stored_price']);
            $this->assertSame([5], $_SESSION[CartBookingGuard::SESSION_KEY], 'the verified id is remembered for the login');
            $this->assertSame([], $GLOBALS['__nvt_test_notifications']);
        }

        public function testLineClaimingAForeignBookingIsRemoved(): void
        {
            $this->bookings[9] = ['user_id' => 77777] + $this->booking(9);
            $line = $this->line(9);
            $cart = ['products' => ['c1' => $line]];
            $cartProducts = ['c1' => $line];

            fn_novoton_holidays_calculate_cart_items($cart, $cartProducts, ['user_id' => 42]);

            $this->assertSame([], $cart['products'], 'no catalogue-priced hotel line is left behind');
            $this->assertSame([], $cartProducts);
            $this->assertCount(1, $GLOBALS['__nvt_test_notifications'], 'the customer is told to book again');
            $this->assertSame([], $_SESSION[CartBookingGuard::SESSION_KEY]);
            $this->assertCount(1, $this->queries);
            $this->assertStringContainsString('order_id = 0', $this->queries[0][0]);
        }

        public function testRetryAfterAFailedPaymentKeepsTheSubmittedBooking(): void
        {
            // Order 812 was placed (booking 5 submitted and linked), the
            // NETOPIA payment failed, and CS-Cart returned the customer to
            // checkout with the same cart: the live reservation keeps its price.
            $this->bookings[5] = ['order_id' => 812, 'status' => 'confirmed'] + $this->booking(5);
            $line = $this->line(5);
            $cart = ['products' => ['c1' => $line], 'processed_order_id' => [812], 'failed_order_id' => [812]];
            $cartProducts = ['c1' => $line];

            fn_novoton_holidays_calculate_cart_items($cart, $cartProducts, ['user_id' => 42]);

            $this->assertSame($line, $cart['products']['c1']);
            $this->assertSame('Y', $cartProducts['c1']['stored_price']);
            $this->assertSame([], $GLOBALS['__nvt_test_notifications']);
        }

        public function testRetryOfABookingSplitIntoPackageGroupsKeepsItsLine(): void
        {
            // Two rooms on two packages: submission rewrote booking 5's row
            // with group 1 (700) and added group 2 as booking 10 (800), both
            // on order 812. The line charges both - 1500.
            $stay = ['order_id' => 812, 'status' => 'confirmed', 'hotel_id' => '476', 'check_in' => '2026-10-05', 'check_out' => '2026-10-11'];
            $this->bookings[5] = ['total_price' => 700.0] + $stay + $this->booking(5);
            $this->bookings[10] = ['total_price' => 800.0] + $stay + $this->booking(10);
            $line = $this->line(5);
            $cart = ['products' => ['c1' => $line], 'processed_order_id' => [812], 'failed_order_id' => [812]];
            $cartProducts = ['c1' => $line];

            fn_novoton_holidays_calculate_cart_items($cart, $cartProducts, ['user_id' => 42]);

            $this->assertSame($line, $cart['products']['c1']);
            $this->assertSame([], $GLOBALS['__nvt_test_notifications']);
        }

        public function testBookingLinkedToAnUnrelatedOrderIsRemoved(): void
        {
            $this->bookings[5] = ['order_id' => 700, 'status' => 'confirmed'] + $this->booking(5);
            $line = $this->line(5);
            $cart = ['products' => ['c1' => $line], 'processed_order_id' => [812]];
            $cartProducts = ['c1' => $line];

            fn_novoton_holidays_calculate_cart_items($cart, $cartProducts, ['user_id' => 42]);

            $this->assertSame([], $cart['products']);
            $this->assertSame([], $cartProducts);
        }

        public function testFailedSubmissionOnTheCartsOwnOrderIsRemoved(): void
        {
            // Nothing live at Novoton: paying the stored price on the retry
            // would buy nothing (place_order_post never resubmits a linked booking).
            $this->bookings[5] = ['order_id' => 812, 'status' => 'failed'] + $this->booking(5);
            $line = $this->line(5);
            $cart = ['products' => ['c1' => $line], 'failed_order_id' => [812]];
            $cartProducts = ['c1' => $line];

            fn_novoton_holidays_calculate_cart_items($cart, $cartProducts, ['user_id' => 42]);

            $this->assertSame([], $cart['products']);
        }

        public function testGuestBookingVerifiedBeforeLoginIsKeptAfterIt(): void
        {
            // Guest booking: user_id 0, the pre-login session id. CS-Cart
            // regenerated the session id at login, so neither user_id nor
            // session_id matches any more - the session's verified list does.
            $this->bookings[5] = ['user_id' => 0, 'session_id' => 'sess-before-login'] + $this->booking(5);
            $_SESSION[CartBookingGuard::SESSION_KEY] = [5];
            $line = $this->line(5);
            $cart = ['products' => ['c1' => $line]];
            $cartProducts = ['c1' => $line];

            fn_novoton_holidays_calculate_cart_items($cart, $cartProducts, ['user_id' => 42]);

            $this->assertSame($line, $cart['products']['c1']);
            $this->assertSame([5], $_SESSION[CartBookingGuard::SESSION_KEY]);
        }

        public function testGuestBookingNotVerifiedByThisSessionIsRemoved(): void
        {
            $this->bookings[5] = ['user_id' => 0, 'session_id' => 'someone-elses-session'] + $this->booking(5);
            $line = $this->line(5);
            $cart = ['products' => ['c1' => $line]];
            $cartProducts = ['c1' => $line];

            fn_novoton_holidays_calculate_cart_items($cart, $cartProducts, ['user_id' => 42]);

            $this->assertSame([], $cart['products']);
        }

        public function testLineOnlyInTheStoredCartIsVerifiedToo(): void
        {
            // place_order_post submits from $cart['products']: a claiming row
            // missing from this calculation's rows must not escape the check.
            $this->bookings[9] = ['user_id' => 77777] + $this->booking(9);
            $cart = ['products' => ['c1' => $this->line(9)]];
            $cartProducts = [];

            fn_novoton_holidays_calculate_cart_items($cart, $cartProducts, ['user_id' => 42]);

            $this->assertSame([], $cart['products']);
        }

        // ── helpers ──────────────────────────────────────────────────────────

        /** @return array<string, mixed> */
        private function booking(int $id): array
        {
            return [
                'booking_id' => $id, 'product_id' => 77, 'total_price' => 1500.0, 'currency' => 'EUR',
                'order_id' => 0, 'status' => 'pending', 'user_id' => 42, 'session_id' => '',
            ];
        }

        /** @return array<string, mixed> add_to_cart's row shape (no dates: display data then needs no fn_date_format) */
        private function line(int $bookingId): array
        {
            return [
                'product_id' => 77, 'amount' => 1, 'price' => 1500.0, 'stored_price' => 'Y',
                'extra' => ['novoton_booking' => true, 'novoton_booking_id' => $bookingId, 'travel_booking' => true, 'total_price' => 1500.0,
                    'board_id' => 'BB', 'room_id' => 'DBL', 'nights' => 7],
            ];
        }

        /**
         * Evaluates findCartStage's query against $this->bookings: the
         * placeholders are consumed in the order the SQL names them.
         *
         * @param list<mixed> $params
         * @return list<array<string, mixed>>
         */
        private function select(string $sql, array $params): array
        {
            $this->queries[] = [$sql, $params];
            if (str_contains($sql, 'SUM(total_price)')) {
                return $this->lineTotals((array) $params[0]);
            }
            $ids = (array) array_shift($params);
            $pending = array_shift($params);
            [$ownOrders, $dead] = str_contains($sql, 'order_id IN (?n)')
                ? [(array) array_shift($params), (array) array_shift($params)]
                : [[], []];
            $userId = str_contains($sql, 'user_id = ?i') ? array_shift($params) : null;
            $sessionId = str_contains($sql, 'session_id = ?s') ? array_shift($params) : null;
            $verified = substr_count($sql, 'booking_id IN (?n)') === 2 ? (array) array_shift($params) : [];

            $rows = [];
            foreach ($this->bookings as $row) {
                $stage = ($row['order_id'] === 0 && $row['status'] === $pending)
                    || (in_array($row['order_id'], $ownOrders, true) && !in_array($row['status'], $dead, true));
                $owned = ($userId !== null && $row['user_id'] === $userId)
                    || ($sessionId !== null && $row['session_id'] === $sessionId)
                    || in_array($row['booking_id'], $verified, true);
                if (in_array($row['booking_id'], $ids, true) && $stage && $owned) {
                    $rows[] = $row;
                }
            }

            return $rows;
        }

        /**
         * The totals query: the rows of the given orders summed per order,
         * hotel and dates.
         *
         * @param array<mixed> $orderIds
         * @return list<array<string, mixed>>
         */
        private function lineTotals(array $orderIds): array
        {
            $totals = [];
            foreach ($this->bookings as $row) {
                if (!in_array($row['order_id'], $orderIds, true)) {
                    continue;
                }
                $stay = ['order_id' => $row['order_id'], 'hotel_id' => $row['hotel_id'] ?? '', 'check_in' => $row['check_in'] ?? '', 'check_out' => $row['check_out'] ?? ''];
                $key = implode('|', $stay);
                $totals[$key] = $stay + ['line_total' => ($totals[$key]['line_total'] ?? 0.0) + $row['total_price']];
            }

            return array_values($totals);
        }
    }
}
