<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\Services\NightShares;

/**
 * "Over Nights" penalties at each night's own price (board 14, 1 Nov 2026):
 * Admiral, DBL 2+1 DELUXE, BB, 2 adults, 9-16 Nov 2026 is 7 nights at 110 €
 * (55 € a person) plus a 15 € handling fee (7,50 € a person), 785 € as
 * room_price quotes it.
 */
final class NightSharesTest extends TestCase
{
    /**
     * A PriceInfoCalculation answer.
     *
     * @param list<float> $nights each night's base price (net)
     * @return array<string, mixed>
     */
    private static function calc(array $nights, float $handling = 15.0, float $commission = 0.0, float $discount = 0.0): array
    {
        $net = array_sum($nights) * (1 - $discount) + $handling;
        $byNight = [];
        foreach ($nights as $i => $price) {
            $byNight[$i] = ['date' => date('Y-m-d', (int) strtotime('2026-11-09 +' . $i . ' days')), 'season' => 1, 'price' => $price];
        }

        return [
            'success' => true,
            'price' => round($net * (1 + $commission / 100), 2),
            'price_without_commission' => round($net, 2),
            'breakdown' => [
                'base_price' => array_sum($nights),
                'base_per_night' => $byNight,
                'fees' => ['handling_fee' => $handling, 'company_fee' => 0.0, 'total_fees' => $handling],
            ],
        ];
    }

    /** @param list<float> $shares */
    private static function firstNights(array $shares, int $n, float $total): float
    {
        return round($total * array_sum(array_slice($shares, 0, $n)), 2);
    }

    public function testTheAdmiralsTwoNightsAre220NotTheAverage(): void
    {
        $shares = NightShares::fromCalculation(self::calc(array_fill(0, 7, 110.0)), 7, 785.0);

        self::assertCount(7, $shares);
        self::assertSame(220.0, self::firstNights($shares, 2, 785.0), 'not 224,29 €, the average with the fee');
        self::assertSame(770.0, self::firstNights($shares, 7, 785.0), 'the fee is no night\'s');
    }

    public function testTheCommissionAndTheCurrencyDoNotChangeTheShares(): void
    {
        $shares = NightShares::fromCalculation(self::calc(array_fill(0, 7, 110.0), 15.0, 10.0), 7, 863.5);

        self::assertSame(242.0, self::firstNights($shares, 2, 863.5), '220 € and the 10% commission');
        self::assertSame(1210.0, self::firstNights($shares, 2, 863.5 * 5), 'the same shares of a price in lei');
    }

    public function testTwoSeasonsTheFirstNightsAreTheirOwnSeasons(): void
    {
        // 28 Nov - 5 Dec: 3 nights at 110 €, 4 at 120 € (December): 825 €
        $shares = NightShares::fromCalculation(self::calc([110.0, 110.0, 110.0, 120.0, 120.0, 120.0, 120.0]), 7, 825.0);

        self::assertSame(220.0, self::firstNights($shares, 2, 825.0), 'not 235,71 €');
        self::assertSame(450.0, self::firstNights($shares, 4, 825.0));
    }

    public function testADiscountFollowsEachNight(): void
    {
        // Early booking 30% on the nights, not on the handling fee: 539 € + 15 €
        $shares = NightShares::fromCalculation(self::calc(array_fill(0, 7, 110.0), 15.0, 0.0, 0.3), 7, 554.0);

        self::assertSame(154.0, self::firstNights($shares, 2, 554.0), 'two nights at 77 €');
    }

    public function testPriceinfoThatDoesNotRebuildTheQuoteIsNotUsed(): void
    {
        $calc = self::calc(array_fill(0, 7, 110.0));

        self::assertSame([], NightShares::fromCalculation($calc, 7, 790.5), 'the quote moved: 5,50 € off');
        self::assertNotSame([], NightShares::fromCalculation($calc, 7, 785.99), 'within 1 €, as the admin\'s comparison');
        self::assertSame([], NightShares::fromCalculation($calc, 6, 785.0), 'not one price a night');
        self::assertSame([], NightShares::fromCalculation(['success' => false, 'error' => 'Priceinfo not found'], 7, 785.0));
        self::assertSame([], NightShares::fromCalculation(['success' => true, 'price' => 785.0], 7, 785.0), 'no breakdown');
        self::assertSame([], NightShares::fromCalculation(self::calc(array_fill(0, 7, 0.0), 785.0), 7, 785.0), 'all fee, no night');
    }

    public function testOnlyAPenaltyCountingFewerNightsThanTheStayNeedsThem(): void
    {
        $windows = static fn (?int $nights, ?float $percent = null): array => [
            ['to' => '2026-11-04', 'percent' => 0.0, 'nights' => null, 'no_show' => false],
            ['to' => '2026-11-08', 'percent' => $percent, 'nights' => $nights, 'no_show' => false],
        ];

        self::assertTrue(NightShares::needed($windows(2), 7));
        self::assertFalse(NightShares::needed($windows(0), 7), 'free');
        self::assertFalse(NightShares::needed($windows(7), 7), 'every night is the whole price');
        self::assertFalse(NightShares::needed($windows(null, 50.0), 7), 'a percent');
    }

    public function testSeveralRoomsWeighEachRoomsNightsByItsPrice(): void
    {
        $admiral = NightShares::fromCalculation(self::calc(array_fill(0, 7, 110.0)), 7, 785.0);
        $single = NightShares::fromCalculation(self::calc(array_fill(0, 7, 55.0), 7.5), 7, 392.5);
        $both = NightShares::combined([['price' => 785.0, 'shares' => $admiral], ['price' => 392.5, 'shares' => $single]]);

        self::assertSame(330.0, self::firstNights($both, 2, 1177.5), '220 € + 110 €');
        self::assertSame([], NightShares::combined([['price' => 785.0, 'shares' => $admiral], ['price' => 392.5, 'shares' => []]]));
        self::assertSame([], NightShares::combined([['price' => 785.0, 'shares' => $admiral], ['price' => 392.5, 'shares' => [0.5, 0.5]]]));
        self::assertSame([], NightShares::combined([]));
    }

    public function testABookingsCartLineWeighsEachRoom(): void
    {
        $terms = '<TermsOfCancellation><Penalty tillDate="2026-11-04" Type="Over Nights">0</Penalty>'
            . '<Penalty tillDate="2026-11-08" Type="Over Nights">2</Penalty>'
            . '<Penalty tillDate="2026-11-09" Type="Percent">100</Penalty></TermsOfCancellation>';
        $shares = new NightShares(static fn (array $query): array => $query['adults'] === 2
            ? self::calc(array_fill(0, 7, 110.0))
            : self::calc(array_fill(0, 7, 55.0), 7.5));
        $room = static fn (int $adults, float $price, string $extBoard = ''): array => [
            'room_id' => 'DBL 2+1 DELUXE', 'board_id' => 'BB', 'ext_board_id' => $extBoard, 'package_name' => 'ADMIRAL ***** BB/HB',
            'adults' => $adults, 'childrenAges' => [], 'price' => $price,
        ];

        $both = $shares->forBooking('476', [$room(2, 785.0), $room(1, 392.5)], '2026-11-09', 7, $terms);
        self::assertSame(330.0, self::firstNights($both, 2, 1177.5));

        self::assertSame([], $shares->forBooking('476', [$room(2, 785.0), $room(1, 392.5, 'HB')], '2026-11-09', 7, $terms), 'a board with a supplement');
        self::assertSame([], $shares->forBooking('476', [$room(2, 785.0)], '2026-11-09', 7, str_replace('Over Nights">2', 'Percent">50', $terms)), 'no nights');
        self::assertSame([], $shares->forBooking('476', [], '2026-11-09', 7, $terms));
    }

    public function testEachQuestionIsCalculatedOnceAndAFailureIsNoShares(): void
    {
        $calls = 0;
        $shares = new NightShares(static function (array $query) use (&$calls): array {
            $calls++;
            if ($query['room_id'] === 'BROKEN') {
                throw new \RuntimeException('priceinfo unreadable');
            }

            return self::calc(array_fill(0, 7, 110.0));
        });
        $query = [
            'hotel_id' => '476', 'package_name' => 'ADMIRAL ***** BB/HB', 'room_id' => 'DBL 2+1 DELUXE', 'board_id' => 'BB',
            'check_in' => '2026-11-09', 'nights' => 7, 'adults' => 2, 'children_ages' => [],
        ];

        self::assertCount(7, $shares->for($query, 785.0));
        self::assertSame([], $shares->for($query, 700.0), 'the same answer, another quote');
        self::assertSame(1, $calls);
        self::assertSame([], $shares->for(['room_id' => 'BROKEN'] + $query, 785.0));
        self::assertSame(2, $calls);
    }
}
