<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\ViewModels;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Dto\Hotel\HotelSeoData;
use Tygh\Addons\TravelCore\Services\MoneyFormatter;
use Tygh\Addons\TravelCore\ViewModels\CartBookingCardFactory;

/**
 * The checkout / cart booking card: every value prepared once, for every
 * provider, so the template carries no provider branch and no arithmetic.
 */
final class CartBookingCardFactoryTest extends TestCase
{
    private static function factory(string $today = '2026-09-30'): CartBookingCardFactory
    {
        $usd = new MoneyFormatter(['symbol' => '$', 'after' => 'N', 'decimals' => 2, 'decimals_separator' => '.', 'thousands_separator' => ',']);

        return new CartBookingCardFactory($usd, $today, '%m/%d/%Y');
    }

    /**
     * The line from the checkout screenshot: novoton, one room, two adults.
     *
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private static function line(array $extra = [], float $price = 468.75): array
    {
        return [
            'product_id' => 42,
            'product' => 'Admiral Hotel subtitle',
            'price' => $price,
            'extra' => $extra + [
                'travel_booking' => true,
                'novoton_booking' => true,
                'novoton_booking_id' => 17,
                'hotel_name' => 'Admiral Hotel',
                'room_type_display' => 'Double Room (DBL 2+0 DELUXE NO BALCONY)',
                'board_name' => 'Ultra All Inclusive (ULTRA ALL INCL)',
                'check_in' => '2026-10-05',
                'check_out' => '2026-10-11',
                'nights' => 6,
                'adults' => 2,
                'children' => 0,
                'num_rooms' => 1,
                'guests_data' => json_encode([
                    'room1_adult_1' => ['name' => 'Ion Popescu', 'type' => 'adult', 'room' => 1, 'is_holder' => 1],
                    'room1_adult_2' => ['first_name' => 'Maria', 'last_name' => 'Popescu', 'type' => 'adult', 'room' => 1, 'is_holder' => 0],
                ]),
                'total_price' => 468.75,
            ],
        ];
    }

    private static function hotel(): HotelSeoData
    {
        return new HotelSeoData(
            hotelId: '123',
            providerName: 'novoton',
            name: 'ADMIRAL HOTEL',
            classification: 5,
            city: 'GOLDEN SANDS',
            country: 'Bulgaria',
            address: 'Golden Sands Resort, 9007 Varna',
        );
    }

    public function testNonTravelLinesGetNoCard(): void
    {
        self::assertSame([], self::factory()->build(['product_id' => 1, 'price' => 10.0, 'extra' => []]));
    }

    public function testSingleRoomCardFromTheCheckoutScreenshot(): void
    {
        $card = self::factory()->build(self::line(), '991', self::hotel(), ['icon' => ['image_path' => 'x.jpg']]);

        // Hotel identity: the booked name, the owner's stars and destination
        // (never the street address — too long for the sidebar).
        self::assertSame('Admiral Hotel', $card['hotel']['name']);
        self::assertSame(5, $card['hotel']['stars']);
        self::assertSame('Golden Sands, Bulgaria', $card['hotel']['location']);
        self::assertSame(['icon' => ['image_path' => 'x.jpg']], $card['hotel']['image_pair']);

        // Store date format, weekday as its own label.
        self::assertSame(['date' => '10/05/2026', 'weekday' => 'Monday'], $card['check_in']);
        self::assertSame(['date' => '10/11/2026', 'weekday' => 'Sunday'], $card['check_out']);
        self::assertTrue($card['show_weekday']);
        self::assertSame(6, $card['nights']);
        self::assertSame('$78.13', $card['per_night']);

        // One party line, supplier codes split off the names.
        self::assertSame(1, $card['rooms']);
        self::assertSame(2, $card['adults']);
        self::assertSame(0, $card['children']);
        self::assertSame(['name' => 'Double Room', 'code' => 'DBL 2+0 DELUXE NO BALCONY'], $card['room']);
        self::assertSame('Ultra All Inclusive', $card['board']);
        self::assertSame([], $card['room_list']);

        self::assertSame(2, $card['guest_count']);
        self::assertSame('Ion Popescu', $card['lead_guest']);
        self::assertTrue($card['guests'][0]['is_holder']);
        self::assertSame('Popescu Maria', $card['guests'][1]['name'], 'no display name: "last first", as before');

        self::assertSame('novoton_booking.edit_booking?booking_id=17&cart_id=991', $card['edit_url']);
        self::assertFalse($card['has_terms']);
        self::assertSame('', $card['cancel']['state']);
        self::assertSame([], $card['deposit']);
        self::assertSame([], $card['price_change']);
    }

    public function testWeekdayIsNotRepeatedWhenTheStoreFormatPrintsIt(): void
    {
        $usd = new MoneyFormatter(['symbol' => '$']);
        $card = (new CartBookingCardFactory($usd, '2026-09-30', '%a, %d %b %Y'))->build(self::line());

        self::assertFalse($card['show_weekday']);
        self::assertSame('Mon, 05 Oct 2026', $card['check_in']['date']);
    }

    public function testHotelFallsBackToTheLineWhenNoProviderOwnsTheProduct(): void
    {
        $card = self::factory()->build(self::line([
            'hotel_name' => '',
            'hotel_city' => 'NISIPURILE DE AUR',
            'hotel_country' => 'Bulgaria',
        ]));

        self::assertSame('Admiral Hotel subtitle', $card['hotel']['name']);
        self::assertSame(0, $card['hotel']['stars']);
        self::assertSame('Nisipurile De Aur, Bulgaria', $card['hotel']['location']);
    }

    public function testChildrenAreCountedFromTheirAgesWhenTheLineHasNoCount(): void
    {
        // eurosite: children_ages "5,9", no children count.
        $card = self::factory()->build(self::line(['children' => 0, 'children_ages' => '5,9']));

        self::assertSame(2, $card['children']);
        self::assertSame('5, 9', $card['children_ages']);
    }

    public function testEditLinkFollowsTheProviderAndEurositeHasNone(): void
    {
        $sphinx = self::factory()->build(self::line([
            'travel_provider' => 'sphinx',
            'travel_booking_id' => 5,
            'novoton_booking_id' => 0,
        ]), 'abc');
        self::assertSame('sphinx_booking.edit_booking?booking_id=5&cart_id=abc', $sphinx['edit_url']);

        $eurosite = self::factory()->build(self::line(['novoton_booking_id' => 0, 'eurosite_booking_id' => 9]));
        self::assertSame('', $eurosite['edit_url']);
    }

    public function testFreeCancellationHeadlineWithWhatFollows(): void
    {
        $card = self::factory()->build(self::line(), '', null, [], [
            'cancel_windows' => [
                ['to' => '2026-10-01', 'percent' => 0],
                ['to' => '2026-10-04', 'percent' => 50],
                ['to' => null, 'percent' => 100, 'no_show' => true],
            ],
            'payment_rows' => [['due' => null, 'percent' => 100]],
        ]);

        self::assertTrue($card['has_terms']);
        self::assertSame('free', $card['cancel']['state']);
        self::assertSame('10/01/2026', $card['cancel']['free_until']);
        self::assertSame('50%', $card['cancel']['then']['percent_label']);
        self::assertSame('$234.38', $card['cancel']['then']['amount_label']);
        self::assertCount(3, $card['terms']['cancel_steps']);
        self::assertCount(1, $card['terms']['payment_steps']);
        self::assertSame([], $card['terms']['cancel_lines'], 'prose only without a timeline');
    }

    public function testCancellingAlreadyCostsSomething(): void
    {
        $partial = self::factory()->build(self::line(), '', null, [], [
            'cancel_windows' => [['from' => '2026-09-20', 'to' => '2026-10-04', 'percent' => 30]],
        ]);
        self::assertSame('partial', $partial['cancel']['state']);
        self::assertSame('', $partial['cancel']['free_until']);
        self::assertSame('30%', $partial['cancel']['now']['percent_label']);

        $full = self::factory()->build(self::line(), '', null, [], [
            'cancel_windows' => [['from' => '2026-09-20', 'to' => '2026-10-11', 'percent' => 100]],
        ]);
        self::assertSame('full', $full['cancel']['state']);
    }

    public function testProseTermsWhenTheProviderHasNoStructuredOnes(): void
    {
        $card = self::factory()->build(self::line(), '', null, [], [
            'cancel_lines' => ['Free cancellation until 01.10.2026', ' ', 'Then 100%'],
            'payment_lines' => ['100% on booking'],
        ]);

        self::assertTrue($card['has_terms']);
        self::assertSame('', $card['cancel']['state']);
        self::assertSame(['Free cancellation until 01.10.2026', 'Then 100%'], $card['terms']['cancel_lines']);
        self::assertSame(['100% on booking'], $card['terms']['payment_lines']);
    }

    public function testMultiRoomLineListsEachRoomWithItsGuests(): void
    {
        $card = self::factory()->build(self::line([
            'num_rooms' => 2,
            'board_name' => 'All Inclusive',
            'rooms_data' => [
                ['room_type_display' => 'Double Room (DBL 2+0 STANDARD)', 'price' => 720, 'adults' => 2, 'children' => 0, 'board_name' => 'All Inclusive'],
                ['room_type_display' => 'Double Room (DBL 1+1)', 'price' => 566.4, 'adults' => 1, 'children' => 1, 'children_ages_str' => '7 years old', 'board_name' => 'All Inclusive'],
            ],
            'guests_data' => [
                ['name' => 'Ion Popescu', 'type' => 'adult', 'room' => 1, 'is_holder' => true],
                ['name' => 'Maria Popescu', 'type' => 'adult', 'room' => 1],
                ['name' => 'Ana Ionescu', 'type' => 'adult', 'room' => 2],
                ['name' => 'Luca Ionescu', 'type' => 'child', 'age' => 7, 'room' => 2],
            ],
        ], 1286.4));

        self::assertSame(2, $card['rooms']);
        self::assertSame(3, $card['adults'], 'summed across rooms');
        self::assertSame(1, $card['children']);
        self::assertSame([['qty' => 2, 'name' => 'Double Room']], $card['room_lines']);
        self::assertCount(2, $card['room_list']);

        $second = $card['room_list'][1];
        self::assertSame(2, $second['number']);
        self::assertSame('Double Room', $second['name']);
        self::assertSame('DBL 1+1', $second['code']);
        self::assertSame('$566.40', $second['price']);
        self::assertSame('7', $second['children_ages']);
        self::assertSame(['Ana Ionescu', 'Luca Ionescu'], array_column($second['guests'], 'name'));
        self::assertTrue($second['guests'][1]['is_child']);
        self::assertSame(7, $second['guests'][1]['age']);
        self::assertSame('$214.40', $card['per_night'], '1,286.40 over 6 nights');
    }

    public function testGuestsWithoutARoomNumberStayInTheFirstRoom(): void
    {
        $card = self::factory()->build(self::line([
            'num_rooms' => 2,
            'rooms_data' => json_encode([['room_name' => 'A'], ['room_name' => 'B']]),
            'guests_data' => [['name' => 'Ion Popescu'], ['name' => 'Maria Popescu']],
        ]));

        self::assertCount(2, $card['room_list'][0]['guests']);
        self::assertSame([], $card['room_list'][1]['guests']);
    }

    public function testDepositLineShowsTheSplitOfTheFullPrice(): void
    {
        $card = self::factory()->build(self::line([
            'travel_deposit' => ['ratio' => 0.3, 'balance_due' => '2027-06-12', 'full' => 1286.4, 'deposit' => 385.92, 'balance' => 900.48],
        ], 385.92));

        self::assertSame([
            'full' => '$1,286.40',
            'deposit' => '$385.92',
            'balance' => '$900.48',
            'balance_due' => '06/12/2027',
            'percent' => 30,
        ], $card['deposit']);
        self::assertSame('$214.40', $card['per_night'], 'per night of the stay, not of the deposit');
    }

    public function testSupplierPriceCorrection(): void
    {
        $up = self::factory()->build(self::line(['price_before_correction' => 440.0, 'total_price' => 468.75]));
        self::assertSame(['old' => '$440.00', 'new' => '$468.75', 'up' => true], $up['price_change']);

        $same = self::factory()->build(self::line(['price_before_correction' => 468.75]));
        self::assertSame([], $same['price_change']);
    }

    public function testSplitCode(): void
    {
        self::assertSame(['name' => 'Double Room', 'code' => 'DBL 2+0 DELUXE NO BALCONY'], CartBookingCardFactory::splitCode('Double Room (DBL 2+0 DELUXE NO BALCONY)'));
        self::assertSame(['name' => 'Camera dublă', 'code' => ''], CartBookingCardFactory::splitCode('Camera dublă'));
        self::assertSame(['name' => 'All Inclusive', 'code' => ''], CartBookingCardFactory::splitCode('All Inclusive (all inclusive)'));
        self::assertSame(['name' => '(DBL)', 'code' => ''], CartBookingCardFactory::splitCode('(DBL)'));
        self::assertSame(['name' => 'Suite (Sea View) Deluxe', 'code' => ''], CartBookingCardFactory::splitCode('Suite (Sea View) Deluxe'));
    }
}
