<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Services\DesignARates;
use Tygh\Addons\TravelCore\Results\RateOffer;
use Tygh\Addons\TravelCore\Results\RoomResultsBuilder;
use Tygh\Addons\TravelCore\Services\MoneyFormatter;
use Tygh\Addons\TravelCore\ViewModels\TermsTimelineFactory;

/**
 * Novoton's search rows → the Design A room cards' rates, checked against
 * the store's own export: Admiral, Golden Sands (hotel 476), 10–17 Jul
 * 2027, two adults — eight room types, all Ultra All Inclusive, all
 * Early Booking −30%, free cancellation until 30 Jun 2027, 20% by 30 Nov
 * 2026 and the rest by 26 Jun 2027.
 */
final class DesignARatesTest extends TestCase
{
    private const TODAY = '2026-10-26';

    private const CANCEL = '<TermsOfCancellation>'
        . '<Penalty tillDate="2027-06-30" Type="Over Nights">0</Penalty>'
        . '<Penalty tillDate="2027-07-09" Type="Over Nights">3</Penalty>'
        . '<Penalty Type="Percent">100</Penalty>'
        . '</TermsOfCancellation>';

    private const PAY = '<TermsOfPayment><Percent tillDate="2026-11-30">20</Percent><Percent tillDate="2027-06-26">80</Percent></TermsOfPayment>';

    private const PARAMS = [
        'hotel_id' => '476', 'check_in' => '2027-07-10', 'check_out' => '2027-07-17', 'nights' => 7,
        'adults' => 2, 'children_count' => 0, 'children_ages' => '', 'children_ages_array' => [],
    ];

    private const TEXTS = [
        'novoton_holidays.ultra_all_inclusive' => 'Ultra All Inclusive',
        'novoton_holidays.half_board' => 'Demipensiune',
        'novoton_holidays.bed_breakfast' => 'Mic Dejun Inclus',
        'novoton_holidays.early_booking' => 'Early Booking',
        'novoton_holidays.book_x_pay_y' => 'Rezervă [book] nopți, plătești [pay]',
        'novoton_holidays.important' => 'Important',
    ];

    private static function mapper(bool $deposit = true, string $lang = 'ro'): DesignARates
    {
        $money = new MoneyFormatter(['symbol' => '€', 'after' => 'Y', 'decimals' => 0, 'decimals_separator' => ',', 'thousands_separator' => '.']);

        return new DesignARates(
            new TermsTimelineFactory($money, self::TODAY, '%d.%m.%Y'),
            static fn (float $amount): float => $amount,
            static fn (string $key, array $replace): string => strtr(self::TEXTS[$key] ?? $key, $replace),
            static fn (string $url): string => '/index.php?dispatch=' . $url,
            $lang,
            self::TODAY,
            $deposit,
        );
    }

    /**
     * @param array<string, mixed> $more
     * @return array<string, mixed>
     */
    private static function row(string $room, string $label, float $price, int $left, array $more = []): array
    {
        return $more + [
            'room_id' => $room,
            'room_name' => $room,
            'room_type_display' => $label,
            'board_id' => 'ULTRA ALL INCL',
            'board_name' => 'Ultra All Inclusive',
            'package_name' => 'ADMIRAL ***** +BEACH 2027',
            'total_price' => $price,
            'rooms_available' => $left,
            'is_on_request' => false,
            'remark' => '',
            'important' => '',
            'more_info' => 'Beach included',
            'early_booking_discount' => 30.0,
            'extras' => '',
            'terms_of_payment' => self::PAY,
            'terms_of_cancellation' => self::CANCEL,
        ];
    }

    /** @return list<array<string, mixed>> the export's eight offers */
    private static function admiral(): array
    {
        return [
            self::row('DBL 2+0 DELUXE NO BALCONY', 'Cameră Dublă (DBL 2+0 DELUXE NO BALCONY)', 1485, 4),
            self::row('DBL 2+1 DELUXE', 'Cameră Dublă (DBL 2+1 DELUXE)', 1524, 19),
            self::row('DBL 2+1 DELUXE SEA SIDE', 'Cameră Dublă (DBL 2+1 DELUXE SEA SIDE)', 1622, 19),
            self::row('DBL 2+1 SUPERIOR DELUXE SEA SIDE', 'Cameră Dublă (DBL 2+1 SUPERIOR DELUXE SEA SIDE)', 2183, 1),
            self::row('JUNIOR SUITE 2+1 DELUXE SEA SIDE', 'Suită Junior (JUNIOR SUITE 2+1 DELUXE SEA SIDE)', 2355, 1),
            self::row('1-BR APP 2+1 DELUXE SEA SIDE', 'Apartament 1 Dormitor (1-BR APP 2+1 DELUXE SEA SIDE)', 2526, 1),
            self::row('FAM 2+3 DELUXE', 'Cameră Familială (FAM 2+3 DELUXE)', 2632, 3),
            self::row('FAM 2+3 DELUXE SEA SIDE', 'Cameră Familială (FAM 2+3 DELUXE SEA SIDE)', 2764, 3),
        ];
    }

    public function testRoomsAreNamedWithoutTheSuppliersCode(): void
    {
        self::assertSame('Cameră Dublă', DesignARates::roomName('Cameră Dublă (DBL 2+0 DELUXE NO BALCONY)', 'DBL 2+0 DELUXE NO BALCONY', 'ro'));
        self::assertSame('Double Room', DesignARates::roomName('Double Room (DBL 2+1)', 'DBL 2+1', 'en'));
        // Without the store's label, from the type words in the code.
        self::assertSame('Suită Junior', DesignARates::roomName('', 'JUNIOR SUITE 2+1 DELUXE', 'ro'));
        self::assertSame('Apartament 1 Dormitor', DesignARates::roomName('', '1-BR APP 2+1 DELUXE SEA SIDE', 'ro'));
        self::assertSame('Family Room', DesignARates::roomName('', 'FAM 2+3 DELUXE', 'en'));
        self::assertSame('Mezzanine', DesignARates::roomName('', 'MEZZANINE 2+2', 'ro'), 'an unknown type reads as a word');

        self::assertSame('Deluxe · fără balcon', DesignARates::roomDetail('DBL 2+0 DELUXE NO BALCONY', 'ro'));
        self::assertSame('Superior Deluxe · spre mare', DesignARates::roomDetail('DBL 2+1 SUPERIOR DELUXE SEA SIDE', 'ro'));
        self::assertSame('Deluxe · sea side', DesignARates::roomDetail('1-BR APP 2+1 DELUXE SEA SIDE', 'en'));
        self::assertSame('Vedere la mare', DesignARates::roomDetail('DBL 2+1 SEA VIEW', 'ro'));
        self::assertSame('Premium · cu balcon', DesignARates::roomDetail('DBL 2+2 PREMIUM BALCONY', 'ro'));
        self::assertSame('', DesignARates::roomDetail('DBL 2+1', 'ro'));
    }

    public function testMealPlansAreRankedLightestFirst(): void
    {
        self::assertSame(['novoton_holidays.ultra_all_inclusive', RateOffer::MEAL_ULTRA_ALL_INCLUSIVE], DesignARates::meal('ULTRA ALL INCL'));
        self::assertSame(['novoton_holidays.half_board', RateOffer::MEAL_HALF_BOARD], DesignARates::meal('HB +'));
        self::assertSame(['novoton_holidays.bed_breakfast', RateOffer::MEAL_BREAKFAST], DesignARates::meal('BB'));
        self::assertSame(['', RateOffer::MEAL_OTHER], DesignARates::meal('SPECIAL'));
    }

    public function testTheExportsOffersBecomeTheirRates(): void
    {
        $rates = self::mapper()->rates(self::admiral(), self::PARAMS);

        self::assertCount(8, $rates);
        $first = $rates[0];
        self::assertSame('DBL 2+0 DELUXE NO BALCONY', $first->roomKey);
        self::assertSame('Cameră Dublă', $first->roomName);
        self::assertSame('Deluxe · fără balcon', $first->roomDetail);
        self::assertSame('Ultra All Inclusive', $first->mealPlan);
        self::assertSame(RateOffer::MEAL_ULTRA_ALL_INCLUSIVE, $first->mealRank);
        self::assertSame(1485.0, $first->price);
        self::assertSame(2121.43, $first->standardPrice, 'the struck price: 1485 / (1 − 30%)');
        self::assertSame('Early Booking −30%', $first->discountLabel);
        self::assertSame(['Beach included'], $first->perks);
        self::assertSame('2027-06-30', $first->freeCancellationUntil, 'the last free day, still ahead');
        self::assertFalse($first->nonRefundable);
        self::assertFalse($first->onRequest);
        self::assertSame(4, $first->roomsLeft);
        self::assertSame(0.0, $first->depositRatio, 'nothing is due today (20% by 30 Nov): paid in full on booking');
        self::assertSame('', $first->rateName, 'the package name is the supplier\'s, not shown');
        self::assertNotSame([], $first->timeline['cancel_steps'] ?? []);
        self::assertCount(2, $first->timeline['payment_steps'] ?? []);
    }

    public function testBookGoesToTodaysBookingFormWithTheSameFields(): void
    {
        $url = self::mapper()->rates(self::admiral(), self::PARAMS)[0]->bookUrl;
        self::assertStringStartsWith('/index.php?dispatch=novoton_booking.booking_form?', $url);
        parse_str((string) parse_url(substr($url, strlen('/index.php?dispatch=novoton_booking.booking_form')), PHP_URL_QUERY), $query);

        // The store's own card for this offer (export product 292).
        self::assertSame([
            'hotel_id' => '476',
            'room_id' => 'DBL 2+0 DELUXE NO BALCONY',
            'board_id' => 'ULTRA ALL INCL',
            'check_in' => '2027-07-10',
            'check_out' => '2027-07-17',
            'nights' => '7',
            'adults' => '2',
            'children' => '0',
            'children_ages' => '',
            'price' => '1485',
            'package_name' => 'ADMIRAL ***** +BEACH 2027',
            'room_name' => 'Cameră Dublă (DBL 2+0 DELUXE NO BALCONY)',
            'board_name' => 'Ultra All Inclusive',
            'rooms_data' => '[{"adults":2,"children":0,"childrenAges":[]}]',
            'extras' => '',
            'extras_price' => '',
            'standard_price' => '1485',
            'early_booking' => '30',
            'is_on_request' => '0',
            'rooms_available' => '4',
        ], $query);
    }

    public function testQuotaZeroIsOnRequestAndPaysNothingBeforeTheHotelConfirms(): void
    {
        $rates = self::mapper()->rates([
            self::row('DBL 2+1', 'Cameră Dublă (DBL 2+1)', 900, 0, ['terms_of_payment' => '<TermsOfPayment><Percent>30</Percent><Percent tillDate="2027-06-01">70</Percent></TermsOfPayment>']),
            self::row('DBL 2+2', 'Cameră Dublă (DBL 2+2)', 950, 2, ['terms_of_payment' => '<TermsOfPayment><Percent>30</Percent><Percent tillDate="2027-06-01">70</Percent></TermsOfPayment>']),
        ], self::PARAMS);

        self::assertTrue($rates[0]->onRequest);
        self::assertSame(0.0, $rates[0]->depositRatio, 'on request: nothing to pay yet');
        self::assertFalse($rates[1]->onRequest);
        self::assertSame(0.3, $rates[1]->depositRatio, '30% on booking, the rest by 1 Jun');
        self::assertSame('2027-06-01', $rates[1]->balanceDue);
        $off = self::mapper(false)->rates([self::row('DBL 2+2', '', 950, 2, ['terms_of_payment' => '<TermsOfPayment><Percent>30</Percent><Percent tillDate="2027-06-01">70</Percent></TermsOfPayment>'])], self::PARAMS);
        self::assertSame(0.0, $off[0]->depositRatio, 'deposits switched off in the settings');
    }

    public function testAnExtrasPromotionIsTheRatesLabelAndAFullChargeIsNonRefundable(): void
    {
        $rates = self::mapper()->rates([
            self::row('DBL 2+1', 'Cameră Dublă (DBL 2+1)', 700, 5, [
                'extras' => '7=6', 'extras_label' => '7=6', 'extras_price' => 600.0, 'early_booking_discount' => 0.0,
                'terms_of_cancellation' => '<TermsOfCancellation><Penalty tillDate="2027-07-09" Type="Percent">100</Penalty><Penalty Type="Percent">100</Penalty></TermsOfCancellation>',
                'important' => 'lt;pgt;Gala dinner on 31.12 is compulsory, 1 adult; 50 €lt;/pgt;',
            ]),
        ], self::PARAMS);

        self::assertSame(600.0, $rates[0]->price);
        self::assertSame(700.0, $rates[0]->standardPrice);
        self::assertSame('Rezervă 7 nopți, plătești 6', $rates[0]->discountLabel);
        self::assertTrue($rates[0]->nonRefundable, 'cancelling today costs the full price');
        self::assertSame('', $rates[0]->freeCancellationUntil);
        self::assertSame(
            [['title' => 'Important', 'lines' => ['Gala dinner on 31.12 is compulsory, 1 adult; 50 €']]],
            $rates[0]->terms,
            'half-escaped tags dropped, "adult;" kept',
        );
    }

    public function testTheBuilderTurnsThemIntoTheBoardsSingleMealPlanCase(): void
    {
        $money = new MoneyFormatter(['symbol' => '€', 'after' => 'Y', 'decimals' => 0, 'decimals_separator' => ',', 'thousands_separator' => '.']);
        $view = (new RoomResultsBuilder($money, static fn (string $iso): string => date('d.m.Y', (int) strtotime($iso))))
            ->build(self::mapper()->rates(self::admiral(), self::PARAMS), 7);

        self::assertSame(['meal' => 'Ultra All Inclusive', 'immediate' => true, 'free_until' => '30.06.2027'], $view['facts']);
        self::assertSame([], $view['chips']);
        self::assertSame(8, $view['room_count']);
        self::assertSame('Deluxe · fără balcon', $view['rooms'][0]['detail']);
        self::assertSame('Early Booking −30%', $view['rooms'][0]['groups'][0]['rates'][0]['discount']);
        self::assertNotSame([], $view['rooms'][0]['timeline'], 'one terms timeline for the room');
    }
}
