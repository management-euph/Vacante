<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Api\Contracts\PricingApiClientInterface;
use Tygh\Addons\NovotonHolidays\Services\DesignARates;
use Tygh\Addons\NovotonHolidays\Services\SearchService;
use Tygh\Addons\TravelCore\Results\RateOffer;
use Tygh\Addons\TravelCore\Results\RoomResultsBuilder;
use Tygh\Addons\TravelCore\Services\MoneyFormatter;
use Tygh\Addons\TravelCore\ValueObjects\BoardType;
use Tygh\Addons\TravelCore\ViewModels\TermsTimelineFactory;

/**
 * Novoton's search rows → the Design A room cards' rates (board 13b),
 * checked against the store's own export: Admiral, Golden Sands (hotel 476),
 * 10–17 Jul 2027, two adults — eight room types, all Ultra All Inclusive,
 * all Early Booking −30%, free cancellation until 30 Jun 2027, 20% by 30 Nov
 * 2026 and the rest by 26 Jun 2027; and against a recorded room_price
 * answer for the Early Booking's last day (EBToDate).
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

    private static function mapper(bool $deposit = true, string $lang = 'ro', string $today = self::TODAY): DesignARates
    {
        $money = new MoneyFormatter(['symbol' => '€', 'after' => 'Y', 'decimals' => 0, 'decimals_separator' => ',', 'thousands_separator' => '.']);

        return new DesignARates(
            new TermsTimelineFactory($money, $today, '%d.%m.%Y'),
            static fn (float $amount): float => $amount,
            static fn (string $key, array $replace): string => strtr(self::TEXTS[$key] ?? $key, $replace),
            static fn (string $url): string => '/index.php?dispatch=' . $url,
            $lang,
            $today,
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

        // The card's title: the type, its tier, then its view or balcony (board 13b).
        self::assertSame('Cameră Dublă Deluxe, fără balcon', DesignARates::roomTitle('Cameră Dublă', 'DBL 2+0 DELUXE NO BALCONY', 'ro'));
        self::assertSame('Cameră Dublă Superior Deluxe, spre mare', DesignARates::roomTitle('Cameră Dublă', 'DBL 2+1 SUPERIOR DELUXE SEA SIDE', 'ro'));
        self::assertSame('Cameră Dublă, vedere la mare', DesignARates::roomTitle('Cameră Dublă', 'DBL 2+1 SEA VIEW', 'ro'));
        self::assertSame('Cameră Dublă Premium, cu balcon', DesignARates::roomTitle('Cameră Dublă', 'DBL 2+2 PREMIUM BALCONY', 'ro'));
        self::assertSame('Double Room Deluxe, sea side', DesignARates::roomTitle('Double Room', 'DBL 2+1 DELUXE SEA SIDE', 'en'));
        self::assertSame('Cameră Dublă', DesignARates::roomTitle('Cameră Dublă', 'DBL 2+1', 'ro'));
        self::assertSame('Cameră Dublă Deluxe', DesignARates::roomTitle('Cameră Dublă Deluxe', 'DBL 2+1 DELUXE', 'ro'), 'a tier the name already says is not said twice');

        // How many it sleeps, in words on the card ("Max. 3 persoane"), never "2+1".
        self::assertSame(3, DesignARates::capacity('DBL 2+1 DELUXE SEA SIDE'));
        self::assertSame(5, DesignARates::capacity('FAM 2+3 DELUXE'));
        self::assertSame(2, DesignARates::capacity('DBL 2%2B0 DELUXE NO BALCONY'));
        self::assertSame(0, DesignARates::capacity('STUDIO SEA VIEW'));
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
        self::assertSame('Cameră Dublă Deluxe, fără balcon', $first->roomTitle);
        self::assertSame('DBL 2+0 DELUXE NO BALCONY', $first->roomCode, 'IdRoom under the title (the owner\'s call)');
        self::assertSame(2, $first->capacity);
        self::assertSame('ADMIRAL ***** +BEACH 2027', $first->packageName);
        self::assertSame('Ultra All Inclusive', $first->mealPlan);
        self::assertSame(RateOffer::MEAL_ULTRA_ALL_INCLUSIVE, $first->mealRank);
        self::assertSame(1485.0, $first->price);
        self::assertSame(2121.43, $first->standardPrice, 'the struck price: 1485 / (1 − 30%)');
        self::assertSame('Early Booking', $first->discountLabel, 'the percent goes on the struck price');
        self::assertSame('', $first->discountUntil, 'no EBToDate in these rows');
        self::assertSame(['Beach included'], $first->perks);
        self::assertSame('2027-06-30', $first->freeCancellationUntil, 'the last free day, still ahead');
        self::assertFalse($first->nonRefundable);
        self::assertFalse($first->onRequest);
        self::assertSame(4, $first->roomsLeft);
        self::assertSame(0.0, $first->depositRatio, 'nothing is due today (20% by 30 Nov): paid in full on booking');
        self::assertSame('', $first->rateName);
        self::assertSame([], $first->facilities);
        self::assertSame(['Aer condiționat', 'Seif'], self::mapper()->rates(self::admiral(), self::PARAMS, ['Aer condiționat', 'Seif'])[0]->facilities);
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
            'ext_board_id' => '',
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

    /**
     * Admiral off-season: "BB" alone and "BB" with the "HB" supplement in
     * one package. Half board is an option of its own, named and ranked as
     * half board, and its link books the supplement.
     */
    public function testTheBoardsSupplementIsAMealPlanOfItsOwn(): void
    {
        [$breakfast, $half] = self::mapper()->rates([
            self::row('DBL 2+0 DELUXE NO BALCONY', 'Cameră Dublă (DBL 2+0 DELUXE NO BALCONY)', 415, 4, ['board_id' => 'BB']),
            self::row('DBL 2+0 DELUXE NO BALCONY', 'Cameră Dublă (DBL 2+0 DELUXE NO BALCONY)', 535, 4, ['board_id' => 'BB', 'ext_board_id' => 'HB']),
        ], self::PARAMS);

        self::assertSame(['Mic Dejun Inclus', RateOffer::MEAL_BREAKFAST], [$breakfast->mealPlan, $breakfast->mealRank]);
        self::assertSame(['Demipensiune', RateOffer::MEAL_HALF_BOARD], [$half->mealPlan, $half->mealRank]);
        self::assertSame($breakfast->roomKey, $half->roomKey, 'one room card, two options');
        parse_str((string) parse_url($half->bookUrl, PHP_URL_QUERY), $query);
        self::assertSame(['BB', 'HB', 'Demipensiune'], [$query['board_id'] ?? null, $query['ext_board_id'] ?? null, $query['board_name'] ?? null]);
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

        self::assertSame('Ultra All Inclusive', $view['single_meal'], '"8 tipuri de cameră, toate cu Ultra All Inclusive"');
        self::assertSame([], $view['meal_plans']);
        self::assertSame([], $view['chips']);
        self::assertSame(8, $view['room_count']);
        self::assertSame(['count' => 4, 'names' => 'Suită Junior, Apartament 1 Dormitor, Cameră Familială', 'from' => '2.355 €'], $view['more']);

        $room = $view['rooms'][0];
        self::assertSame(['Cameră Dublă Deluxe, fără balcon', 'DBL 2+0 DELUXE NO BALCONY', 2, 4], [$room['title'], $room['code'], $room['capacity'], $room['left']]);
        self::assertTrue($room['single']);
        $option = $room['options'][0];
        self::assertSame('ADMIRAL ***** +BEACH 2027', $option['package'], 'one option: its package stays on it');
        self::assertSame(['name' => 'Early Booking', 'until' => '', 'saving' => '636 €'], $option['deal']);
        self::assertSame(['1.485 €', '2.121 €', '−30%'], [$option['price'], $option['was'], $option['off']]);
        self::assertSame(['kind' => 'free', 'until' => '30.06.2027'], $room['cancel']);
        self::assertCount(1, $room['terms']);
        self::assertNotSame([], $room['terms'][0]['timeline'], 'the terms timeline, as in the booking sidebar');
        self::assertSame([true, 'Early Booking'], [$view['struck'], $view['struck_deal']]);
    }

    /**
     * The fixture's third offer is "BB" with the "HB" supplement (half
     * board). The search row carries the supplement and is named, filtered
     * and shown as half board, and the de-dup keeps it next to "BB" alone of
     * the same room and package: it kept only the cheaper of the two, so
     * half board was never offered.
     */
    public function testTheSearchKeepsTheBoardsSupplement(): void
    {
        require_once dirname(__DIR__, 3) . '/functions/formatting.php';
        $pricing = $this->createStub(PricingApiClientInterface::class);
        $pricing->method('applyCommission')->willReturnArgument(0);
        $xml = (string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/room_price_476.xml');
        $search = new SearchService($pricing);
        $rows = $search->parseRoomPriceResponse($xml, 7, '2026-10-10', '2026-10-17');

        self::assertSame(['', '', 'HB'], array_column($rows, 'ext_board_id'));
        self::assertSame(BoardType::toDisplayName('HB'), $rows[2]['board_name']);
        self::assertSame([675.0], array_column($search->parseRoomPriceResponse($xml, 7, '2026-10-10', '2026-10-17', 'HB'), 'total_price'), 'the half-board filter finds it');
        $params = ['check_in' => '2026-10-10', 'check_out' => '2026-10-17', 'nights' => 7] + self::PARAMS;
        self::assertSame('Demipensiune', self::mapper(true, 'ro', '2026-09-01')->rates([$rows[2]], $params)[0]->mealPlan);

        $plain = ['ext_board_id' => '', 'total_price' => 555.0] + $rows[2];
        self::assertSame(['HB', ''], array_column(SearchService::deduplicateResults([$rows[2], $plain]), 'ext_board_id'));
    }

    /**
     * The recorded room_price answer for Admiral (fixture): three offers of
     * one room; the second is the Early Booking −25%, bookable until 30 Sep
     * 2026 (EBToDate, empty on the others). Read by the search parser, then
     * the mapper: the discount's last day while it is still ahead.
     */
    public function testTheEarlyBookingsLastDayComesFromEbToDate(): void
    {
        require_once dirname(__DIR__, 3) . '/functions/formatting.php';
        $pricing = $this->createStub(PricingApiClientInterface::class);
        $pricing->method('applyCommission')->willReturnArgument(0);
        $xml = (string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/room_price_476.xml');
        $rows = (new SearchService($pricing))->parseRoomPriceResponse($xml, 7, '2026-10-10', '2026-10-17');

        self::assertSame(['', '2026-09-30', ''], array_column($rows, 'early_booking_until'));
        self::assertSame([0.0, 25.0, 0.0], array_column($rows, 'early_booking_discount'));

        $params = ['check_in' => '2026-10-10', 'check_out' => '2026-10-17', 'nights' => 7] + self::PARAMS;
        $rates = self::mapper(true, 'ro', '2026-09-01')->rates($rows, $params);
        self::assertSame(['Early Booking', '2026-09-30'], [$rates[1]->discountLabel, $rates[1]->discountUntil]);
        self::assertSame('ADMIRAL ***** +BEACH [STAY 18.09 - 31.10]', $rates[1]->packageName);
        self::assertSame(['', ''], [$rates[0]->discountLabel, $rates[0]->discountUntil]);

        $later = self::mapper(true, 'ro', '2026-10-01')->rates($rows, $params);
        self::assertSame(['Early Booking', ''], [$later[1]->discountLabel, $later[1]->discountUntil], 'a last day already gone is not shown');
    }

    /** The search's params for these rooms (rooms_data, as the search form posts them). */
    private static function severalRooms(array ...$rooms): array
    {
        return ['rooms_data' => array_map(
            static fn (array $room): array => ['adults' => $room[0], 'children' => count($room[1]), 'childrenAges' => $room[1]],
            $rooms,
        )] + self::PARAMS;
    }

    /**
     * @param array<string, mixed> $results a group's ResultsView
     * @return array<string, mixed> its card of this room code
     */
    private static function card(array $results, string $code): array
    {
        foreach ($results['rooms'] as $room) {
            if ($room['code'] === $code) {
                return $room;
            }
        }
        self::fail('no card for ' . $code);
    }

    /**
     * Boards 13c: rooms 1 and 3 for two adults, room 2 for two adults and a
     * child of 5. The two-adult rooms are one group, priced from room 1's
     * rows (the batcher asks once for the same guests), de-duplicated as for
     * one room; every rate carries the entry the booking of several rooms
     * posts for a room, field for field today's selection's.
     */
    public function testSeveralRoomsAreGroupedByTheirGuestsWithEachRoomsBookingEntry(): void
    {
        $twoAdults = self::admiral();
        // The same room, board and package with a "book 7, pay 6": one rate at the promotion's price.
        $twoAdults[] = self::row('DBL 2+1 DELUXE', 'Cameră Dublă (DBL 2+1 DELUXE)', 1306, 19, ['extras' => '7=6', 'early_booking_discount' => 0.0]);
        $family = [
            self::row('DBL 2+1 DELUXE', 'Cameră Dublă (DBL 2+1 DELUXE)', 1830, 19),
            self::row('FAM 2+3 DELUXE', 'Cameră Familială (FAM 2+3 DELUXE)', 2900, 3),
        ];
        $params = self::severalRooms([2, []], [2, [5]], [2, []]);
        $money = new MoneyFormatter(['symbol' => '€', 'after' => 'Y', 'decimals' => 0, 'decimals_separator' => ',', 'thousands_separator' => '.']);
        $builder = new RoomResultsBuilder($money, static fn (string $iso): string => $iso);

        $view = self::mapper()->groups([1 => $twoAdults, 2 => $family, 3 => []], $params, $builder);

        self::assertSame([3, 2, false, 7], [$view['room_count'], $view['group_count'], $view['by_room'], $view['nights']]);
        [$pair, $child] = $view['groups'];
        self::assertSame([[1, 3], '1, 3', 2, 2, []], [$pair['rooms'], $pair['numbers'], $pair['size'], $pair['adults'], $pair['ages']]);
        self::assertSame([[2], '2', 1, [5]], [$child['rooms'], $child['numbers'], $child['size'], $child['ages']]);
        self::assertSame(8, $pair['results']['room_count'], 'room 1\'s eight types, the promotion folded into its room\'s rate');
        self::assertSame(2, $child['results']['room_count'], 'priced for the child: room 2\'s own rows');

        $balcony = self::card($pair['results'], 'DBL 2+0 DELUXE NO BALCONY');
        self::assertStringStartsWith('g1-', $balcony['key']);
        self::assertSame([2, '2.970 €'], [$balcony['qty'], $balcony['options'][0]['qty_price']], 'both rooms of the group, 4 left');
        self::assertSame(1, self::card($pair['results'], 'DBL 2+1 SUPERIOR DELUXE SEA SIDE')['qty'], 'one left: one room');
        self::assertSame(
            [
                'room_id' => 'DBL 2+0 DELUXE NO BALCONY',
                'board_id' => 'ULTRA ALL INCL',
                'price' => 1485,
                'room_display' => 'Cameră Dublă (DBL 2+0 DELUXE NO BALCONY)',
                'board_name' => 'Ultra All Inclusive',
                'package_name' => 'ADMIRAL ***** +BEACH 2027',
                'standard_price' => 1485,
                'early_booking' => 30,
                'extras' => '',
            ],
            json_decode($balcony['options'][0]['room_json'], true),
        );

        $promotion = json_decode(self::card($pair['results'], 'DBL 2+1 DELUXE')['options'][0]['room_json'], true);
        self::assertSame([1306, 1524, '7=6'], [$promotion['price'], $promotion['standard_price'], $promotion['extras']], 'charged the promotion, the standard price beside it');

        $family = self::card($child['results'], 'DBL 2+1 DELUXE');
        self::assertStringStartsWith('g2-', $family['key']);
        self::assertSame(1830, json_decode($family['options'][0]['room_json'], true)['price']);
        self::assertSame(
            self::card($pair['results'], 'DBL 2+1 DELUXE')['quota'],
            $family['quota'],
            'one room type, one availability across the groups',
        );

        $one = $builder->build(self::mapper()->rates(self::admiral(), self::PARAMS), 7);
        self::assertSame('', $one['rooms'][0]['options'][0]['room_json'], 'one room books by its link');
    }

    public function testTheBookingOfSeveralRoomsGoesToTodaysBookingForm(): void
    {
        $form = DesignARates::bookForm(self::severalRooms([2, []], [2, [5]], [2, []]), 'https://shop.example');

        self::assertSame('https://shop.example', $form['url']);
        self::assertSame(
            [
                'dispatch' => 'novoton_booking.booking_form',
                'hotel_id' => '476',
                'check_in' => '2027-07-10',
                'check_out' => '2027-07-17',
                'nights' => 7,
                'num_rooms' => 3,
                'multi_room' => 1,
            ],
            $form['fields'],
        );
        self::assertSame(['rooms_data', 'total_price'], [$form['rooms_field'], $form['total_field']]);
        self::assertSame('index.php', DesignARates::bookForm(self::PARAMS, '')['url']);
    }

    public function testSeveralRoomsShowOnlyWhenEveryRoomHasRates(): void
    {
        $rows = [self::row('DBL 2+1 DELUXE', 'Cameră Dublă (DBL 2+1 DELUXE)', 1524, 19)];
        $params = self::severalRooms([2, []], [2, [5]]);

        self::assertTrue(DesignARates::everyRoomPriced([1 => $rows, 2 => $rows], $params));
        self::assertFalse(DesignARates::everyRoomPriced([1 => $rows, 2 => []], $params), 'today\'s selection says which room has none');
        self::assertFalse(DesignARates::everyRoomPriced([1 => $rows], $params));
        self::assertFalse(DesignARates::everyRoomPriced([1 => $rows], self::severalRooms([2, []])), 'one room: the one-room page');
        self::assertFalse(DesignARates::everyRoomPriced([1 => $rows], self::PARAMS));
    }
}
