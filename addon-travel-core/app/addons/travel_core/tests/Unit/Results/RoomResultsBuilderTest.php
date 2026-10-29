<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Results;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Results\RateOffer;
use Tygh\Addons\TravelCore\Results\RoomResultsBuilder;
use Tygh\Addons\TravelCore\Services\MoneyFormatter;

/**
 * The room-first results of the Design A hotel page, by the owner's board
 * 13b: one option per meal plan and terms (the cheapest of two packages that
 * offer the same), what a room's options share said once, the meal filter
 * only with 2+ meal plans, a chip only when it splits the options, the first
 * four room types and "see all".
 */
final class RoomResultsBuilderTest extends TestCase
{
    private static function builder(): RoomResultsBuilder
    {
        return new RoomResultsBuilder(
            new MoneyFormatter(['symbol' => '€', 'after' => 'Y', 'decimals' => 0, 'decimals_separator' => ',', 'thousands_separator' => '.']),
            static fn (string $iso): string => date('d.m.Y', (int) strtotime($iso)),
        );
    }

    /**
     * Admiral, Golden Sands, 10–17 Jul 2027 (the store's export, board 13b.4):
     * eight room types with one rate each, all Ultra All Inclusive from one
     * package, all instant, all free to cancel until 30 Jun 2027, all Early
     * Booking −30% (bookable until 31 Mar 2027).
     *
     * @return list<RateOffer>
     */
    private static function admiral(): array
    {
        $rows = [
            ['DBL 2+0 DELUXE NO BALCONY', 'Cameră Dublă', 'Cameră Dublă Deluxe, fără balcon', 2, 1485, 4],
            ['DBL 2+1 DELUXE', 'Cameră Dublă', 'Cameră Dublă Deluxe', 3, 1524, 19],
            ['DBL 2+1 DELUXE SEA SIDE', 'Cameră Dublă', 'Cameră Dublă Deluxe, spre mare', 3, 1622, 19],
            ['DBL 2+1 SUPERIOR DELUXE SEA SIDE', 'Cameră Dublă', 'Cameră Dublă Superior Deluxe, spre mare', 3, 2183, 1],
            ['JUNIOR SUITE 2+1 DELUXE SEA SIDE', 'Suită Junior', 'Suită Junior Deluxe, spre mare', 3, 2355, 1],
            ['1-BR APP 2+1 DELUXE SEA SIDE', 'Apartament cu un dormitor', 'Apartament cu un dormitor Deluxe, spre mare', 3, 2526, 2],
            ['FAM 2+3 DELUXE', 'Cameră de familie', 'Cameră de familie Deluxe', 5, 2632, 3],
            ['FAM 2+3 DELUXE SEA SIDE', 'Cameră de familie', 'Cameră de familie Deluxe, spre mare', 5, 2764, 3],
        ];
        $rates = [];
        // The supplier's order is not the price order.
        foreach (array_reverse($rows) as [$code, $name, $title, $capacity, $price, $left]) {
            $rates[] = new RateOffer(
                roomKey: $code,
                roomName: $name,
                mealPlan: 'Ultra All Inclusive',
                price: (float) $price,
                bookUrl: 'book?room=' . rawurlencode($code),
                roomTitle: $title,
                mealRank: RateOffer::MEAL_ULTRA_ALL_INCLUSIVE,
                standardPrice: round($price / 0.7),
                discountLabel: 'Early Booking',
                perks: ['Acces la plajă inclus'],
                freeCancellationUntil: '2027-06-30',
                roomsLeft: $left,
                terms: [['title' => 'Important', 'lines' => ['Plaja inclusă']]],
                facilities: ['Aer condiționat', 'Wi-Fi gratuit'],
                roomCode: $code,
                capacity: $capacity,
                packageName: 'ADMIRAL ***** +BEACH 2027',
                discountUntil: '2027-03-31',
            );
        }

        return $rates;
    }

    public function testOneMealPlanIsSaidInTheCountAndEachRoomKeepsItsPackage(): void
    {
        $view = self::builder()->build(self::admiral(), 7);

        self::assertSame([8, 'few', 8, 'few'], [$view['rate_count'], $view['rate_form'], $view['room_count'], $view['room_form']]);
        self::assertSame('Ultra All Inclusive', $view['single_meal'], '"8 tipuri de cameră, toate cu Ultra All Inclusive"');
        self::assertSame([], $view['meal_plans'], 'no meal filter with one meal plan');
        self::assertSame([], $view['chips'], 'every option is free and instant: no chip splits them');
        self::assertSame('1.485 €', $view['cheapest']);
        self::assertTrue($view['meals_included']);
        self::assertSame([true, 'Early Booking'], [$view['struck'], $view['struck_deal']], 'the footnote names the discount the struck price leaves out');
        self::assertSame([7, 'few', 2, 'few', 0, 'few'], [
            $view['nights'], $view['nights_form'], $view['adults'], $view['adults_form'], $view['children'], $view['children_form'],
        ]);

        $room = $view['rooms'][0];
        self::assertSame(['Cameră Dublă', 'Cameră Dublă Deluxe, fără balcon', 'DBL 2+0 DELUXE NO BALCONY', 2, 'few'], [
            $room['name'], $room['title'], $room['code'], $room['capacity'], $room['capacity_form'],
        ]);
        self::assertTrue($room['single']);
        self::assertTrue($room['cheapest'], '"Cel mai mic preț" marks the cheapest room');
        self::assertFalse($view['rooms'][1]['cheapest']);
        self::assertSame([true, false], [$room['immediate'], $room['on_request']]);
        self::assertSame(['Aer condiționat', 'Wi-Fi gratuit'], $room['facilities']);

        // One option: the package and its discount stay on it, with the saving.
        self::assertSame(['', null], [$room['package'], $room['deal']]);
        $option = $room['options'][0];
        self::assertSame('ADMIRAL ***** +BEACH 2027', $option['package']);
        self::assertSame(['name' => 'Early Booking', 'until' => '31.03.2027', 'saving' => '636 €'], $option['deal']);
        self::assertSame(['1.485 €', '2.121 €', '−30%', '212 €'], [$option['price'], $option['was'], $option['off'], $option['per_night']]);
        self::assertSame(['Acces la plajă inclus'], $option['perks']);
        self::assertTrue($option['chosen']);
        self::assertSame('book?room=DBL%202%2B0%20DELUXE%20NO%20BALCONY', $option['book_url']);

        // What is said once for the room: cancellation, payment, terms.
        self::assertSame(['kind' => RoomResultsBuilder::CANCEL_FREE, 'until' => '30.06.2027'], $room['cancel']);
        self::assertNull($option['cancel']);
        self::assertSame(['on_request' => false, 'deposit_percent' => 0, 'balance_due' => '', 'text' => ''], $room['payment']);
        self::assertNull($option['payment']);
        self::assertSame(
            [['for' => RoomResultsBuilder::TERMS_ALL, 'terms' => [['title' => 'Important', 'lines' => ['Plaja inclusă']]], 'timeline' => [], 'url' => '']],
            $room['terms'],
        );
    }

    public function testRoomsComeCheapestFirstTheFirstFourShownAndSayHowManyAreLeft(): void
    {
        $view = self::builder()->build(self::admiral(), 7);
        $rooms = $view['rooms'];

        self::assertSame(
            ['1.485 €', '1.524 €', '1.622 €', '2.183 €', '2.355 €', '2.526 €', '2.632 €', '2.764 €'],
            array_column($rooms, 'from_text'),
        );
        self::assertSame([4, 19, 19, 1, 1, 2, 3, 3], array_column($rooms, 'left'), 'every count: the card tiers it (1 / 2–3 / 4+)');
        self::assertSame(['few', 'few', 'few', 'one', 'one', 'few', 'few', 'few'], array_column($rooms, 'left_form'), '"19 camere", "20 de camere"');
        self::assertSame([false, false, false, false, true, true, true, true], array_column($rooms, 'more'));
        self::assertSame(
            ['count' => 4, 'names' => 'Suită Junior, Apartament cu un dormitor, Cameră de familie', 'from' => '2.355 €'],
            $view['more'],
            '"Încă 4: …, de la 2.355 €"',
        );
    }

    /**
     * Board 13b.1, as Novoton sends Admiral for 7–13 Nov 2026: each meal plan
     * from its own package (BB/HB, and Ultra All Inclusive with Early
     * Booking −12% until 31 Oct), the same cancellation and payment for both;
     * the fourth room on request with one meal plan.
     *
     * @return list<RateOffer>
     */
    private static function november(): array
    {
        $rate = static fn (string $room, string $title, string $meal, int $rank, float $price, array $more = []): RateOffer => new RateOffer(...array_merge([
            'roomKey' => $room,
            'roomName' => 'Cameră Dublă',
            'mealPlan' => $meal,
            'price' => $price,
            'bookUrl' => 'book?room=' . $room . '&meal=' . $rank . '&p=' . $price,
            'roomTitle' => $title,
            'mealRank' => $rank,
            'freeCancellationUntil' => '2026-11-03',
            'roomCode' => $room,
        ], $more));
        $bb = static fn (string $room, string $title, float $price, array $more = []): RateOffer => $rate($room, $title, 'Mic dejun', 1, $price, ['packageName' => 'ADMIRAL ***** BB/HB [STAY 22.09 - 19.12]'] + $more);
        $uai = static fn (string $room, string $title, float $price, float $was, array $more = []): RateOffer => $rate($room, $title, 'Ultra All Inclusive', 5, $price, [
            'packageName' => 'ADMIRAL ***** ULTRA ALL INCL [STAY 01.11 - 19.12]',
            'standardPrice' => $was,
            'discountLabel' => 'Early Booking',
            'discountUntil' => '2026-10-31',
        ] + $more);

        return [
            $uai('DBL 2+0 DELUXE NO BALCONY', 'Cameră Dublă Deluxe, fără balcon', 845, 960, ['roomsLeft' => 1]),
            $bb('DBL 2+0 DELUXE NO BALCONY', 'Cameră Dublă Deluxe, fără balcon', 615, ['roomsLeft' => 1]),
            // The same breakfast from a second package, on the same terms: one choice, the cheaper.
            $rate('DBL 2+0 DELUXE NO BALCONY', 'Cameră Dublă Deluxe, fără balcon', 'Mic dejun', 1, 640, ['packageName' => 'ADMIRAL ***** BB [OFFER]', 'roomsLeft' => 1]),
            $bb('DBL 2+1 DELUXE', 'Cameră Dublă Deluxe', 675, ['roomsLeft' => 5]),
            $uai('DBL 2+1 DELUXE', 'Cameră Dublă Deluxe', 905, 1028, ['roomsLeft' => 5]),
            $bb('DBL 2+1 DELUXE SEA SIDE', 'Cameră Dublă Deluxe, spre mare', 735, ['roomsLeft' => 2]),
            $uai('DBL 2+1 DELUXE SEA SIDE', 'Cameră Dublă Deluxe, spre mare', 965, 1097, ['roomsLeft' => 2]),
            $uai('DBL 2+1 SUPERIOR DELUXE SEA SIDE', 'Cameră Dublă Superior Deluxe, spre mare', 1295, 1472, ['onRequest' => true, 'roomsLeft' => 0]),
        ];
    }

    public function testSeveralMealPlansOneOptionEachWithTheirOwnPackages(): void
    {
        $view = self::builder()->build(self::november(), 6);

        self::assertSame(7, $view['rate_count'], 'the second breakfast package is one choice with the first');
        self::assertSame('', $view['single_meal']);
        self::assertSame(
            [
                ['name' => 'Mic dejun', 'from' => '615 €', 'count' => 3, 'form' => 'few'],
                ['name' => 'Ultra All Inclusive', 'from' => '845 €', 'count' => 4, 'form' => 'few'],
            ],
            array_map(static fn (array $plan): array => array_diff_key($plan, ['key' => true]), $view['meal_plans']),
            'lightest meal first, each with its lowest price and how many room types have it',
        );
        self::assertSame(
            [['key' => 'immediate', 'count' => 6]],
            $view['chips'],
            'every option is free to cancel: no chip for that; no special-offers chip (the owner, 29 Oct 2026)',
        );

        [$first, , , $last] = $view['rooms'];
        self::assertSame(['Mic dejun', 'Ultra All Inclusive'], array_column($first['options'], 'meal'));
        self::assertSame([true, false], array_column($first['options'], 'chosen'), 'the cheapest option is chosen');
        self::assertSame(0, $first['chosen']);
        self::assertSame('615 €', $first['options'][0]['price']);
        self::assertSame(
            implode(' ', array_column($view['meal_plans'], 'key')),
            $first['meals'],
            'the meal filter finds the room by its meal keys',
        );

        // Each meal plan has its own package: package and discount stay on the option.
        self::assertSame(['', null], [$first['package'], $first['deal']]);
        self::assertSame('ADMIRAL ***** BB/HB [STAY 22.09 - 19.12]', $first['options'][0]['package']);
        self::assertNull($first['options'][0]['deal'], 'no discount on breakfast');
        $uai = $first['options'][1];
        self::assertSame(['name' => 'Early Booking', 'until' => '31.10.2026', 'saving' => '115 €'], $uai['deal']);
        self::assertSame(['960 €', '−12%', '141 €'], [$uai['was'], $uai['off'], $uai['per_night']]);

        // The same cancellation and payment for both: said once, under them.
        self::assertSame(['kind' => 'free', 'until' => '03.11.2026'], $first['cancel']);
        self::assertSame([null, null], array_column($first['options'], 'cancel'));
        self::assertNotNull($first['payment']);
        self::assertSame(1, $first['left']);
        self::assertSame([], $first['terms'], 'no terms beyond what the card says');

        self::assertTrue($last['single']);
        self::assertSame([true, false, 0], [$last['on_request'], $last['immediate'], $last['left']], 'on request: "La cerere", no availability pill');
        self::assertSame(['on_request' => true, 'deposit_percent' => 0, 'balance_due' => '', 'text' => ''], $last['payment']);
    }

    public function testASharedPackageIsSaidOnceWithItsDiscountButNotTheSaving(): void
    {
        $plain = ['roomKey' => 'R', 'roomName' => 'Cameră', 'bookUrl' => 'b', 'packageName' => 'SUMMER 2027', 'discountLabel' => 'Early Booking', 'discountUntil' => '2027-01-31'];
        $room = self::builder()->build([
            new RateOffer(...$plain + ['mealPlan' => 'Mic dejun', 'mealRank' => 1, 'price' => 700.0, 'standardPrice' => 1000.0]),
            new RateOffer(...$plain + ['mealPlan' => 'All Inclusive', 'mealRank' => 4, 'price' => 900.0, 'standardPrice' => 1200.0]),
        ], 7)['rooms'][0];

        self::assertSame('SUMMER 2027', $room['package']);
        self::assertSame(['name' => 'Early Booking', 'until' => '31.01.2027', 'saving' => ''], $room['deal'], '"inclus în preț": the saving differs per option');
        self::assertSame(['', ''], array_column($room['options'], 'package'));
        self::assertSame([null, null], array_column($room['options'], 'deal'));
        self::assertSame(['−30%', '−25%'], array_column($room['options'], 'off'), 'the percent stays on each price');
    }

    public function testWhatDiffersStaysOnItsOption(): void
    {
        $plain = ['roomKey' => 'R', 'roomName' => 'Cameră', 'mealPlan' => 'Mic dejun', 'mealRank' => 1, 'bookUrl' => 'b'];
        $view = self::builder()->build([
            new RateOffer(...$plain + ['price' => 500.0, 'rateName' => 'Tarif nerambursabil', 'nonRefundable' => true]),
            new RateOffer(...$plain + ['price' => 560.0, 'freeCancellationUntil' => '2027-06-30', 'depositRatio' => 0.3, 'balanceDue' => '2027-06-20']),
            new RateOffer(...$plain + ['price' => 590.0, 'onRequest' => true, 'freeCancellation' => true]),
        ], 7);
        $room = $view['rooms'][0];

        self::assertSame(3, $view['rate_count'], 'the same meal on other terms is another choice');
        self::assertNull($room['cancel']);
        self::assertSame([
            ['kind' => RoomResultsBuilder::CANCEL_NON_REFUNDABLE, 'until' => ''],
            ['kind' => RoomResultsBuilder::CANCEL_FREE, 'until' => '30.06.2027'],
            ['kind' => RoomResultsBuilder::CANCEL_FREE_OPEN, 'until' => ''],
        ], array_column($room['options'], 'cancel'));
        self::assertNull($room['payment']);
        self::assertSame([0, 30, 0], array_map(static fn (array $o): int => $o['payment']['deposit_percent'] ?? -1, $room['options']));
        self::assertSame('20.06.2027', $room['options'][1]['payment']['balance_due'] ?? '');
        self::assertSame('Tarif nerambursabil', $room['options'][0]['name']);
        self::assertSame([false, false], [$room['on_request'], $room['immediate']], 'mixed: each option says its own');
        self::assertSame([false, true, true], array_column($room['options'], 'free'));
        self::assertSame(
            [['key' => 'free', 'count' => 2], ['key' => 'immediate', 'count' => 2]],
            $view['chips'],
        );
    }

    public function testTermsAreOneSetWhenEveryOptionHasTheSameElseTheChosenOnes(): void
    {
        $timeline = ['cancel_steps' => [['kind' => 'free', 'to_iso' => '2027-06-30']], 'payment_steps' => []];
        $plain = ['roomKey' => 'R', 'roomName' => 'R', 'bookUrl' => 'b', 'timeline' => $timeline];
        $room = self::builder()->build([
            new RateOffer(...$plain + ['mealPlan' => 'BB', 'mealRank' => 1, 'price' => 100.0]),
            new RateOffer(...$plain + ['mealPlan' => 'HB', 'mealRank' => 2, 'price' => 120.0]),
        ], 7)['rooms'][0];
        self::assertSame([['for' => RoomResultsBuilder::TERMS_ALL, 'terms' => [], 'timeline' => $timeline, 'url' => '']], $room['terms']);

        // Loaded on demand (Sphinx, Eurosite): each option's own link.
        $room = self::builder()->build([
            new RateOffer('R', 'R', 'BB', 100.0, 'b', mealRank: 1, termsUrl: 'terms?offer=1'),
            new RateOffer('R', 'R', 'HB', 120.0, 'b', mealRank: 2, termsUrl: 'terms?offer=2'),
            new RateOffer('R', 'R', 'AI', 150.0, 'b', mealRank: 4),
        ], 7)['rooms'][0];
        self::assertSame([[0, 'terms?offer=1'], [1, 'terms?offer=2']], array_map(
            static fn (array $set): array => [$set['for'], $set['url']],
            $room['terms'],
        ), 'an option with no terms has no link');
    }

    public function testAPaymentTextStandsForTheDepositRules(): void
    {
        $rooms = self::builder()->build([
            new RateOffer('R', 'R', 'BB', 100.0, 'b', paymentText: 'Avans 30% la rezervare'),
            new RateOffer('S', 'S', 'BB', 120.0, 'b', onRequest: true, paymentText: 'Avans 30% la rezervare'),
        ], 7)['rooms'];

        self::assertSame('Avans 30% la rezervare', $rooms[0]['payment']['text'] ?? '');
        self::assertSame(['on_request' => true, 'deposit_percent' => 0, 'balance_due' => '', 'text' => ''], $rooms[1]['payment'], 'on request: nothing is paid before');
    }

    public function testKeysStayTheSameForTheSameRates(): void
    {
        $a = self::builder()->build(self::november(), 6);
        $b = self::builder()->build(array_reverse(self::november()), 6);

        self::assertSame(array_column($a['rooms'], 'key'), array_column($b['rooms'], 'key'));
        self::assertSame(array_column($a['meal_plans'], 'key'), array_column($b['meal_plans'], 'key'));
        self::assertSame(array_column($a['rooms'][0]['options'], 'key'), array_column($b['rooms'][0]['options'], 'key'));
        self::assertMatchesRegularExpression('/^room-[0-9a-f]{10}$/', $a['rooms'][0]['key']);
        self::assertCount(4, array_unique(array_column($a['rooms'], 'key')));
        self::assertNull($a['more'], 'four room types: all shown');
    }

    public function testCountsTakeTheirRomanianPluralForm(): void
    {
        self::assertSame('one', RoomResultsBuilder::form(1));
        self::assertSame('few', RoomResultsBuilder::form(2));
        self::assertSame('few', RoomResultsBuilder::form(19));
        self::assertSame('many', RoomResultsBuilder::form(20));
        self::assertSame('many', RoomResultsBuilder::form(30));
        self::assertSame('few', RoomResultsBuilder::form(101));
        self::assertSame('many', RoomResultsBuilder::form(120));
    }

    public function testNoRatesNoRooms(): void
    {
        $view = self::builder()->build([new RateOffer('R', 'R', 'BB', 0.0, 'b')], 7);

        self::assertSame(0, $view['rate_count']);
        self::assertFalse($view['meals_included']);
        self::assertSame([], $view['rooms']);
        self::assertSame('', $view['cheapest']);
        self::assertNull($view['more']);
        self::assertFalse($view['struck']);
    }
}
