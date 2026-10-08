<?php

declare(strict_types=1);

namespace Tygh\Addons\Eurosite\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\Eurosite\Repository\EurositeBookingRepository;
use Tygh\Addons\Eurosite\Services\OrderLineDecorator;

/**
 * The admin order page showed nothing under a eurosite line: no template, and
 * the line itself lacks the meal plan, the children count, every room after
 * the first, the terms, the supplier reference and the View Booking id.
 * OrderLineDecorator fills those in from the eurosite_bookings row; these
 * tests pin what the shared block then receives.
 */
#[CoversClass(OrderLineDecorator::class)]
final class OrderLineDecoratorTest extends TestCase
{
    /**
     * @return array{fee_line: string, ref_line: string, statuses: array<string, string>, payment_terms: string, date_format: string}
     */
    private static function context(string $paymentTerms = "30% at booking\n\n70% 21 days before check-in\n"): array
    {
        return [
            'fee_line' => 'Between [from] - [to]: [value] penalty',
            'ref_line' => 'Eurosite booking: [ref] ([status])',
            'statuses' => ['pending' => 'Pending', 'confirmed' => 'Confirmed', 'cancelled' => 'Cancelled', 'failed' => 'Failed'],
            'payment_terms' => $paymentTerms,
            'date_format' => '%d.%m.%Y',
        ];
    }

    /**
     * A eurosite line as add_to_cart writes it, after travel_core's own
     * get_order_info (guests_data formatted into an array).
     *
     * @return array<string, mixed>
     */
    private static function extra(array $overrides = []): array
    {
        return $overrides + [
            'travel_booking' => true,
            'eurosite_booking_id' => 41,
            'booking_id' => 41,
            'hotel_name' => 'Hotel Laguna',
            'check_in' => '2026-10-05',
            'check_out' => '2026-10-11',
            'nights' => 6,
            'rooms_data' => '[{"room_name":"Double Room","code":"DBL","adults":2,"children":0,"childrenAges":[]}]',
            'num_rooms' => 1,
            'room_name' => 'Double Room',
            'adults' => 2,
            'children_ages' => '',
            'guests_data' => [
                ['display_name' => 'Popescu / Ion', 'name' => 'Popescu / Ion', 'type' => 'adult', 'age' => 0, 'is_holder' => true, 'birthday' => '', 'room' => 1],
                ['display_name' => 'Popescu / Ana', 'name' => 'Popescu / Ana', 'type' => 'adult', 'age' => 0, 'is_holder' => false, 'birthday' => '', 'room' => 1],
            ],
            'holder_name' => 'Popescu / Ion',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function booking(array $overrides = []): array
    {
        return $overrides + [
            'booking_id' => 41,
            'client_ref' => 'ES1A2B3C4D5E',
            'api_ref' => '778899',
            'status' => 'confirmed',
            'meal_name' => 'Half Board',
            'board_id' => 'HB',
            'cancellation_fees_json' => '',
        ];
    }

    public function testFillsBoardFromTheBookingsMealPlan(): void
    {
        $extra = OrderLineDecorator::decorate(self::extra(), self::booking(), 0, self::context());

        self::assertSame('Half Board', $extra['board_name']);
    }

    public function testBoardFallsBackToTheBoardCodeAndNeverOverwritesALineValue(): void
    {
        $fromCode = OrderLineDecorator::decorate(self::extra(), self::booking(['meal_name' => '']), 0, self::context());
        $kept = OrderLineDecorator::decorate(self::extra(['board_name' => 'All Inclusive']), self::booking(), 0, self::context());

        self::assertSame('HB', $fromCode['board_name']);
        self::assertSame('All Inclusive', $kept['board_name']);
    }

    public function testCountsChildrenFromTheirAges(): void
    {
        $two = OrderLineDecorator::decorate(self::extra(['children_ages' => '7, 4']), null, 0, self::context());
        $none = OrderLineDecorator::decorate(self::extra(), null, 0, self::context());
        $given = OrderLineDecorator::decorate(self::extra(['children_ages' => '7', 'children' => 3]), null, 0, self::context());

        self::assertSame(2, $two['children']);
        self::assertSame('7, 4', $two['children_ages'], 'the stored ages stay as they are');
        self::assertSame(0, $none['children']);
        self::assertSame(3, $given['children']);
    }

    public function testNamesEveryRoomOfAMultiRoomBooking(): void
    {
        $rooms = '[{"room_name":"Double Room","adults":2},{"room_name":"Double Room","adults":2},{"room_name":"Triple Room","adults":3}]';
        $fromJson = OrderLineDecorator::decorate(self::extra(['rooms_data' => $rooms, 'num_rooms' => 3]), null, 0, self::context());
        $fromArray = OrderLineDecorator::decorate(
            self::extra(['rooms_data' => json_decode($rooms, true), 'num_rooms' => 3]),
            null,
            0,
            self::context(),
        );

        self::assertSame('2x Double Room, Triple Room', $fromJson['room_type_display']);
        self::assertSame('2x Double Room, Triple Room', $fromArray['room_type_display']);
        self::assertSame($rooms, $fromJson['rooms_data'], 'rooms_data is left as stored');
    }

    public function testASingleRoomKeepsTheLinesRoomName(): void
    {
        $extra = OrderLineDecorator::decorate(self::extra(), null, 0, self::context());
        $broken = OrderLineDecorator::decorate(self::extra(['rooms_data' => '{not json']), null, 0, self::context());

        self::assertArrayNotHasKey('room_type_display', $extra);
        self::assertArrayNotHasKey('room_type_display', $broken);
    }

    public function testGuestNamesReadLastCommaFirst(): void
    {
        $extra = OrderLineDecorator::decorate(self::extra(), null, 0, self::context());

        self::assertIsArray($extra['guests_data']);
        self::assertSame('Popescu, Ion', $extra['guests_data'][0]['display_name']);
        self::assertSame('Popescu, Ana', $extra['guests_data'][1]['display_name']);
        self::assertSame('Popescu, Ion', OrderLineDecorator::displayName('Popescu, Ion'));
        self::assertSame('Madonna', OrderLineDecorator::displayName('Madonna'));
        self::assertSame('Popescu /', OrderLineDecorator::displayName('Popescu /'));
    }

    public function testPaymentTermsComeFromTheSettingOneLineEach(): void
    {
        $extra = OrderLineDecorator::decorate(self::extra(), null, 0, self::context());
        $none = OrderLineDecorator::decorate(self::extra(), null, 0, self::context(" \n "));
        $kept = OrderLineDecorator::decorate(self::extra(['payment_terms' => ['Paid in full']]), null, 0, self::context());

        self::assertSame(['30% at booking', '70% 21 days before check-in'], $extra['payment_terms']);
        self::assertArrayNotHasKey('payment_terms', $none);
        self::assertSame(['Paid in full'], $kept['payment_terms']);
    }

    public function testCancellationFeesAddUpTheBookingItemsPerWindow(): void
    {
        $fees = (string) json_encode([
            'api_ref' => '778899',
            'client_ref' => 'ES1A2B3C4D5E',
            'items' => [
                ['item_client_id' => '1', 'item_ref' => 'A', 'fees' => [
                    ['type' => 'cancellation', 'from_date' => '2026-09-20', 'to_date' => '2026-09-30', 'price' => 100.0, 'currency' => 'EUR'],
                    ['type' => 'cancellation', 'from_date' => '2026-10-01', 'to_date' => '2026-10-05', 'price' => 400.5, 'currency' => 'EUR'],
                ]],
                ['item_client_id' => '2', 'item_ref' => 'B', 'fees' => [
                    ['type' => 'cancellation', 'from_date' => '2026-09-20', 'to_date' => '2026-09-30', 'price' => 50.0, 'currency' => 'EUR'],
                ]],
            ],
        ]);

        $extra = OrderLineDecorator::decorate(self::extra(), self::booking(['cancellation_fees_json' => $fees]), 0, self::context());

        self::assertSame([
            'Between 20.09.2026 - 30.09.2026: 150,00 € penalty',
            'Between 01.10.2026 - 05.10.2026: 400,50 € penalty',
        ], $extra['cancellation_fees']);
    }

    public function testNoFeeScheduleMeansNoCancellationLines(): void
    {
        $empty = OrderLineDecorator::decorate(self::extra(), self::booking(), 0, self::context());
        $broken = OrderLineDecorator::decorate(self::extra(), self::booking(['cancellation_fees_json' => '{oops']), 0, self::context());

        self::assertArrayNotHasKey('cancellation_fees', $empty);
        self::assertArrayNotHasKey('cancellation_fees', $broken);
    }

    public function testReferenceLinePrefersTheSupplierReference(): void
    {
        $api = OrderLineDecorator::decorate(self::extra(), self::booking(), 0, self::context());
        $client = OrderLineDecorator::decorate(self::extra(), self::booking(['api_ref' => '', 'status' => 'failed']), 0, self::context());

        self::assertSame('Eurosite booking: 778899 (Confirmed)', $api['eurosite_ref_line']);
        self::assertSame('Eurosite booking: ES1A2B3C4D5E (Failed)', $client['eurosite_ref_line']);
    }

    public function testReferenceLineWithoutStatusDropsTheEmptyBrackets(): void
    {
        $extra = OrderLineDecorator::decorate(self::extra(), self::booking(['status' => '']), 0, self::context());
        $unknown = OrderLineDecorator::decorate(self::extra(), self::booking(['status' => 'on hold']), 0, self::context());

        self::assertSame('Eurosite booking: 778899', $extra['eurosite_ref_line']);
        self::assertSame('Eurosite booking: 778899 (On hold)', $unknown['eurosite_ref_line']);
    }

    public function testStatusAndReferenceAreRecomputedNotKept(): void
    {
        // An admin order edit can write the decorated extra back into the
        // order: the next read must show today's status, not the saved one.
        $stale = self::extra(['eurosite_ref_line' => 'Eurosite booking: 778899 (Pending)']);

        $extra = OrderLineDecorator::decorate($stale, self::booking(), 0, self::context());

        self::assertSame('Eurosite booking: 778899 (Confirmed)', $extra['eurosite_ref_line']);
    }

    public function testViewBookingIdIsSetOnlyWhenKnown(): void
    {
        $linked = OrderLineDecorator::decorate(self::extra(), self::booking(), 27, self::context());
        $unlinked = OrderLineDecorator::decorate(self::extra(), self::booking(), 0, self::context());

        self::assertSame(27, $linked['travel_surrogate_id']);
        self::assertArrayNotHasKey('travel_surrogate_id', $unlinked);
    }

    public function testWithoutABookingRowOnlyTheLinesOwnDataIsUsed(): void
    {
        $extra = OrderLineDecorator::decorate(self::extra(['children_ages' => '5']), null, 0, self::context());

        self::assertArrayNotHasKey('board_name', $extra);
        self::assertArrayNotHasKey('eurosite_ref_line', $extra);
        self::assertSame(1, $extra['children']);
    }

    public function testSourceKeysAreLeftAlone(): void
    {
        $before = self::extra(['children_ages' => '7']);

        $after = OrderLineDecorator::decorate($before, self::booking(), 27, self::context());

        foreach (['eurosite_booking_id', 'booking_id', 'children_ages', 'rooms_data', 'room_name', 'adults', 'hotel_name', 'holder_name'] as $key) {
            self::assertSame($before[$key], $after[$key], $key);
        }
    }

    public function testDecoratesOnlyEurositeLinesWithOneReadPerOrder(): void
    {
        $repo = new class () extends EurositeBookingRepository {
            /** @var list<string> */
            public array $calls = [];

            public function findByIds(array $bookingIds): array
            {
                $this->calls[] = 'findByIds:' . implode(',', $bookingIds);

                return [41 => ['booking_id' => 41, 'meal_name' => 'Half Board', 'api_ref' => '778899', 'status' => 'confirmed']];
            }

            public function surrogateIds(array $bookingIds): array
            {
                $this->calls[] = 'surrogateIds:' . implode(',', $bookingIds);

                return [41 => 27];
            }
        };
        $sphinx = ['product_id' => 5, 'extra' => ['travel_booking' => true, 'sphinx_booking' => true, 'hotel_name' => 'Other']];
        $order = [
            'order_id' => 12,
            'products' => [
                '3001' => ['product_id' => 9, 'extra' => self::extra()],
                '3002' => $sphinx,
            ],
        ];

        $admin = (new OrderLineDecorator($repo, self::context()))->decorateOrder($order, true);

        self::assertSame(['findByIds:41', 'surrogateIds:41'], $repo->calls);
        self::assertIsArray($admin['products']);
        self::assertSame(['3001', '3002'], array_map('strval', array_keys($admin['products'])));
        self::assertSame('Half Board', $admin['products']['3001']['extra']['board_name']);
        self::assertSame(27, $admin['products']['3001']['extra']['travel_surrogate_id']);
        self::assertSame($sphinx, $admin['products']['3002'], 'other providers\' lines are untouched');
    }

    public function testTheStorefrontGetsNoViewBookingLink(): void
    {
        $repo = new class () extends EurositeBookingRepository {
            public int $surrogateReads = 0;

            public function findByIds(array $bookingIds): array
            {
                return [];
            }

            public function surrogateIds(array $bookingIds): array
            {
                ++$this->surrogateReads;

                return [41 => 27];
            }
        };

        $order = (new OrderLineDecorator($repo, self::context()))->decorateOrder(
            ['products' => [['extra' => self::extra()]]],
            false,
        );

        self::assertSame(0, $repo->surrogateReads);
        self::assertIsArray($order['products']);
        self::assertArrayNotHasKey('travel_surrogate_id', $order['products'][0]['extra']);
    }

    public function testAnOrderWithoutEurositeLinesIsNotRead(): void
    {
        $repo = new class () extends EurositeBookingRepository {
            public int $reads = 0;

            public function findByIds(array $bookingIds): array
            {
                ++$this->reads;

                return [];
            }
        };
        $order = ['products' => [['extra' => ['travel_booking' => true, 'novoton_booking' => true]]]];

        self::assertSame($order, (new OrderLineDecorator($repo, self::context()))->decorateOrder($order, true));
        self::assertSame(0, $repo->reads);
    }
}
