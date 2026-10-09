<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\ViewModels;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Services\MoneyFormatter;
use Tygh\Addons\TravelCore\ViewModels\OrderBookingCardFactory;

/**
 * The booking card under a travel line of an order: the admin's version
 * (status, supplier reference, supplier price, failure alert) and the
 * customer's, which must never carry anything about the provider.
 */
#[CoversClass(OrderBookingCardFactory::class)]
final class OrderBookingCardFactoryTest extends TestCase
{
    private const string TODAY = '2026-10-08';

    private static function factory(): OrderBookingCardFactory
    {
        $ron = new MoneyFormatter(['symbol' => 'RON', 'after' => 'Y', 'decimals' => 2, 'decimals_separator' => ',', 'thousands_separator' => '.']);

        return new OrderBookingCardFactory($ron, self::TODAY, '%d.%m.%Y');
    }

    /**
     * A eurosite order line as fn_get_order_info hands it over: two rooms,
     * guests formatted by travel_core, the board added by eurosite.
     *
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private static function item(array $extra = [], float $price = 7940.0): array
    {
        return [
            'item_id' => '3001',
            'product_id' => 9,
            'product' => 'Hotel Laguna Beach',
            'price' => $price,
            'amount' => 1,
            'extra' => $extra + [
                'travel_booking' => true,
                'eurosite_booking_id' => 41,
                'hotel_name' => 'Hotel Laguna Beach',
                'check_in' => '2026-11-16',
                'check_out' => '2026-11-22',
                'nights' => 6,
                'num_rooms' => 2,
                'rooms_data' => '[{"room_name":"Double Room","adults":2,"children":0,"price":640},{"room_name":"Double Room","adults":1,"children":1,"childrenAges":[7]}]',
                'room_name' => 'Double Room',
                'board_name' => 'Half Board',
                'adults' => 3,
                'children_ages' => '7',
                'holder_name' => 'Popescu / Ion',
                'guests_data' => [
                    ['display_name' => 'Popescu, Ion', 'name' => 'Popescu / Ion', 'type' => 'adult', 'is_holder' => true, 'room' => 1],
                    ['display_name' => 'Popescu, Eva', 'name' => 'Popescu / Eva', 'type' => 'adult', 'is_holder' => false, 'room' => 1],
                    ['display_name' => 'Popescu, Ana', 'name' => 'Popescu / Ana', 'type' => 'adult', 'is_holder' => true, 'room' => 2],
                    ['display_name' => 'Popescu, Mia', 'name' => 'Popescu / Mia', 'type' => 'child', 'age' => 7, 'is_holder' => false, 'room' => 2],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function booking(array $overrides = []): array
    {
        return $overrides + [
            'booking_id' => 27,
            'provider' => 'eurosite',
            'provider_booking_id' => '41',
            'status' => 'confirmed',
            'total_price' => '1396.50',
            'currency' => 'EUR',
            'created_at' => '2026-10-06 14:33:00',
            'updated_at' => '2026-10-08 06:00:00',
        ];
    }

    /** @return array<string, mixed> */
    private static function facts(array $overrides = []): array
    {
        return $overrides + [
            'provider' => 'eurosite',
            'provider_booking_id' => '41',
            'reference' => '778899',
            'our_reference' => 'ES1A2B3C4D5E',
            'note' => 'Late check-in on request',
        ];
    }

    /** @return array<string, mixed> */
    private static function meta(array $overrides = []): array
    {
        return $overrides + ['provider_name' => 'Eurosite', 'supplier_price' => '1.396,50 €', 'actions' => []];
    }

    public function testTheAdminCardNamesTheBookingAtItsSupplier(): void
    {
        $card = self::factory()->build(self::item(), 'admin', self::booking(), self::facts(), self::meta());

        self::assertSame('admin', $card['audience']);
        self::assertSame(['code' => 'eurosite', 'name' => 'Eurosite'], $card['provider']);
        self::assertSame(['code' => 'confirmed', 'tone' => 'ok'], $card['status']);
        self::assertSame('778899', $card['reference']);
        self::assertSame('ES1A2B3C4D5E', $card['our_reference']);
        self::assertSame(27, $card['booking_id']);
        self::assertSame('Late check-in on request', $card['note']);
        self::assertSame('1.396,50 €', $card['supplier_price']);
        self::assertSame('06.10.2026 14:33', $card['booked_at']);
        self::assertSame('08.10.2026 06:00', $card['updated_at']);
        self::assertSame([], $card['alert']);
    }

    public function testTheCustomerCardCarriesNothingAboutTheProvider(): void
    {
        $card = self::factory()->build(
            self::item(),
            'customer',
            self::booking(),
            self::facts(['error' => 'Room no longer available']),
            self::meta(['actions' => [['label' => 'Retry', 'href' => 'x', 'post' => true, 'confirm' => true]]]),
        );

        self::assertSame('customer', $card['audience']);
        self::assertSame([], $card['provider']);
        self::assertSame('', $card['reference']);
        self::assertSame('', $card['our_reference']);
        self::assertSame(0, $card['booking_id']);
        self::assertSame('', $card['note']);
        self::assertSame('', $card['supplier_price']);
        self::assertSame('', $card['booked_at']);
        self::assertSame([], $card['alert']);
        // Supplier per-room prices (rooms_data) never show either.
        foreach ($card['room_list'] as $room) {
            self::assertSame('', $room['price']);
        }
        $json = (string) json_encode($card);
        foreach (['Eurosite', 'eurosite', '778899', 'ES1A2B3C4D5E', '1.396,50', 'Room no longer available', 'Late check-in'] as $secret) {
            self::assertStringNotContainsString($secret, $json, $secret);
        }
    }

    public function testTheCustomerSeesAwaitingConfirmationWhateverWentWrong(): void
    {
        $factory = self::factory();
        foreach (['failed', 'pending', 'ask', 'waiting'] as $status) {
            self::assertSame(['code' => 'pending', 'tone' => 'warn'], $factory->status(['status' => $status], [], false), $status);
        }
        self::assertSame(['code' => 'pending', 'tone' => 'warn'], $factory->status(['status' => 'pending'], ['not_sent' => true], false));
        self::assertSame(['code' => 'confirmed', 'tone' => 'ok'], $factory->status(['status' => 'confirmed'], [], false));
        self::assertSame(['code' => 'cancelled', 'tone' => 'muted'], $factory->status(['status' => 'cancelled'], [], false));
        self::assertSame(['code' => '', 'tone' => ''], $factory->status([], [], false));
    }

    public function testTheAdminSeesWhatHappened(): void
    {
        $factory = self::factory();

        self::assertSame(['code' => 'failed', 'tone' => 'bad'], $factory->status(['status' => 'failed'], [], true));
        self::assertSame(['code' => 'ask', 'tone' => 'warn'], $factory->status(['status' => 'ask'], [], true));
        self::assertSame(['code' => 'not_sent', 'tone' => 'bad'], $factory->status(['status' => 'pending'], ['not_sent' => true], true));
        // A row the supplier already answered is never "not sent".
        self::assertSame(['code' => 'confirmed', 'tone' => 'ok'], $factory->status(['status' => 'confirmed'], ['not_sent' => true], true));
        // No mirror row: the provider's own status.
        self::assertSame(['code' => 'pending', 'tone' => 'warn'], $factory->status([], ['status' => 'pending'], true));
    }

    public function testAFailedBookingRaisesTheAlertWithTheSuppliersAnswer(): void
    {
        $actions = [['label' => 'Retry Booking', 'href' => 'travel_bookings.provider_action?provider=sphinx', 'post' => true, 'confirm' => true]];
        $card = self::factory()->build(
            self::item(),
            'admin',
            self::booking(['status' => 'failed']),
            self::facts(['error' => 'Room no longer available']),
            self::meta(['actions' => $actions]),
        );
        $notSent = self::factory()->build(self::item(), 'admin', self::booking(['status' => 'pending']), self::facts(['not_sent' => true]), self::meta());

        self::assertSame(['kind' => 'failed', 'error' => 'Room no longer available', 'actions' => $actions], $card['alert']);
        self::assertSame('not_sent', $notSent['alert']['kind']);
    }

    public function testEveryRoomIsABlockWithItsBoardAndGuests(): void
    {
        $card = self::factory()->build(self::item(), 'admin', self::booking(), self::facts(), self::meta());

        self::assertCount(2, $card['room_cards']);
        [$first, $second] = $card['room_cards'];
        self::assertSame('Double Room', $first['name']);
        // eurosite stores the board for the stay only.
        self::assertSame('Half Board', $first['board']);
        self::assertSame('Half Board', $second['board']);
        self::assertSame(1, $second['children']);
        self::assertSame('7', $second['children_ages']);
        self::assertSame(['Popescu, Ion', 'Popescu, Eva'], array_column($first['guests'], 'name'));
        self::assertSame(['Popescu, Ana', 'Popescu, Mia'], array_column($second['guests'], 'name'));
    }

    public function testASingleRoomIsABlockToo(): void
    {
        $item = self::item(['num_rooms' => 1, 'rooms_data' => '', 'adults' => 2, 'children_ages' => '', 'guests_data' => [
            ['display_name' => 'Ionescu, Maria', 'type' => 'adult', 'is_holder' => true, 'room' => 1],
        ]]);

        $card = self::factory()->build($item, 'customer', self::booking());

        self::assertCount(1, $card['room_cards']);
        self::assertSame(['Double Room', 'Half Board', 2], [$card['room_cards'][0]['name'], $card['room_cards'][0]['board'], $card['room_cards'][0]['adults']]);
        self::assertSame(['Ionescu, Maria'], array_column($card['room_cards'][0]['guests'], 'name'));
    }

    public function testExactlyOneLeadGuest(): void
    {
        // The order's guest list marks the first adult AND any name holding
        // the holder's: two "lead" guests here. The holder's own name wins.
        $card = self::factory()->build(self::item(), 'admin', self::booking(), self::facts(), self::meta());
        $leads = array_values(array_filter(
            array_merge(...array_map(static fn (array $r): array => $r['guests'], $card['room_cards'])),
            static fn (array $g): bool => $g['is_holder'] === true,
        ));

        self::assertSame(['Popescu, Ion'], array_column($leads, 'name'));
        self::assertSame('Popescu, Ion', $card['lead_guest']);

        $byFlag = OrderBookingCardFactory::oneLead([
            ['name' => 'A, B', 'is_holder' => false],
            ['name' => 'C, D', 'is_holder' => true],
            ['name' => 'E, F', 'is_holder' => true],
        ], 'Somebody Else');
        self::assertSame([false, true, false], array_column($byFlag, 'is_holder'));
    }

    public function testACircuitReadsAsDepartureAndReturnWithItsMealPlan(): void
    {
        $item = self::item([
            'eurosite_booking_id' => 0,
            'booking_type' => 'circuit',
            'check_in' => '2026-11-08',
            // stored as departure + days: a day late
            'check_out' => '2026-11-16',
            'nights' => 7,
            'num_rooms' => 1,
            'rooms_data' => [],
            'board_name' => 'Bus',
            'board_id' => 'bus',
        ]);
        $facts = ['provider' => 'sphinx', 'kind' => 'circuit', 'transport' => 'bus', 'departure' => 'Bucharest'];

        $old = self::factory()->build($item, 'customer', [], $facts);
        $new = self::factory()->build($item, 'customer', [], $facts + ['meals' => 'Half Board']);

        self::assertSame('circuit', $old['kind']);
        self::assertSame('15.11.2026', $old['check_out']['date']);
        self::assertSame(8, $old['days']);
        self::assertSame('bus', $old['transport']);
        self::assertSame('Bucharest', $old['departure']);
        self::assertSame('', $old['board'], 'the transport is not a meal plan');
        self::assertSame('Half Board', $new['board']);
    }

    public function testAPackageKeepsItsTransportAndAHotelHasNone(): void
    {
        $package = self::factory()->build(self::item(['booking_type' => 'package', 'transport_type' => 'flight']), 'customer', [], ['kind' => 'package', 'transport' => 'flight']);
        $hotel = self::factory()->build(self::item(), 'customer');
        $forged = self::factory()->build(self::item(), 'customer', [], ['kind' => '<script>', 'transport' => '"><img>']);

        self::assertSame(['package', 'flight'], [$package['kind'], $package['transport']]);
        self::assertSame(['hotel', '', 0], [$hotel['kind'], $hotel['transport'], $hotel['days']]);
        self::assertSame(['hotel', ''], [$forged['kind'], $forged['transport']]);
    }

    public function testThePlacedOrderIsNotEditedOrRepriced(): void
    {
        $card = self::factory()->build(self::item(['novoton_booking_id' => 5, 'price_before_correction' => 900, 'total_price' => 950]), 'customer');

        self::assertSame('', $card['edit_url']);
        self::assertSame([], $card['price_change']);
    }

    public function testADepositOrderShowsItsBalanceForThisLine(): void
    {
        $deposit = ['travel_deposit' => ['ratio' => 0.3, 'balance_due' => '2026-10-26', 'full' => 6380.0, 'deposit' => 1914.0, 'balance' => 4466.0]];
        $balances = [
            ['balance_id' => 1, 'item_id' => '9999', 'due_date' => '2026-10-26', 'status' => 'paid', 'balance_order_id' => 55],
            ['balance_id' => 2, 'item_id' => '3001', 'due_date' => '2026-10-26', 'status' => 'open', 'pay_query' => 'travel_balance.pay?balance_id=2&key=k', 'reminders_sent' => '1'],
        ];

        $card = self::factory()->build(self::item($deposit, 1914.0), 'customer', [], [], [], [], $balances);

        self::assertSame([
            'state' => 'open',
            'amount' => '4.466,00 RON',
            'due' => '26.10.2026',
            'pay_query' => 'travel_balance.pay?balance_id=2&key=k',
            'reminders' => 1,
            'balance_order_id' => 0,
        ], $card['balance']);
    }

    public function testABalanceRowFromBeforeItemIdsMatchesByDueDate(): void
    {
        $deposit = ['travel_deposit' => ['ratio' => 0.3, 'balance_due' => '2026-10-26', 'full' => 6380.0, 'deposit' => 1914.0, 'balance' => 4466.0]];
        $paid = [['balance_id' => 1, 'item_id' => '', 'due_date' => '2026-10-26', 'status' => 'paid', 'balance_order_id' => 55]];
        $late = [['balance_id' => 1, 'item_id' => '3001', 'due_date' => '2026-10-26', 'status' => 'open', 'pay_query' => 'q']];

        $paidCard = self::factory()->build(self::item($deposit, 1914.0), 'customer', [], [], [], [], $paid);
        $lateCard = (new OrderBookingCardFactory(new MoneyFormatter(['symbol' => 'RON', 'after' => 'Y']), '2026-10-27', '%d.%m.%Y'))
            ->build(self::item($deposit, 1914.0), 'customer', [], [], [], [], $late);

        self::assertSame(['paid', '', 55], [$paidCard['balance']['state'], $paidCard['balance']['pay_query'], $paidCard['balance']['balance_order_id']]);
        self::assertSame(['overdue', 'q'], [$lateCard['balance']['state'], $lateCard['balance']['pay_query']]);
        self::assertSame([], self::factory()->build(self::item(), 'customer')['balance'], 'paid in full');
    }

    public function testANonTravelLineHasNoCard(): void
    {
        self::assertSame([], self::factory()->build(['product_id' => 1, 'price' => 10, 'extra' => []], 'admin'));
    }
}
