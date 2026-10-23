<?php

declare(strict_types=1);

namespace {
    if (!defined('BOOTSTRAP')) {
        define('BOOTSTRAP', true);
    }
    if (!defined('CONTROLLER_STATUS_REDIRECT')) {
        define('CONTROLLER_STATUS_REDIRECT', 'redirect');
    }

    require_once dirname(__DIR__, 3) . '/src/Services/ServiceLoader.php';

    if (!function_exists('_nvt_get_security_service')) {
        /** The main novoton_booking controller defines it before including a mode. */
        function _nvt_get_security_service(): \Tygh\Addons\NovotonHolidays\Services\SecurityServiceInterface
        {
            return _nvt_security_service();
        }
    }
    if (!function_exists('fn_set_notification')) {
        /** Records CS-Cart notifications (same recorder as CalculateCartItemsTest). */
        function fn_set_notification(string $type, string $title, string $message = ''): void
        {
            $GLOBALS['__nvt_test_notifications'][] = [$type, $title, $message];
        }
    }
    if (!function_exists('fn_travel_core_after_add_to_cart_url')) {
        function fn_travel_core_after_add_to_cart_url(string $addon): string
        {
            return 'checkout.cart';
        }
    }
}

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Schema {

    use PHPUnit\Framework\TestCase;
    use Tygh\Addons\NovotonHolidays\Repository\BookingOwnershipRepositoryInterface;
    use Tygh\Addons\NovotonHolidays\Repository\BookingRepositoryInterface;
    use Tygh\Addons\NovotonHolidays\Services\Container;
    use Tygh\Tygh;

    /**
     * novoton_booking.update_booking (audit C2/H4): the reservation sends each
     * child at its guest's age, so a new date of birth that gives a child a
     * different age than the stay was priced for is refused — back to the
     * edit form with an error, the booking and the cart left as they were.
     * Before, the guests were written as posted and the reservation went out
     * with ages that were never priced (the checkout check only caught it
     * while it was enabled and the API answered).
     */
    final class UpdateBookingKeepsPricedAgesTest extends TestCase
    {
        private const string CONTROLLER = '/controllers/frontend/novoton_booking/update_booking.php';

        /** @var array<string, mixed> */
        private array $serverBackup = [];

        /** @var array<string, mixed> */
        private array $requestBackup = [];

        private mixed $appBackup = null;

        /** @var list<array<string, mixed>> the update() calls on the booking */
        private array $updates = [];

        protected function setUp(): void
        {
            $this->serverBackup = $_SERVER;
            $this->requestBackup = $_REQUEST;
            $this->appBackup = Tygh::$app;
            $GLOBALS['__nvt_test_notifications'] = [];
            $this->updates = [];

            $_SERVER['REQUEST_METHOD'] = 'POST';
            Tygh::$app = ['session' => ['auth' => ['user_id' => 42]]];

            $ownership = $this->createStub(BookingOwnershipRepositoryInterface::class);
            $ownership->method('checkOwnership')->willReturn(5);

            $bookings = $this->createStub(BookingRepositoryInterface::class);
            $bookings->method('findById')->willReturn(self::row());
            $bookings->method('update')->willReturnCallback(function (int $id, array $data): bool {
                $this->updates[] = ['booking_id' => $id] + $data;

                return true;
            });

            Container::setInstance(null);
            Container::getInstance()->override('bookingOwnershipRepository', static fn (): BookingOwnershipRepositoryInterface => $ownership);
            Container::getInstance()->override('bookingRepository', static fn (): BookingRepositoryInterface => $bookings);
        }

        protected function tearDown(): void
        {
            $_SERVER = $this->serverBackup;
            $_REQUEST = $this->requestBackup;
            Tygh::$app = $this->appBackup;
            Container::setInstance(null);
            unset($GLOBALS['__nvt_test_notifications']);
        }

        public function testADateOfBirthThatChangesAPricedAgeIsRefused(): void
        {
            // The stay was priced for a child of 4; the new date of birth makes them 14
            $_REQUEST = self::post(date('Y-m-d', (int) strtotime('-14 years -2 months')));

            $result = $this->runController();

            self::assertSame([CONTROLLER_STATUS_REDIRECT, 'novoton_booking.edit_booking?booking_id=5&cart_id='], $result);
            self::assertSame([], $this->updates, 'the booking keeps the guests it was priced for');
            $notices = $GLOBALS['__nvt_test_notifications'];
            self::assertIsArray($notices);
            self::assertCount(1, $notices);
            self::assertSame('E', $notices[0][0]);
        }

        public function testGuestsKeepingThePricedAgesAreSaved(): void
        {
            $_REQUEST = self::post(date('Y-m-d', (int) strtotime('-4 years -2 months')));

            $result = $this->runController();

            self::assertSame([CONTROLLER_STATUS_REDIRECT, 'checkout.cart'], $result);
            self::assertCount(1, $this->updates);
            $guests = json_decode((string) $this->updates[0]['guests_data'], true);
            self::assertIsArray($guests);
            self::assertSame(4, $guests['room1_child_1']['age'] ?? null);
        }

        /** @return mixed the controller's return value */
        private function runController(): mixed
        {
            $path = dirname(__DIR__, 3) . self::CONTROLLER;

            return (static fn () => include $path)();
        }

        /**
         * The edit form's post: the holder and one child with a date of birth.
         *
         * @return array<string, mixed>
         */
        private static function post(string $childBirthday): array
        {
            return [
                'booking_id' => '5',
                'cart_id' => '',
                'guests' => [
                    'room1_adult_1' => ['first_name' => 'Ana', 'last_name' => 'Pop', 'type' => 'adult', 'room' => '1', 'is_holder' => '1'],
                    'room1_child_1' => ['first_name' => 'Ion', 'last_name' => 'Pop', 'type' => 'child', 'room' => '1', 'age' => '4', 'birthday' => $childBirthday],
                ],
                'contact' => ['email' => 'ana@example.com', 'phone' => '0700000000'],
            ];
        }

        /**
         * Booking 5 as add_to_cart stored it: one room, 2 adults and a child
         * priced at 4.
         *
         * @return array<string, mixed>
         */
        private static function row(): array
        {
            return [
                'booking_id' => 5,
                'order_id' => 0,
                'status' => 'pending',
                'hotel_id' => '476',
                'package_name' => 'P1',
                'room_id' => 'DBL 2+1',
                'board_id' => 'BB',
                'check_in' => date('Y-m-d', (int) strtotime('+30 days')),
                'check_out' => date('Y-m-d', (int) strtotime('+37 days')),
                'adults' => '2',
                'children' => '1',
                'children_ages' => '4',
                'rooms_data' => json_encode([[
                    'room_id' => 'DBL 2+1', 'board_id' => 'BB', 'package_name' => 'P1',
                    'adults' => 2, 'children' => 1, 'childrenAges' => [4], 'price' => 800.0, 'price_raw' => 727.27,
                ]]),
            ];
        }
    }
}
