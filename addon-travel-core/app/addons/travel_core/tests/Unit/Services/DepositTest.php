<?php

declare(strict_types=1);

namespace Tygh\Addons\TravelCore\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\TravelCore\Services\DepositCartLine;
use Tygh\Addons\TravelCore\Services\DepositPlan;
use Tygh\Addons\TravelCore\Services\DepositPolicy;

/**
 * "Pay a deposit now, the balance later": the plan from the supplier's
 * schedule, the cart line that charges only the deposit, and who offers it.
 */
final class DepositTest extends TestCase
{
    private const TODAY = '2026-09-28';

    public function testThirtySeventyIsADepositOfThirtyPercent(): void
    {
        $plan = DepositPlan::fromInstallments([
            ['due' => null, 'percent' => 30],
            ['due' => '2026-10-20', 'percent' => 70],
        ], self::TODAY);

        self::assertNotNull($plan);
        self::assertSame(0.3, $plan->ratio);
        self::assertSame('2026-10-20', $plan->balanceDue);
        self::assertSame(['deposit' => 108.0, 'balance' => 252.0], $plan->amounts(360.0));
    }

    /** Sphinx sends net amounts that don't sum to the price: the ratio is what counts. */
    public function testAmountRowsBecomeAShareOfTheSchedule(): void
    {
        $plan = DepositPlan::fromInstallments([
            ['due' => '2026-09-01', 'amount' => 50.0],
            ['due' => '2026-11-01', 'amount' => 150.0],
        ], self::TODAY, 200.0);

        self::assertNotNull($plan);
        self::assertSame(['deposit' => 75.0, 'balance' => 225.0], $plan->amounts(300.0));
    }

    public function testNoChoiceWhenTheTermsDoNotSplit(): void
    {
        self::assertNull(DepositPlan::fromInstallments([['due' => '2026-10-18', 'percent' => 100]], self::TODAY), 'one payment');
        self::assertNull(DepositPlan::fromInstallments([['due' => null, 'percent' => 100]], self::TODAY), 'all now');
        self::assertNull(DepositPlan::fromInstallments([['due' => null, 'percent' => 30], ['due' => '2026-10-04', 'percent' => 70]], self::TODAY), 'balance within 7 days');
        self::assertNotNull(DepositPlan::fromInstallments([['due' => null, 'percent' => 30], ['due' => '2026-10-05', 'percent' => 70]], self::TODAY), 'balance 7 days away');
        self::assertNull(DepositPlan::fromInstallments([], self::TODAY));
    }

    public function testTheCartLineChargesOnlyTheDeposit(): void
    {
        $plan = DepositPlan::fromInstallments([['due' => null, 'percent' => 30], ['due' => '2026-10-20', 'percent' => 70]], self::TODAY);
        self::assertNotNull($plan);
        $row = DepositCartLine::apply(['price' => 299.0, 'base_price' => 299.0, 'original_price' => 299.0, 'extra' => ['total_price' => 299.0]], $plan);

        self::assertSame(89.7, $row['price']);
        self::assertSame(89.7, $row['base_price']);
        self::assertSame(89.7, $row['original_price']);
        self::assertSame(299.0, $row['extra']['total_price'], 'the full price stays for the providers');
        self::assertSame(['ratio' => 0.3, 'balance_due' => '2026-10-20', 'full' => 299.0, 'deposit' => 89.7, 'balance' => 209.3], DepositCartLine::amounts($row));
    }

    /** A pre-order price correction rescales the deposit; it never reverts the line to full. */
    public function testRepriceKeepsTheDeposit(): void
    {
        $plan = DepositPlan::fromInstallments([['due' => null, 'percent' => 30], ['due' => '2026-10-20', 'percent' => 70]], self::TODAY);
        self::assertNotNull($plan);
        $row = DepositCartLine::reprice(DepositCartLine::apply(['price' => 300.0, 'extra' => []], $plan), 400.0);

        self::assertSame(120.0, $row['price']);
        self::assertSame(280.0, DepositCartLine::amounts($row)['balance'] ?? null);

        $full = DepositCartLine::reprice(['price' => 300.0, 'extra' => []], 400.0);
        self::assertSame(400.0, $full['price'], 'a full-price line takes the new price');
        self::assertSame([], DepositCartLine::amounts($full));
    }

    public function testOnlyScheduleProvidersOfferItAndOnlyWhenAsked(): void
    {
        $plan = DepositPlan::fromInstallments([['due' => null, 'percent' => 30], ['due' => '2026-10-20', 'percent' => 70]], self::TODAY);
        $on = new DepositPolicy(true);

        self::assertTrue($on->offers('novoton_holidays'));
        self::assertTrue($on->offers('sphinx_holidays'));
        self::assertFalse($on->offers('eurosite'), 'free-text terms: no schedule');
        self::assertFalse((new DepositPolicy(false))->offers('novoton_holidays'));

        self::assertSame($plan, $on->chosen('sphinx_holidays', ['pay_mode' => 'deposit'], $plan));
        self::assertNull($on->chosen('sphinx_holidays', ['pay_mode' => 'full'], $plan));
        self::assertNull($on->chosen('sphinx_holidays', [], $plan), 'full price is the default');
        self::assertNull($on->chosen('sphinx_holidays', ['pay_mode' => 'deposit'], null), 'terms that no longer split');
        self::assertNull($on->chosen('eurosite', ['pay_mode' => 'deposit'], $plan));
    }

    public function testASavedPlanRoundTrips(): void
    {
        $plan = DepositPlan::fromSaved(['ratio' => 0.3, 'balance_due' => '2026-10-20']);
        self::assertNotNull($plan);
        self::assertSame('2026-10-20', $plan->balanceDue);
        self::assertNull(DepositPlan::fromSaved(['ratio' => 1.0, 'balance_due' => '2026-10-20']));
        self::assertNull(DepositPlan::fromSaved(['ratio' => 0.3]));
        self::assertNull(DepositPlan::fromSaved(null));
    }
}
