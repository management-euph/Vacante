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
    use Tygh\Addons\SphinxHolidays\SphinxApi;
    use Tygh\Addons\TravelCore\TravelConstants;
    use Tygh\Registry;

    /**
     * place_order_post marks a booking confirmed only when Sphinx says so.
     * It used to mark every booking that did not throw confirmed: a refusal
     * (the client answers null on an HTTP 4xx) showed as a confirmed booking
     * on a paid order, and nobody heard of it.
     */
    #[CoversClass(OrderHooks::class)]
    final class OrderHooksBookOutcomeTest extends TestCase
    {
        /** @var list<array{string, mixed}> repository writes, in order */
        public static array $writes = [];

        /** @var array<string, mixed>|null what bookHotel() answers */
        public static ?array $answer = null;

        public static string $lastError = '';

        public static bool $maybeProcessed = false;

        /** @var list<array{subject: string, body: string}> alerts sent (their language keys) */
        public static array $alerts = [];

        protected function setUp(): void
        {
            $_SESSION = ['auth' => ['user_id' => 5]];
            self::$writes = [];
            self::$alerts = [];
            self::$answer = null;
            self::$lastError = '';
            self::$maybeProcessed = false;
            PlaceOrderPostArityTest::$orderInfoAnswer = [];
            Registry::set('addons.sphinx_holidays', null);
            ConfigProvider::resetSettingsCache();
            Container::reset();
            Container::override('bookingRepository', static fn (): SphinxBookingRepository => new class extends SphinxBookingRepository {
                public function findById(int $booking_id): ?array
                {
                    return $booking_id === 10 ? [
                        'booking_id' => 10, 'order_id' => 0, 'user_id' => 5, 'session_id' => '',
                        'product_id' => 110, 'offer_id' => 'offer-luxury', 'status' => 'pending',
                        'total_price' => 1500.0, 'base_price' => 1500.0, 'currency' => 'EUR',
                        'room_type' => 'Double Room', 'hotel_name' => 'Hotel Luxury', 'guests_data' => '{"holder":"Ana Pop"}',
                    ] : null;
                }

                public function update(int $booking_id, array $data): bool
                {
                    OrderHooksBookOutcomeTest::$writes[] = ['update:' . $booking_id, $data];

                    return true;
                }
            });
            Container::override('api', static fn (): SphinxApi => new class extends SphinxApi {
                public function __construct()
                {
                }

                public function bookHotel(array $bookingData): ?array
                {
                    return OrderHooksBookOutcomeTest::$answer;
                }

                public function lastError(): string
                {
                    return OrderHooksBookOutcomeTest::$lastError;
                }

                public function lastMaybeProcessed(): bool
                {
                    return OrderHooksBookOutcomeTest::$maybeProcessed;
                }
            });
            Container::override('bookingAlert', static fn (): BookingAlert => new BookingAlert(
                static fn (string $key, array $params): string => $key,
                static function (array $mail): bool {
                    OrderHooksBookOutcomeTest::$alerts[] = ['subject' => $mail['subject'], 'body' => $mail['body']];

                    return true;
                },
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
                    'offer_id' => 'offer-luxury',
                    'total_price' => 1500.0,
                    'hotel_name' => 'Hotel Luxury',
                    'currency' => 'EUR',
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

        /** @return array<string, mixed> every field written to booking 10, the last value of each */
        private static function written(): array
        {
            $fields = [];
            foreach (self::$writes as [$what, $data]) {
                if ($what === 'update:10' && is_array($data)) {
                    foreach ($data as $key => $value) {
                        $fields[$key] = $value;
                    }
                }
            }

            return $fields;
        }

        public function testARefusalIsAFailedBookingTheStoreHearsOf(): void
        {
            self::$lastError = 'Client error HTTP 422: {"message":"The price has changed"}';

            self::placeTheOrder();

            self::assertSame([TravelConstants::STATUS_PENDING, TravelConstants::STATUS_FAILED], self::statuses(), 'linked pending, then failed — never confirmed');
            self::assertStringContainsString('The price has changed', (string) (self::written()['api_response'] ?? ''));
            self::assertCount(1, self::$alerts);
            self::assertSame('sphinx_holidays.book_alert_failed', self::$alerts[0]['body']);
        }

        /** A 5xx or a timeout: the booking may exist at Sphinx, so it is not failed (a failed one can be retried). */
        public function testNoClearAnswerStaysPendingAndTheStoreHearsOfIt(): void
        {
            self::$lastError = 'Server error HTTP 502 (not resent: the request may have reached Sphinx)';
            self::$maybeProcessed = true;

            self::placeTheOrder();

            self::assertSame([TravelConstants::STATUS_PENDING, TravelConstants::STATUS_PENDING], self::statuses());
            self::assertSame('sphinx_holidays.book_alert_pending', self::$alerts[0]['body'] ?? null);
        }

        public function testAnOnRequestBookingStaysPendingWithItsVoucher(): void
        {
            self::$answer = ['order_id' => 9, 'booking_confirmation_number' => 'OR-77', 'status' => 'on_request'];

            self::placeTheOrder();

            self::assertSame(TravelConstants::STATUS_PENDING, self::statuses()[1] ?? null);
            self::assertSame('OR-77', self::written()['api_booking_ref'] ?? null);
            self::assertSame('sphinx_holidays.book_alert_pending', self::$alerts[0]['body'] ?? null);
        }

        public function testAConfirmedBookingIsConfirmedQuietly(): void
        {
            self::$answer = ['order_id' => 9, 'booking_confirmation_number' => 'ABC123XYZ', 'status' => 'confirmed'];

            self::placeTheOrder();

            self::assertSame([TravelConstants::STATUS_PENDING, TravelConstants::STATUS_CONFIRMED], self::statuses());
            self::assertSame('ABC123XYZ', self::written()['api_booking_ref'] ?? null);
            self::assertSame([], self::$alerts);
        }
    }
}
