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
    use Tygh\Addons\SphinxHolidays\Services\ConfigProvider;
    use Tygh\Addons\SphinxHolidays\Services\Container;
    use Tygh\Addons\SphinxHolidays\Services\PackageBookRequest;
    use Tygh\Addons\SphinxHolidays\SphinxApi;
    use Tygh\Addons\TravelCore\TravelConstants;
    use Tygh\Registry;

    /**
     * place_order_post books a package with the packages/book body the
     * Sphinx doc writes (PackageBookRequest), priced as Sphinx quotes the
     * offer at that moment. It used to send the hotel body ({offer_id,
     * guests map, contact}): no price, currency, room codes or gender, so
     * Sphinx could not check it against the offer.
     */
    #[CoversClass(OrderHooks::class)]
    #[CoversClass(PackageBookRequest::class)]
    final class OrderHooksPackageBookTest extends TestCase
    {
        /** @var list<string> the API calls made, in order */
        public static array $calls = [];

        /** @var array<string, mixed>|null the packages/book body sent */
        public static ?array $sent = null;

        /** @var array<string, mixed>|null what verifyPackageOffer() answers */
        public static ?array $verified = null;

        /** @var array<string, mixed>|null what customizePackage() answers */
        public static ?array $customized = null;

        /** @var list<array{string, mixed}> repository writes, in order */
        public static array $writes = [];

        protected function setUp(): void
        {
            $_SESSION = ['auth' => ['user_id' => 5]];
            self::$calls = [];
            self::$sent = null;
            self::$writes = [];
            self::$verified = [
                'pricing' => ['selling_price' => 1000.0, 'currency' => 'EUR'],
                'hotel' => ['rooms' => [['code' => '75', 'name' => 'Twin Room', 'adults' => 1, 'children_ages' => [7]]]],
            ];
            self::$customized = [
                'pricing' => ['selling_price' => 1040.0, 'currency' => 'EUR'],
                'hotel' => ['rooms' => [['code' => '75', 'name' => 'Twin Room', 'adults' => 1, 'children_ages' => [7]]]],
            ];
            PlaceOrderPostArityTest::$orderInfoAnswer = [];
            Registry::set('addons.sphinx_holidays', null);
            ConfigProvider::resetSettingsCache();
            Container::reset();
            Container::override('bookingRepository', static fn (): SphinxBookingRepository => new class extends SphinxBookingRepository {
                public function findById(int $booking_id): ?array
                {
                    // A package row as add-to-cart stores it now.
                    return $booking_id === 10 ? [
                        'booking_id' => 10, 'order_id' => 0, 'user_id' => 5, 'session_id' => '',
                        'product_id' => 110, 'offer_id' => 'pkg-offer', 'status' => 'pending',
                        'room_type' => 'package', 'hotel_name' => 'Hotel Sea', 'check_in' => '2027-07-22',
                        'total_price' => 1500.0, 'base_price' => 1000.0, 'currency' => 'EUR',
                        'pricing_json' => json_encode(['selling_price' => 1000.0, 'currency' => 'EUR', 'chosen_services' => ['TRF']]),
                        'rooms_data' => json_encode([['code' => '75', 'name' => 'Twin Room', 'adults' => 1, 'children_ages' => [7], 'room_code' => '75']]),
                        'guests_data' => json_encode([
                            'room1_adult_1' => ['name' => 'Pop, Ana', 'api_name' => 'Ana Pop', 'first_name' => 'Ana', 'last_name' => 'Pop', 'type' => 'adult', 'birthday' => '1990-03-04', 'dob' => '04/03/1990', 'room' => 1, 'is_holder' => 1, 'gender' => 'f'],
                            'room1_child_1' => ['name' => 'Pop, Mia', 'api_name' => 'Mia Pop', 'first_name' => 'Mia', 'last_name' => 'Pop', 'type' => 'child', 'birthday' => '2019-07-24', 'dob' => '24/07/2019', 'room' => 1, 'is_holder' => 0, 'gender' => 'f'],
                        ]),
                    ] : null;
                }

                public function update(int $booking_id, array $data): bool
                {
                    OrderHooksPackageBookTest::$writes[] = ['update:' . $booking_id, $data];

                    return true;
                }
            });
            Container::override('api', static fn (): SphinxApi => new class extends SphinxApi {
                public function __construct()
                {
                }

                public function verifyPackageOffer(string $offerId): ?array
                {
                    OrderHooksPackageBookTest::$calls[] = 'verifyPackageOffer:' . $offerId;

                    return OrderHooksPackageBookTest::$verified;
                }

                public function customizePackage(array $data): ?array
                {
                    OrderHooksPackageBookTest::$calls[] = 'customizePackage:' . implode(',', (array) ($data['service_codes'] ?? []));

                    return OrderHooksPackageBookTest::$customized;
                }

                public function bookPackage(array $bookingData): ?array
                {
                    OrderHooksPackageBookTest::$calls[] = 'bookPackage';
                    OrderHooksPackageBookTest::$sent = $bookingData;

                    return ['order_id' => 9, 'booking_confirmation_number' => 'PK-501', 'status' => 'confirmed'];
                }

                public function bookHotel(array $bookingData): ?array
                {
                    OrderHooksPackageBookTest::$calls[] = 'bookHotel';

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
                    'offer_id' => 'pkg-offer',
                    'total_price' => 1500.0,
                    'hotel_name' => 'Hotel Sea',
                    'currency' => 'EUR',
                    'booking_type' => 'package',
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

        public function testAPackageIsBookedWithTheDocumentedBody(): void
        {
            self::placeTheOrder();

            self::assertSame(['verifyPackageOffer:pkg-offer', 'customizePackage:TRF', 'bookPackage'], self::$calls, 'verified, customized with the chosen service, booked once — never as a hotel');
            self::assertSame([
                'offer_id' => 'pkg-offer',
                'reference_code' => '501',
                // Sphinx's selling price with the service, not the store's 1500.
                'price' => 1040.0,
                'currency' => 'EUR',
                'occupancy' => [['room_code' => '75', 'guests' => [
                    ['first_name' => 'Ana', 'last_name' => 'Pop', 'birth_date' => '1990-03-04', 'gender' => 'f'],
                    ['first_name' => 'Mia', 'last_name' => 'Pop', 'birth_date' => '2019-07-24', 'gender' => 'f'],
                ]]],
                'additional_services' => ['TRF'],
            ], self::$sent);
            self::assertSame([TravelConstants::STATUS_PENDING, TravelConstants::STATUS_CONFIRMED], self::statuses());
        }

        /** Sphinx cannot price the offer now: the basis add-to-cart stored is sent, Sphinx decides. */
        public function testWithoutAQuoteTheStoredBasisIsSent(): void
        {
            self::$verified = null;

            self::placeTheOrder();

            self::assertSame(['verifyPackageOffer:pkg-offer', 'bookPackage'], self::$calls);
            self::assertSame(1000.0, self::$sent['price'] ?? null);
            self::assertSame('EUR', self::$sent['currency'] ?? null);
            self::assertSame(['75'], array_column(self::$sent['occupancy'] ?? [], 'room_code'));
            self::assertSame(['TRF'], self::$sent['additional_services'] ?? null);
        }
    }
}
