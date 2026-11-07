<?php

declare(strict_types=1);

namespace {
    // The same stand-ins as OrderHooksBindingTest (whichever file loads
    // first defines them): CS-Cart's notification and order lookups.
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
    use Tygh\Addons\SphinxHolidays\Services\BookingAlert;
    use Tygh\Addons\SphinxHolidays\Services\CircuitBookRequest;
    use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;
    use Tygh\Addons\SphinxHolidays\Services\Container;
    use Tygh\Addons\SphinxHolidays\SphinxApi;
    use Tygh\Addons\TravelCore\TravelConstants;
    use Tygh\Registry;

    /**
     * place_order_post books a circuit with the circuits/book body the
     * Sphinx doc writes (CircuitBookRequest): the price Sphinx gave the
     * offer (customized again when services were chosen), its currency,
     * the quote's room codes, and each guest's birth date and gender. It
     * used to send the hotel body ({offer_id, guests map, contact}).
     */
    #[CoversClass(OrderHooks::class)]
    #[CoversClass(CircuitBookRequest::class)]
    final class OrderHooksCircuitBookTest extends TestCase
    {
        /** @var list<string> the API calls made, in order */
        public static array $calls = [];

        /** @var array<string, mixed>|null the circuits/book body sent */
        public static ?array $sent = null;

        /** @var array<string, mixed> the booking row findById() answers for booking 10 */
        public static array $row = [];

        /** @var list<array{string, mixed}> repository writes, in order */
        public static array $writes = [];

        protected function setUp(): void
        {
            $_SESSION = ['auth' => ['user_id' => 5]];
            self::$calls = [];
            self::$sent = null;
            self::$writes = [];
            // A circuit row as add-to-cart stores it now.
            self::$row = [
                'booking_id' => 10, 'order_id' => 0, 'user_id' => 5, 'session_id' => '',
                'product_id' => 110, 'offer_id' => 'circuit-offer', 'status' => 'pending',
                'room_type' => 'circuit', 'hotel_id' => '1', 'hotel_name' => 'Dummy Circuit', 'check_in' => '2027-07-05',
                'total_price' => 1500.0, 'base_price' => 1017.0, 'currency' => 'EUR',
                'pricing_json' => json_encode(['selling_price' => 1017.0, 'currency' => 'EUR', 'chosen_services' => ['456']]),
                'rooms_data' => json_encode([['room_id' => 'standard-double', 'room_name' => 'Standard - Double', 'adults' => 1, 'children' => 1, 'childrenAges' => [7]]]),
                'guests_data' => json_encode([
                    'room1_adult_1' => ['name' => 'Pop, Ana', 'api_name' => 'Ana Pop', 'first_name' => 'Ana', 'last_name' => 'Pop', 'type' => 'adult', 'birthday' => '1990-03-04', 'dob' => '04/03/1990', 'room' => 1, 'is_holder' => 1, 'gender' => 'f'],
                    'room1_child_1' => ['name' => 'Pop, Ion', 'api_name' => 'Ion Pop', 'first_name' => 'Ion', 'last_name' => 'Pop', 'type' => 'child', 'birthday' => '2019-07-24', 'dob' => '24/07/2019', 'room' => 1, 'is_holder' => 0, 'gender' => 'm'],
                ]),
            ];
            PlaceOrderPostArityTest::$orderInfoAnswer = [];
            Registry::set('addons.sphinx_holidays', null);
            ConfigProvider::resetSettingsCache();
            Container::reset();
            Container::override('bookingRepository', static fn (): SphinxBookingRepository => new class extends SphinxBookingRepository {
                public function findById(int $booking_id): ?array
                {
                    return $booking_id === 10 ? OrderHooksCircuitBookTest::$row : null;
                }

                public function update(int $booking_id, array $data): bool
                {
                    OrderHooksCircuitBookTest::$writes[] = ['update:' . $booking_id, $data];

                    return true;
                }
            });
            Container::override('api', static fn (): SphinxApi => new class extends SphinxApi {
                public function __construct()
                {
                }

                public function customizeCircuit(array $data): ?array
                {
                    OrderHooksCircuitBookTest::$calls[] = 'customizeCircuit:' . ($data['offer_id'] ?? '') . ':' . implode(',', (array) ($data['service_codes'] ?? []));

                    return [
                        'offer_id' => 'circuit-offer',
                        'rooms' => [['code' => 'standard-double', 'name' => 'Standard - Double', 'adults' => 1, 'children_ages' => [7]]],
                        'pricing' => ['selling_price' => 1120.0, 'currency' => 'EUR'],
                    ];
                }

                public function getCircuitQuote(array $params): array
                {
                    OrderHooksCircuitBookTest::$calls[] = 'getCircuitQuote';

                    return [];
                }

                public function bookCircuit(array $bookingData): ?array
                {
                    OrderHooksCircuitBookTest::$calls[] = 'bookCircuit';
                    OrderHooksCircuitBookTest::$sent = $bookingData;

                    return ['order_id' => 9, 'booking_confirmation_number' => 'CR-501', 'status' => 'confirmed'];
                }

                public function bookHotel(array $bookingData): ?array
                {
                    OrderHooksCircuitBookTest::$calls[] = 'bookHotel';

                    return null;
                }

                public function lastError(): string
                {
                    return '';
                }

                public function lastMaybeProcessed(): bool
                {
                    return false;
                }
            });
            Container::override('bookingAlert', static fn (): BookingAlert => new BookingAlert(
                static fn (string $key, array $params): string => $key,
                static fn (array $mail): bool => true,
                static fn (): string => 'orders@example.ro',
            ));
        }

        protected function tearDown(): void
        {
            $_SESSION = [];
            PlaceOrderPostArityTest::$orderInfoAnswer = [];
            Container::reset();
            Registry::set('addons.sphinx_holidays', null);
            ConfigProvider::resetSettingsCache();
        }

        private static function placeTheOrder(): void
        {
            OrderHooks::placeOrderPost(501, ['products' => [7 => [
                'product_id' => 110,
                'amount' => 1,
                'price' => 1500.0,
                'stored_price' => 'Y',
                'extra' => [
                    'sphinx_booking' => 1,
                    'travel_booking_id' => 10,
                    'offer_id' => 'circuit-offer',
                    'total_price' => 1500.0,
                    'hotel_name' => 'Dummy Circuit',
                    'currency' => 'EUR',
                    'booking_type' => 'circuit',
                ],
            ]]]);
        }

        /** @return list<string> the statuses written to booking 10, in order */
        private static function statuses(): array
        {
            $statuses = [];
            foreach (self::$writes as [$what, $data]) {
                if ($what === 'update:10' && is_array($data) && isset($data['status'])) {
                    $statuses[] = $data['status'];
                }
            }

            return $statuses;
        }

        public function testACircuitIsBookedWithTheDocumentedBody(): void
        {
            self::placeTheOrder();

            self::assertSame(['customizeCircuit:circuit-offer:456', 'bookCircuit'], self::$calls, 'customized again with the chosen service, booked once — never as a hotel, never re-quoted');
            self::assertSame([
                'offer_id' => 'circuit-offer',
                'reference_code' => '501',
                // Sphinx's selling price with the service, not the store's 1500.
                'price' => 1120.0,
                'currency' => 'EUR',
                'occupancy' => [['room_code' => 'standard-double', 'guests' => [
                    ['first_name' => 'Ana', 'last_name' => 'Pop', 'birth_date' => '1990-03-04', 'gender' => 'f'],
                    ['first_name' => 'Ion', 'last_name' => 'Pop', 'birth_date' => '2019-07-24', 'gender' => 'm'],
                ]]],
            ], self::$sent);
            self::assertSame([TravelConstants::STATUS_PENDING, TravelConstants::STATUS_CONFIRMED], self::statuses());
        }

        /** No services: the quote's price, stored at add-to-cart, is this offer's; nothing is asked. */
        public function testWithoutServicesTheQuotedPriceIsSent(): void
        {
            self::$row['pricing_json'] = json_encode(['selling_price' => 927.0, 'currency' => 'EUR', 'chosen_services' => []]);

            self::placeTheOrder();

            self::assertSame(['bookCircuit'], self::$calls);
            self::assertSame(927.0, self::$sent['price'] ?? null);
            self::assertSame('EUR', self::$sent['currency'] ?? null);
            self::assertSame(['standard-double'], array_column((array) (self::$sent['occupancy'] ?? []), 'room_code'));
        }
    }
}
