<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\ViewModels;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Services\MoneyFormatter;
use Tygh\Addons\TravelCore\ViewModels\BookingPageFactory;

/**
 * The admin's page for one travel booking: what state it is in and what to
 * do about it, the stay as the order page shows it, the supplier's side, the
 * order and the history — booking #27 (Ardo Vel, order #19) as the example.
 */
#[CoversClass(BookingPageFactory::class)]
final class BookingPageFactoryTest extends TestCase
{
    private const string TODAY = '2026-10-30';

    private static function factory(): BookingPageFactory
    {
        $usd = new MoneyFormatter(['symbol' => '$', 'after' => 'N', 'decimals' => 2, 'decimals_separator' => '.', 'thousands_separator' => ',']);

        return new BookingPageFactory($usd, self::TODAY, '%d.%m.%Y');
    }

    /**
     * The ?:travel_bookings row as the controller enriches it.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function booking(array $overrides = []): array
    {
        return $overrides + [
            'booking_id' => 27,
            'provider' => 'novoton',
            'provider_booking_id' => '31',
            'order_id' => 19,
            'hotel_name' => 'ARDO VEL',
            'destination' => 'Velingrad',
            'check_in' => '2026-11-02',
            'check_out' => '2026-11-08',
            'nights' => 6,
            'room_name' => 'Doble room',
            'board_code' => 'BB',
            'adults' => 2,
            'children' => 0,
            'status' => 'pending',
            'created_at' => '2026-10-29 22:11:30',
            'updated_at' => '2026-10-29 22:14:25',
            'linked_at' => '2026-10-29 22:14:25',
            'total_label' => '€499.00',
            'hotel_product_url' => 'https://shop.test/ardo-vel',
            'hotel_product' => [
                'name' => 'Ardo Vel Apartments subtitle',
                'code' => 'NVT4658',
                'url' => 'https://shop.test/admin.php?dispatch=products.update&product_id=296',
            ],
            'provider_display' => [
                'provider_ref' => '',
                'novoton_status' => '',
                'novoton_invoice_id' => '',
                'novoton_confirm_id' => '',
                'status_label' => '<span class="label">pending</span>',
            ],
            'provider_actions' => [],
            'guests_decoded' => [
                ['first_name' => 'fff', 'last_name' => 'fff', 'type' => 'adult', 'is_holder' => true],
                ['name' => 'gg fff', 'type' => 'adult'],
            ],
        ];
    }

    /**
     * The admin booking card of its order line — what the page reads of it.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function card(array $overrides = []): array
    {
        return $overrides + [
            'status' => ['code' => 'pending', 'tone' => 'warn'],
            'reference' => '',
            'kind' => 'hotel',
            'hotel' => ['name' => 'ARDO VEL', 'location' => 'Velingrad, Pazardzhik, Bulgaria'],
            'check_in' => ['date' => '02.11.2026', 'weekday' => 'Monday'],
            'check_out' => ['date' => '08.11.2026', 'weekday' => 'Sunday'],
            'nights' => 6,
            'days' => 0,
            'rooms' => 1,
            'adults' => 2,
            'children' => 0,
            'package' => 'Q-Direct booking prices ArdoVel',
            'room_cards' => [[
                'number' => 1,
                'name' => 'Doble room',
                'board' => 'Bed and Breakfast',
                'adults' => 2,
                'children' => 0,
                'children_ages' => '',
                'supplier_room' => 'Doble room (DOUBLE)',
                'guests' => [
                    ['name' => 'fff, fff', 'is_child' => false, 'age' => 0, 'is_holder' => true],
                    ['name' => 'fff, gg', 'is_child' => false, 'age' => 0, 'is_holder' => false],
                ],
            ]],
            'has_terms' => true,
            'cancel' => ['state' => 'full', 'free_until' => '', 'now' => [], 'then' => []],
            'terms' => [
                'cancel_steps' => [['kind' => 'full', 'is_current' => true, 'to_label' => '02.11.2026', 'percent_label' => '100%', 'amount_label' => '€499.00']],
                'cancel_lines' => [],
                'payment_steps' => [['percent_label' => '100%', 'amount_label' => '€499.00', 'due_label' => '29.10.2026']],
                'payment_lines' => [],
            ],
            'supplier_price' => '€499.00',
            'note' => '',
            'alert' => [],
        ];
    }

    /** @return array<string, mixed> */
    private static function order(): array
    {
        return ['id' => 19, 'total' => '$527.00', 'placed' => '29.10.2026 22:14', 'placed_ts' => (int) strtotime('2026-10-29 22:14:20')];
    }

    public function testABookingTheSupplierHasNotConfirmedSaysSoAndLeadsWithCheckStatus(): void
    {
        $page = self::factory()->build(self::booking(), self::card(), self::order(), 'Novoton');

        self::assertSame(['code' => 'pending', 'tone' => 'warn'], $page['status']);
        self::assertSame(['code' => 'novoton', 'name' => 'Novoton'], $page['provider']);
        self::assertSame(19, $page['order_id']);
        self::assertSame('29.10.2026 22:11', $page['created']);

        self::assertSame(BookingPageFactory::BANNER_AWAITING, $page['banner']['kind']);
        self::assertFalse($page['banner']['has_reference']);
        self::assertSame('29.10.2026 22:11', $page['banner']['sent_at']);
        self::assertSame(3, $page['banner']['days_left'], 'check-in on 2 Nov, three days after 30 Oct');

        self::assertSame(['check_status', 'order'], array_column($page['actions'], 'kind'));
        self::assertSame([true, false], array_column($page['actions'], 'primary'));
        self::assertSame('travel_bookings.check_status?booking_id=27', $page['actions'][0]['href']);
        self::assertTrue($page['actions'][0]['post']);
        self::assertSame('orders.details?order_id=19', $page['actions'][1]['href']);

        self::assertSame('', $page['supplier']['reference']);
        self::assertSame('€499.00', $page['supplier']['price']);
        self::assertSame([], $page['supplier']['fields'], 'empty provider fields are not facts');
    }

    public function testTheStayIsTheOrderCardsWithEachRoomAndItsNovotonName(): void
    {
        $page = self::factory()->build(self::booking(), self::card(), self::order(), 'Novoton');

        self::assertSame(['date' => '02.11.2026', 'weekday' => 'Monday'], $page['facts']['check_in']);
        self::assertSame('Velingrad, Pazardzhik, Bulgaria', $page['facts']['place']);
        self::assertSame('€499.00', $page['facts']['total']);
        self::assertSame('$527.00', $page['facts']['order_total']);

        self::assertSame('Ardo Vel Apartments subtitle', $page['stay']['hotel'], 'the hotel as the order page names it');
        self::assertSame('https://shop.test/admin.php?dispatch=products.update&product_id=296', $page['stay']['hotel_url']);
        self::assertSame('NVT4658', $page['stay']['hotel_code']);
        self::assertSame('https://shop.test/ardo-vel', $page['stay']['storefront_url']);
        self::assertSame('Q-Direct booking prices ArdoVel', $page['stay']['package']);
        self::assertSame('Doble room (DOUBLE)', $page['stay']['rooms'][0]['supplier_room']);
        self::assertSame(
            [
                ['name' => 'fff, fff', 'is_child' => false, 'age' => '', 'is_holder' => true],
                ['name' => 'fff, gg', 'is_child' => false, 'age' => '', 'is_holder' => false],
            ],
            $page['stay']['rooms'][0]['guests'],
        );

        self::assertSame('full', $page['terms']['cancel']['state']);
        self::assertSame('100%', $page['terms']['lists']['cancel_steps'][0]['percent_label']);
        self::assertSame(['id' => 19, 'total' => '$527.00', 'placed' => '29.10.2026 22:14'], $page['order']);
    }

    public function testTheHistoryIsNewestFirstWithoutRepeatingAMoment(): void
    {
        $page = self::factory()->build(self::booking(), self::card(), self::order(), 'Novoton');

        // Updated at 22:14, as the order was placed: no separate "updated".
        self::assertSame(
            [
                ['kind' => 'order', 'at' => '29.10.2026 22:14', 'order_id' => 19],
                ['kind' => 'created', 'at' => '29.10.2026 22:11', 'order_id' => 0],
            ],
            $page['history'],
        );
    }

    public function testAFailedBookingLeadsWithTheProvidersRemedy(): void
    {
        $booking = self::booking([
            'status' => 'failed',
            'provider_actions' => [
                ['name' => 'check_status', 'label' => 'Check Status', 'url' => 'travel_bookings.provider_action', 'method' => 'POST', 'booking_id' => '31', 'extra_params' => ['provider_action' => 'resinfo']],
                ['name' => 'request_alternatives', 'label' => 'Request alternatives', 'url' => 'travel_bookings.provider_action', 'method' => 'POST', 'booking_id' => '31', 'extra_params' => ['provider_action' => 'request_alternatives']],
                ['name' => 'alternatives', 'label' => 'Alternatives', 'url' => 'travel_bookings.view&booking_id=31&tab=alternatives', 'method' => 'GET'],
            ],
        ]);
        $card = self::card(['status' => ['code' => 'failed', 'tone' => 'bad'], 'alert' => ['kind' => 'failed', 'error' => 'Room sold out', 'actions' => []]]);

        $page = self::factory()->build($booking, $card, self::order(), 'Novoton');

        self::assertSame(BookingPageFactory::BANNER_FAILED, $page['banner']['kind']);
        self::assertSame('Room sold out', $page['banner']['error']);
        // Checking the status again duplicates Check status; a link back to
        // this page leads nowhere.
        self::assertSame(['provider', 'check_status', 'order'], array_column($page['actions'], 'kind'));
        self::assertSame([true, false, false], array_column($page['actions'], 'primary'));
        $remedy = $page['actions'][0];
        self::assertSame('Request alternatives', $remedy['label']);
        self::assertTrue($remedy['post']);
        self::assertStringStartsWith('travel_bookings.provider_action?provider=novoton', $remedy['href']);
        self::assertStringContainsString('provider_action=request_alternatives', $remedy['href']);
        self::assertStringContainsString('booking_id=31', $remedy['href']);
        self::assertStringContainsString('return_url=' . rawurlencode('travel_bookings.view?booking_id=27'), $remedy['href']);
    }

    public function testAConfirmedBookingNeedsNoBanner(): void
    {
        $page = self::factory()->build(
            self::booking(['status' => 'confirmed']),
            self::card(['status' => ['code' => 'confirmed', 'tone' => 'ok'], 'reference' => 'NT 311921']),
            self::order(),
            'Novoton',
        );

        self::assertSame([], $page['banner']);
        self::assertSame('NT 311921', $page['supplier']['reference']);
        self::assertFalse($page['actions'][0]['primary'], 'nothing is urgent');
    }

    public function testABookingStillInACartReadsFromItsOwnRow(): void
    {
        $booking = self::booking(['order_id' => 0, 'linked_at' => null]);
        $booking['provider_page']['supplier_rooms'] = ['Doble room (DOUBLE)'];

        $page = self::factory()->build($booking, [], [], 'Novoton');

        self::assertSame(['code' => 'pending', 'tone' => 'warn'], $page['status']);
        self::assertSame('02.11.2026', $page['facts']['check_in']['date']);
        self::assertSame('Velingrad', $page['facts']['place']);
        self::assertSame(['check_status'], array_column($page['actions'], 'kind'));
        self::assertSame([], $page['terms']);
        self::assertSame([], $page['order']);

        $room = $page['stay']['rooms'][0];
        self::assertSame('Doble room', $room['name']);
        self::assertSame('BB', $room['board']);
        self::assertSame('Doble room (DOUBLE)', $room['supplier_room']);
        self::assertSame(['fff fff', 'gg fff'], array_column($room['guests'], 'name'));
        self::assertSame([true, false], array_column($room['guests'], 'is_holder'));

        self::assertSame(['updated', 'created'], array_column($page['history'], 'kind'));
    }

    public function testOnlyProviderFieldsWithAValueAreShownButTheTechnicalListHasThemAll(): void
    {
        $booking = self::booking();
        $booking['provider_display'] = [
            'provider_ref' => 'NT 311921',
            'status_label' => '<span class="label">ASK</span>',
            'novoton_status' => 'ASK',
            'novoton_invoice_id' => '311921',
            'novoton_confirm_id' => '',
            'room_codes' => ['Doble room'],
        ];

        $page = self::factory()->build($booking, self::card(), self::order(), 'Novoton');

        self::assertSame(
            [['label' => 'Novoton status', 'value' => 'ASK'], ['label' => 'Novoton invoice id', 'value' => '311921']],
            $page['supplier']['fields'],
        );
        self::assertSame(
            ['Novoton status', 'Novoton invoice id', 'Novoton confirm id', 'Room codes'],
            array_column($page['technical'], 'label'),
        );
        self::assertSame([false, false, false, true], array_column($page['technical'], 'is_pre'));
    }

    public function testTheTechnicalDetailsEndWithTheBookingsExchangeWithTheSupplier(): void
    {
        $booking = self::booking();
        $booking['provider_display'] = ['novoton_status' => 'ASK'];
        $booking['provider_page'] = ['technical' => [
            ['label' => 'Notes', 'value' => 'API submission disabled - test mode', 'is_pre' => false],
            ['label' => 'Reservation request (hotel_res_RQ)', 'value' => '<hotel_res_RQ><IdHotel>4658</IdHotel></hotel_res_RQ>', 'is_pre' => true],
        ]];

        $page = self::factory()->build($booking, self::card(), self::order(), 'Novoton');

        self::assertSame(['Novoton status', 'Notes', 'Reservation request (hotel_res_RQ)'], array_column($page['technical'], 'label'));
        self::assertSame([false, false, true], array_column($page['technical'], 'is_pre'));
        self::assertSame('<hotel_res_RQ><IdHotel>4658</IdHotel></hotel_res_RQ>', $page['technical'][2]['value']);
    }

    public function testTheCountdownStopsOnceTheStayHasBegun(): void
    {
        $today = self::factory()->build(self::booking(['check_in' => self::TODAY]), [], [], 'Novoton');
        $begun = self::factory()->build(self::booking(['check_in' => '2026-10-28']), [], [], 'Novoton');

        self::assertSame(0, $today['banner']['days_left']);
        self::assertSame(-1, $begun['banner']['days_left']);
    }

    public function testWithoutItsProductTheHotelKeepsTheSuppliersNameUnlinked(): void
    {
        $booking = self::booking();
        $booking['hotel_product'] = ['name' => '', 'code' => '', 'url' => ''];

        $withCard = self::factory()->build($booking, self::card(), self::order(), 'Novoton');
        $withoutCard = self::factory()->build(['hotel_name' => 'ARDO VEL'] + $booking, [], [], 'Novoton');

        self::assertSame('ARDO VEL', $withCard['stay']['hotel']);
        self::assertSame('ARDO VEL', $withoutCard['stay']['hotel']);
        self::assertSame('', $withCard['stay']['hotel_url']);
        self::assertSame('', $withCard['stay']['hotel_code']);
    }
}
