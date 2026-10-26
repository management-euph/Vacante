<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Results;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Results\RateOffer;
use Tygh\Addons\TravelCore\Results\RoomResultsBuilder;
use Tygh\Addons\TravelCore\Services\MoneyFormatter;

/**
 * The room-first results of the Design A hotel page, by the mockup's rules
 * (Design A board frames 3, 10 and 12): R1 meal strip only with 2+ meal
 * plans, R2 one meal plan said once, R3 chips only when they split the list,
 * the best 4 per room and the collapse of a long list.
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
     * Admiral, Golden Sands, 10–17 Jul 2027 (the store's export): eight room
     * types with one rate each, all Ultra All Inclusive, all instant, all free
     * to cancel until 30 Jun 2027, all Early Booking −30%.
     *
     * @return list<RateOffer>
     */
    private static function admiral(): array
    {
        $rows = [
            ['DBL 2+0 DELUXE NO BALCONY', 'Cameră dublă', 'Deluxe · fără balcon', 1485, 4],
            ['DBL 2+1 DELUXE', 'Cameră dublă', 'Deluxe', 1524, 19],
            ['DBL 2+1 DELUXE SEA SIDE', 'Cameră dublă', 'Deluxe · spre mare', 1622, 19],
            ['DBL 2+1 SUPERIOR DELUXE SEA SIDE', 'Cameră dublă', 'Superior Deluxe · spre mare', 2183, 1],
            ['JUNIOR SUITE 2+1 DELUXE SEA SIDE', 'Junior suite', 'Deluxe · spre mare', 2355, 1],
            ['1-BR APP 2+1 DELUXE SEA SIDE', 'Apartament cu un dormitor', 'Deluxe · spre mare', 2526, 1],
            ['FAM 2+3 DELUXE', 'Cameră de familie', 'Deluxe', 2632, 3],
            ['FAM 2+3 DELUXE SEA SIDE', 'Cameră de familie', 'Deluxe · spre mare', 2764, 3],
        ];
        $rates = [];
        // The supplier's order is not the price order.
        foreach (array_reverse($rows) as [$code, $name, $detail, $price, $left]) {
            $rates[] = new RateOffer(
                roomKey: $code,
                roomName: $name,
                mealPlan: 'Ultra All Inclusive',
                price: (float) $price,
                bookUrl: 'book?room=' . rawurlencode($code),
                roomDetail: $detail,
                mealRank: RateOffer::MEAL_ULTRA_ALL_INCLUSIVE,
                standardPrice: round($price / 0.7),
                discountLabel: 'Early Booking −30%',
                perks: ['Acces la plajă inclus'],
                freeCancellationUntil: '2027-06-30',
                roomsLeft: $left,
                terms: [['title' => 'Condiții de anulare', 'lines' => ['Anulare gratuită până la 30.06.2027']]],
            );
        }

        return $rates;
    }

    public function testOneMealPlanIsSaidOnceAndSharedFactsAreNotRepeated(): void
    {
        $view = self::builder()->build(self::admiral(), 7);

        self::assertSame(8, $view['rate_count']);
        self::assertSame('few', $view['rate_form']);
        self::assertSame(8, $view['room_count']);
        self::assertFalse($view['grouped'], 'R2: no meal sub-headers');
        self::assertCount(1, $view['meal_plans'], 'the template shows the strip only with 2+ plans');
        self::assertSame(
            ['meal' => 'Ultra All Inclusive', 'immediate' => true, 'free_until' => '30.06.2027'],
            $view['facts'],
        );
        self::assertSame([], $view['chips'], 'R3: every rate is free, instant and special — no chip splits the list');
        self::assertSame('1.485 €', $view['cheapest']);

        foreach ($view['rooms'] as $room) {
            self::assertSame('', $room['status'], 'the fact line already says "instant"');
            self::assertSame(RoomResultsBuilder::CANCEL_SAID, $room['cancellation']);
            self::assertSame(['on_request' => false, 'deposit_percent' => 0, 'balance_due' => ''], $room['payment']);
            self::assertCount(1, $room['groups']);
            self::assertSame('', $room['groups'][0]['meal']);
            self::assertFalse($room['collapsed']);
            self::assertSame(0, $room['hidden']);
        }
    }

    public function testRoomsComeCheapestFirstAndSayHowFewAreLeft(): void
    {
        $rooms = self::builder()->build(self::admiral(), 7)['rooms'];

        self::assertSame(
            ['1.485 €', '1.524 €', '1.622 €', '2.183 €', '2.355 €', '2.526 €', '2.632 €', '2.764 €'],
            array_map(static fn (array $room): string => $room['groups'][0]['rates'][0]['price'], $rooms),
        );
        self::assertSame([0, 0, 0, 1, 1, 1, 3, 3], array_column($rooms, 'left'), '4 or more left: nothing to say');

        $first = $rooms[0]['groups'][0]['rates'][0];
        self::assertTrue($first['cheapest']);
        self::assertSame('Early Booking −30%', $first['discount']);
        self::assertSame('636 €', $first['saving'], '2.121 € before the discount');
        self::assertSame('212 €', $first['per_night']);
        self::assertSame(['Acces la plajă inclus'], $first['perks']);
        self::assertSame('book?room=DBL%202%2B0%20DELUXE%20NO%20BALCONY', $first['book_url']);
        self::assertFalse($rooms[1]['groups'][0]['rates'][0]['cheapest'], 'one cheapest rate in the hotel');
        self::assertSame('room-0', $rooms[0]['key']);
        self::assertSame(['Cameră dublă', 'Deluxe · fără balcon'], [$rooms[0]['name'], $rooms[0]['detail']]);
        self::assertNotSame([], $rooms[0]['terms'], 'one set of terms for the room');
    }

    /**
     * Frame 3: four meal plans, three rooms, one of them on request.
     *
     * @return list<RateOffer>
     */
    private static function mixed(): array
    {
        $rate = static fn (string $room, string $meal, int $rank, float $price, array $more = []): RateOffer => new RateOffer(...array_merge([
            'roomKey' => $room,
            'roomName' => $room,
            'mealPlan' => $meal,
            'price' => $price,
            'bookUrl' => 'book',
            'mealRank' => $rank,
        ], $more));

        return [
            $rate('Deluxe', 'Ultra All Inclusive', 5, 502, ['standardPrice' => 665.0, 'discountLabel' => 'Early Booking −25%', 'freeCancellationUntil' => '2026-10-22']),
            $rate('Deluxe', 'Ultra All Inclusive', 5, 665, ['freeCancellationUntil' => '2026-10-22']),
            $rate('Deluxe', 'Mic dejun', 1, 565, ['freeCancellationUntil' => '2026-10-22']),
            $rate('Standard', 'Demipensiune', 2, 540, ['freeCancellationUntil' => '2026-10-22']),
            $rate('Standard', 'All Inclusive', 4, 575, ['standardPrice' => 610.0, 'rateName' => 'Tarif nerambursabil', 'nonRefundable' => true]),
            $rate('Standard', 'All Inclusive', 4, 610, ['freeCancellationUntil' => '2026-10-22']),
            $rate('Sea view', 'All Inclusive', 4, 720, ['onRequest' => true, 'freeCancellationUntil' => '2026-10-20']),
            $rate('Sea view', 'Ultra All Inclusive', 5, 790, ['onRequest' => true, 'freeCancellationUntil' => '2026-10-20']),
        ];
    }

    public function testSeveralMealPlansGetTheStripAndGroupEachRoomsRates(): void
    {
        $view = self::builder()->build(self::mixed(), 5);

        self::assertTrue($view['grouped']);
        self::assertSame(
            [
                ['key' => 'm0', 'name' => 'Mic dejun', 'from' => '565 €', 'count' => 1],
                ['key' => 'm1', 'name' => 'Demipensiune', 'from' => '540 €', 'count' => 1],
                ['key' => 'm2', 'name' => 'All Inclusive', 'from' => '575 €', 'count' => 3],
                ['key' => 'm3', 'name' => 'Ultra All Inclusive', 'from' => '502 €', 'count' => 3],
            ],
            $view['meal_plans'],
            'lightest meal plan first, each with its lowest price',
        );
        self::assertSame(['meal' => '', 'immediate' => false, 'free_until' => ''], $view['facts']);
        self::assertSame(
            [['key' => 'free', 'count' => 7], ['key' => 'immediate', 'count' => 6], ['key' => 'special', 'count' => 2]],
            $view['chips'],
        );

        [$deluxe, $standard, $sea] = $view['rooms'];
        self::assertSame(['Deluxe', 'Standard', 'Sea view'], [$deluxe['name'], $standard['name'], $sea['name']]);
        self::assertSame(
            [['Ultra All Inclusive', 'm3', 2], ['Mic dejun', 'm0', 1]],
            array_map(static fn (array $g): array => [$g['meal'], $g['meal_key'], $g['count']], $deluxe['groups']),
            'a room\'s meal groups come cheapest first',
        );
        self::assertSame(RoomResultsBuilder::STATUS_IMMEDIATE, $deluxe['status']);
        self::assertSame([RoomResultsBuilder::CANCEL_FREE, '22.10.2026'], [$deluxe['cancellation'], $deluxe['free_until']]);
        self::assertSame(RoomResultsBuilder::CANCEL_DIFFERS, $standard['cancellation'], 'each rate says its own');
        $nonRefundable = $standard['groups'][1]['rates'][0];
        self::assertSame(['Tarif nerambursabil', true, '', '35 €'], [
            $nonRefundable['name'], $nonRefundable['non_refundable'], $nonRefundable['free_until'], $nonRefundable['saving'],
        ]);
        self::assertSame(RoomResultsBuilder::STATUS_ON_REQUEST, $sea['status']);
        self::assertSame(['on_request' => true, 'deposit_percent' => 0, 'balance_due' => ''], $sea['payment']);
        self::assertTrue($deluxe['groups'][0]['rates'][0]['cheapest']);
    }

    public function testADepositIsSaidOnlyWhenItCanBePaid(): void
    {
        $plain = ['roomKey' => 'R', 'roomName' => 'R', 'mealPlan' => 'BB', 'price' => 100.0, 'bookUrl' => 'b'];
        $rooms = self::builder()->build([
            new RateOffer(...$plain + ['depositRatio' => 0.2, 'balanceDue' => '2027-06-26']),
            new RateOffer(...$plain + ['price' => 120.0, 'depositRatio' => 0.2, 'balanceDue' => '2027-06-26']),
        ], 7)['rooms'];
        self::assertSame(['on_request' => false, 'deposit_percent' => 20, 'balance_due' => '26.06.2027'], $rooms[0]['payment']);

        $rooms = self::builder()->build([
            new RateOffer(...$plain + ['depositRatio' => 0.2, 'balanceDue' => '2027-06-26']),
            new RateOffer(...$plain + ['price' => 120.0]),
        ], 7)['rooms'];
        self::assertNull($rooms[0]['payment'], 'the rates differ: each says its own');
        self::assertSame(0, $rooms[0]['groups'][0]['rates'][1]['payment']['deposit_percent']);
    }

    /**
     * Frame 10: 30 rates in 4 room types. The first room shows the cheapest
     * rate of each meal plan; the others collapse to their cheapest rate.
     */
    public function testALongListShowsTheBestFourAndCollapsesTheOtherRooms(): void
    {
        $meals = [['Mic dejun', 1], ['Demipensiune', 2], ['All Inclusive', 4], ['Ultra All Inclusive', 5]];
        $rates = [];
        foreach (['Superior' => [610, 11], 'Sea view' => [690, 9], 'Family' => [980, 6], 'Standard' => [480, 4]] as $room => [$from, $n]) {
            for ($i = 0; $i < $n; $i++) {
                [$meal, $rank] = $meals[$i % 4];
                $rates[] = new RateOffer($room, $room, $meal, $from + 50.0 * $i, 'book', mealRank: $rank);
            }
        }
        $view = self::builder()->build($rates, 5);

        self::assertSame(30, $view['rate_count']);
        self::assertSame('many', $view['rate_form'], '"30 de tarife"');
        [$standard, $superior, $sea, $family] = $view['rooms'];
        self::assertSame(['Standard', 'Superior', 'Sea view', 'Family'], array_column($view['rooms'], 'name'));

        self::assertFalse($standard['collapsed'], 'the first room is open');
        self::assertSame(0, $standard['hidden'], 'four rates: all of them');

        self::assertTrue($superior['collapsed']);
        self::assertSame(10, $superior['hidden']);
        self::assertSame('610 € – 1.110 €', $superior['range']);
        self::assertSame('610 €', $superior['cheapest_rate']['price']);
        self::assertSame(11, $superior['rate_count']);

        // Opened by "see all" (or never collapsed): the best four first.
        $open = self::builder()->build(array_values(array_filter($rates, static fn (RateOffer $r): bool => $r->roomKey === 'Superior')), 5)['rooms'][0];
        self::assertFalse($open['collapsed']);
        self::assertSame(7, $open['hidden']);
        $shown = [];
        foreach ($open['groups'] as $group) {
            foreach ($group['rates'] as $rate) {
                if ($rate['shown']) {
                    $shown[] = $group['meal'] . ' ' . $rate['price'];
                }
            }
        }
        self::assertSame(['Mic dejun 610 €', 'Demipensiune 660 €', 'All Inclusive 710 €', 'Ultra All Inclusive 760 €'], $shown);
        self::assertTrue($open['groups'][0]['shown']);
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
        self::assertSame([], $view['rooms']);
        self::assertSame('', $view['cheapest']);
    }
}
