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
}
