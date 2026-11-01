<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\ViewModels;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Services\MoneyFormatter;
use Tygh\Addons\TravelCore\ViewModels\TermsTimelineFactory;

/**
 * One cancellation & payment timeline for every provider, each feeding its
 * own API terms (normalised to windows / installments).
 */
final class TermsTimelineFactoryTest extends TestCase
{
    private static function factory(string $today = '2026-09-26'): TermsTimelineFactory
    {
        $eur = new MoneyFormatter(['symbol' => '€', 'after' => 'Y', 'decimals' => 2, 'decimals_separator' => ',', 'thousands_separator' => '.']);

        return new TermsTimelineFactory($eur, $today, '%m/%d/%Y');
    }

    public function testEurositePenaltyAlreadyRunningHasNoFreeStep(): void
    {
        // SCANDINAVIA CM, live getItemFees 26.09.2026: 80% now, then 100%.
        $t = self::factory()->cancellation([
            ['from' => '2026-09-26', 'to' => '2026-09-28', 'percent' => 80],
            ['from' => '2026-09-29', 'to' => '2026-10-05', 'percent' => 100],
        ], 299.0, 6);

        self::assertCount(2, $t['steps']);
        self::assertSame('partial', $t['steps'][0]['kind']);
        self::assertTrue($t['steps'][0]['is_current']);
        self::assertSame('', $t['steps'][0]['from_label'], 'a current window reads "until …"');
        self::assertSame('09/28/2026', $t['steps'][0]['to_label']);
        self::assertSame('80%', $t['steps'][0]['percent_label']);
        self::assertSame('239,20 €', $t['steps'][0]['amount_label']);
        self::assertSame('full', $t['steps'][1]['kind']);
        self::assertSame('09/29/2026', $t['steps'][1]['from_label']);
        self::assertSame('', $t['free_until'], 'never "free until" while a penalty applies');
        self::assertFalse($t['full_charge_now']);
    }

    public function testEurositeExplicitFreeWindowIsTheFreeUntil(): void
    {
        // ONE66, live: 0% until 25.10, then 100%.
        $t = self::factory()->cancellation([
            ['from' => '2026-09-26', 'to' => '2026-10-25', 'percent' => 0],
            ['from' => '2026-10-26', 'to' => '2026-11-16', 'percent' => 100],
        ], 586.0);

        self::assertSame('free', $t['steps'][0]['kind']);
        self::assertTrue($t['steps'][0]['is_current']);
        self::assertSame('10/25/2026', $t['free_until']);
        self::assertSame('', $t['steps'][0]['amount_label']);
        self::assertSame('586,00 €', $t['steps'][1]['amount_label']);
    }

    public function testSphinxCumulativeRulesGetAFreeStepBeforeTheFirstPenalty(): void
    {
        // rules[{since, value}]: the provider passes one window per rule; the
        // factory closes the gap and adds the free stretch before 01.10.
        $t = self::factory()->cancellation([
            ['from' => '2026-10-01', 'percent' => 30],
            ['from' => '2026-10-04', 'percent' => 100],
        ], 673.0);

        self::assertCount(3, $t['steps']);
        self::assertSame('free', $t['steps'][0]['kind']);
        self::assertSame('09/30/2026', $t['free_until'], 'the LAST free day, not the first penalty day');
        self::assertSame('10/03/2026', $t['steps'][1]['to_label']);
        self::assertSame('201,90 €', $t['steps'][1]['amount_label']);
        self::assertSame('', $t['steps'][2]['to_label'], 'the last rule runs until check-in');
    }

    public function testFirstPenaltyTodayMeansNoFreeUntil(): void
    {
        // The bug that printed "Free cancellation until 09/25" next to
        // "you'll pay 673 $": the first `since` IS today.
        $t = self::factory('2026-09-25')->cancellation([['from' => '2026-09-25', 'amount' => 673.0]], 673.0);

        self::assertCount(1, $t['steps']);
        self::assertSame('full', $t['steps'][0]['kind']);
        self::assertSame('', $t['free_until']);
        self::assertTrue($t['full_charge_now']);
        self::assertSame('100%', $t['steps'][0]['percent_label']);
    }

    public function testNovotonTillDatesNightsAndNoShow(): void
    {
        // Penalty tillDate rows: only the END of each window is known.
        $t = self::factory()->cancellation([
            ['to' => '2026-09-30', 'percent' => 0],
            ['to' => '2026-10-02', 'nights' => 2],
            ['to' => '2026-10-05', 'percent' => 100],
            ['percent' => 100, 'no_show' => true],
        ], 673.0, 6);

        self::assertCount(4, $t['steps']);
        self::assertSame('09/30/2026', $t['free_until']);
        self::assertSame('10/01/2026', $t['steps'][1]['from_label']);
        self::assertSame(2, $t['steps'][1]['nights']);
        self::assertSame('', $t['steps'][1]['percent_label']);
        self::assertSame('224,33 €', $t['steps'][1]['amount_label']);
        self::assertTrue($t['steps'][3]['is_no_show']);
        self::assertFalse($t['steps'][3]['is_current']);
    }

    /**
     * Order #19 (Ardo Vel, 29 Oct 2026, check-in 2 Nov, €499): novoton's
     * first window came out as "499% · €2,490.01" and the next as "100%".
     * A penalty is at most the full price, and two windows that charge the
     * same are one.
     */
    public function testAPenaltyAboveTheFullPriceIsTheFullPriceAndEqualWindowsMerge(): void
    {
        $t = self::factory('2026-10-29')->cancellation([
            ['to' => '2026-10-30', 'percent' => 499],
            ['to' => '2026-11-02', 'percent' => 100],
        ], 499.0, 6);

        self::assertCount(1, $t['steps']);
        self::assertSame('full', $t['steps'][0]['kind']);
        self::assertTrue($t['steps'][0]['is_current']);
        self::assertSame('11/02/2026', $t['steps'][0]['to_label']);
        self::assertSame('100%', $t['steps'][0]['percent_label']);
        self::assertSame('499,00 €', $t['steps'][0]['amount_label']);
        self::assertTrue($t['full_charge_now']);
    }

    public function testAnAmountWindowMergesWithTheSameChargeAsAPercent(): void
    {
        $t = self::factory('2026-10-29')->cancellation([
            ['to' => '2026-10-30', 'amount' => 499.0],
            ['to' => '2026-11-02', 'percent' => 100],
        ], 499.0, 6);

        self::assertCount(1, $t['steps']);
        self::assertSame('100%', $t['steps'][0]['percent_label']);
        self::assertSame('499,00 €', $t['steps'][0]['amount_label']);
    }

    public function testDifferentChargesStayApart(): void
    {
        $t = self::factory('2026-10-29')->cancellation([
            ['to' => '2026-10-30', 'percent' => 50],
            ['to' => '2026-11-02', 'percent' => 100],
        ], 499.0, 6);

        self::assertCount(2, $t['steps']);
        self::assertSame(['50%', '100%'], array_column($t['steps'], 'percent_label'));
    }

    public function testWindowsThatAlreadyEndedAreDropped(): void
    {
        $t = self::factory()->cancellation([
            ['from' => '2026-09-01', 'to' => '2026-09-20', 'percent' => 0],
            ['from' => '2026-09-21', 'to' => '2026-10-05', 'percent' => 50],
        ], 100.0);

        self::assertCount(1, $t['steps']);
        self::assertTrue($t['steps'][0]['is_current']);
        self::assertSame('', $t['free_until']);
    }

    public function testNoUsableRowsGiveNoSteps(): void
    {
        $t = self::factory()->cancellation([['from' => '2026-10-01'], []], 100.0);

        self::assertSame([], $t['steps']);
        self::assertSame('', $t['free_until']);
    }

    public function testPaymentRowsOnBookingAndPastDatesAreDueNow(): void
    {
        $rows = self::factory()->payment([
            ['due' => '2026-10-01', 'percent' => 70],
            ['due' => null, 'percent' => 30],
            ['due' => '2026-09-14', 'percent' => 0],
        ], 673.0);

        self::assertCount(2, $rows, 'zero instalments are dropped');
        self::assertTrue($rows[0]['is_now']);
        self::assertSame('30%', $rows[0]['percent_label']);
        self::assertSame('201,90 €', $rows[0]['amount_label']);
        self::assertFalse($rows[1]['is_now']);
        self::assertSame('10/01/2026', $rows[1]['due_label']);
        self::assertSame('471,10 €', $rows[1]['amount_label']);
    }

    public function testPaymentAmountOnlyDerivesThePercent(): void
    {
        $rows = self::factory()->payment([['due' => '2026-09-20', 'amount' => 250.0]], 1000.0);

        self::assertTrue($rows[0]['is_now'], 'a due date already passed is due now');
        self::assertSame('25%', $rows[0]['percent_label']);
    }

    /** 30% on booking, 70% later: the price card shows deposit and balance. */
    public function testSplitShowsDepositAndBalance(): void
    {
        $split = self::factory()->split([
            ['due' => null, 'percent' => 30],
            ['due' => '2026-10-20', 'percent' => 70],
        ], 360.0);

        self::assertSame(['full' => '360,00 €', 'deposit' => '108,00 €', 'balance' => '252,00 €', 'balance_due' => '10/20/2026'], $split);
    }

    public function testSplitTakesTheLastBalanceDate(): void
    {
        $split = self::factory()->split([
            ['due' => '2026-09-20', 'amount' => 100.0],
            ['due' => '2026-10-10', 'amount' => 100.0],
            ['due' => '2026-11-01', 'amount' => 200.0],
        ], 400.0);

        self::assertSame('100,00 €', $split['deposit'] ?? null, 'a passed date counts as due now');
        self::assertSame('300,00 €', $split['balance'] ?? null);
        self::assertSame('11/01/2026', $split['balance_due'] ?? null);
    }

    /** No choice to show: one payment, all due now, nothing due now, or the balance is days away. */
    public function testSplitIsEmptyWhenTheTermsDoNotSplit(): void
    {
        $f = self::factory();

        self::assertSame([], $f->split([['due' => '2026-10-18', 'percent' => 100]], 393.0), 'one payment later');
        self::assertSame([], $f->split([['due' => null, 'percent' => 100]], 393.0), 'all now');
        self::assertSame([], $f->split([['due' => null, 'percent' => 30], ['due' => '2026-10-02', 'percent' => 70]], 360.0), 'balance within 7 days');
        self::assertNotSame([], $f->split([['due' => null, 'percent' => 30], ['due' => '2026-10-03', 'percent' => 70]], 360.0), 'balance 7 days away');
        self::assertSame([], $f->split([], 360.0));
    }

    /**
     * Novoton's windows for Admiral, 9-16 Nov 2026 (room_price of 1 Nov 2026): free until 4 Nov,
     * 2 nights 5-8 Nov, 100% from 9 Nov (the arrival); 785 € for 7 nights, each 110 € (priceinfo)
     * plus a 15 € handling fee: each night 110/785 of the price (nightShares()).
     *
     * @return list<array<string, mixed>>
     */
    private static function admiral(): array
    {
        return [
            ['to' => '2026-11-04', 'nights' => 0, 'no_show' => false],
            ['to' => '2026-11-08', 'nights' => 2, 'no_show' => false],
            ['to' => '2026-11-09', 'percent' => 100.0, 'no_show' => false],
        ];
    }

    public function testNovotonDeadlinesEndAt2359AndTheLastPeriodGoesOnFromTheArrival(): void
    {
        $t = self::factory('2026-11-01')->cancellation(self::admiral(), 785.0, 7, [
            'cutoff' => '23:59',
            'check_in' => '2026-11-09',
            'night_shares' => self::nightShares([110, 110, 110, 110, 110, 110, 110], 785.0),
        ]);

        self::assertSame('23:59', $t['cutoff']);
        self::assertSame(['free', 'partial', 'full'], array_column($t['steps'], 'kind'));
        [$free, $two, $all] = $t['steps'];
        self::assertSame(['11/04/2026', '23:59', ''], [$free['to_label'], $free['to_time'], $free['to_rel']]);
        self::assertSame(['220,00 €', 2, false], [$two['amount_label'], $two['nights'], $two['approx']], 'the first two nights, at their own price');
        self::assertSame(['785,00 €', true, true, '11/09/2026'], [$all['amount_label'], $all['is_last'], $all['from_arrival'], $all['from_label']]);
        self::assertFalse($two['is_last']);
    }

    public function testWithoutEachNightsPriceNNightsAreTheAverageAndSaySo(): void
    {
        $two = self::factory('2026-11-01')->cancellation(self::admiral(), 785.0, 7, ['check_in' => '2026-11-09'])['steps'][1];

        self::assertSame(['224,29 €', true], [$two['amount_label'], $two['approx']]);
        self::assertSame('', $two['to_time'], 'no hour without the provider\'s');
    }

    public function testADeadlineOfTodayOrTomorrowIsNamedSo(): void
    {
        $tomorrow = self::factory('2026-11-03')->cancellation(self::admiral(), 785.0, 7, ['cutoff' => '23:59'])['steps'][0];
        $today = self::factory('2026-11-04')->cancellation(self::admiral(), 785.0, 7, ['cutoff' => '23:59'])['steps'][0];

        self::assertSame(['free', 'tomorrow'], [$tomorrow['kind'], $tomorrow['to_rel']]);
        self::assertSame(['free', 'today'], [$today['kind'], $today['to_rel']]);
    }

    public function testPastTheFreeWindowThePenaltyHoldsTodayAndOnArrivalDayEverything(): void
    {
        $meta = ['cutoff' => '23:59', 'check_in' => '2026-11-09', 'night_shares' => self::nightShares(array_fill(0, 7, 110), 785.0)];
        $sixth = self::factory('2026-11-06')->cancellation(self::admiral(), 785.0, 7, $meta);
        self::assertSame(['partial', 'full'], array_column($sixth['steps'], 'kind'));
        self::assertTrue($sixth['steps'][0]['is_current']);
        self::assertSame('', $sixth['free_until']);

        $ninth = self::factory('2026-11-09')->cancellation(self::admiral(), 785.0, 7, $meta);
        self::assertCount(1, $ninth['steps']);
        self::assertSame(['full', true, true], [$ninth['steps'][0]['kind'], $ninth['steps'][0]['is_current'], $ninth['steps'][0]['is_last']]);
        self::assertTrue($ninth['full_charge_now']);
    }

    public function testTheFirstNightsAreTheirOwnSeasonsNotTheAverage(): void
    {
        // 28 Nov - 5 Dec: 3 nights at 110 €, 4 at 120 € (December), 15 € handling fee: 825 €
        $two = self::factory('2026-11-01')->cancellation(self::admiral(), 825.0, 7, [
            'night_shares' => self::nightShares([110, 110, 110, 120, 120, 120, 120], 825.0),
        ])['steps'][1];

        self::assertSame(['220,00 €', false], [$two['amount_label'], $two['approx']], 'not 235,71 €, the average');
    }

    public function testNightSharesThatAreNotOnePerNightOrNotPartsOfTheWholeAreNotUsed(): void
    {
        $cases = [
            'two of seven' => [0.14, 0.14],
            'negative' => [0.5, -0.1, 0.1, 0.1, 0.1, 0.1, 0.1],
            'more than the whole' => array_fill(0, 7, 0.2),
            'not numbers' => array_fill(0, 7, '0.14'),
            'not a list' => '0.14',
        ];
        foreach ($cases as $name => $shares) {
            $two = self::factory('2026-11-01')->cancellation(self::admiral(), 785.0, 7, ['night_shares' => $shares])['steps'][1];

            self::assertSame(['224,29 €', true], [$two['amount_label'], $two['approx']], $name);
        }
    }

    /**
     * Each night's share of a stay's price, as the provider gives them.
     *
     * @param list<int|float> $nights each night's price
     * @return list<float>
     */
    private static function nightShares(array $nights, float $total): array
    {
        return array_map(static fn (int|float $night): float => $night / $total, $nights);
    }
}
