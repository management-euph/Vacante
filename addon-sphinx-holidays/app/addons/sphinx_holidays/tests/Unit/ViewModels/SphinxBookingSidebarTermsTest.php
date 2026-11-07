<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\ViewModels;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\ViewModels\SphinxBookingSidebarBuilder;
use Tygh\Addons\TravelCore\Services\MoneyFormatter;

/**
 * Sphinx's own API data feeding the shared booking sidebar: the badge from
 * `confirmation`, the timeline from the cumulative terms rules.
 */
final class SphinxBookingSidebarTermsTest extends TestCase
{
    public function testBadgeSaysWhatTheApiConfirmationSays(): void
    {
        self::assertSame('instant', SphinxBookingSidebarBuilder::status('immediate'));
        self::assertSame('on_request', SphinxBookingSidebarBuilder::status('on_request'));
        self::assertSame('available', SphinxBookingSidebarBuilder::status(''));
    }

    /**
     * Documented shape: cumulative absolute amounts in the supplier schedule.
     * They become percents of the schedule's final value; payment rows become
     * per-instalment increments (20% + 80%, as offer_terms.php shows them).
     */
    public function testCumulativeRulesBecomeWindowsAndInstallments(): void
    {
        [$windows, $installments] = SphinxBookingSidebarBuilder::terms(
            ['is_loaded' => true, 'is_free' => false, 'rules' => [
                ['since' => '2026-10-04', 'value' => 3813],
                ['since' => '2026-10-01', 'value' => 1144],
            ]],
            ['is_loaded' => true, 'rules' => [
                ['until' => '2026-09-26', 'value' => 763],
                ['until' => '2026-10-01', 'value' => 3813],
            ]],
        );

        self::assertSame([
            ['from' => '2026-10-01', 'percent' => 30.0],
            ['from' => '2026-10-04', 'percent' => 100.0],
        ], $windows);
        self::assertSame([
            ['due' => '2026-09-26', 'percent' => 20.0],
            ['due' => '2026-10-01', 'percent' => 80.0],
        ], $installments);
    }

    /**
     * A package's or circuit's booking form showed no terms at all. It now
     * shows a hotel page's timeline: Sphinx's cumulative amounts (the
     * supplier's scale) as shares of the store's price, commission included.
     */
    public function testATripsTermsAreAHotelsTimelineAtTheStorePrice(): void
    {
        $money = new MoneyFormatter(['symbol' => '€', 'after' => 'Y', 'decimals' => 0, 'decimals_separator' => ',', 'thousands_separator' => '.']);
        $terms = SphinxBookingSidebarBuilder::tripTerms(
            ['is_loaded' => true, 'is_free' => false, 'text' => null, 'rules' => [
                ['since' => '2027-06-01', 'value' => 185.4],
                ['since' => '2027-07-01', 'value' => 927],
            ]],
            ['is_loaded' => true, 'text' => null, 'rules' => [
                ['until' => '2027-06-01', 'value' => 185.4],
                ['until' => '2027-07-01', 'value' => 927],
            ]],
            1100.0,
            $money,
            '2027-05-10',
            '%d.%m.%Y',
        );

        self::assertNotSame([], $terms['cancel_steps']);
        self::assertSame('free', $terms['cancel_steps'][0]['kind'] ?? null, 'free until the first penalty');
        self::assertNotSame('', $terms['cancel_free_until']);
        self::assertSame('', $terms['cancel_full_amount'], 'nothing is lost by cancelling today');
        $amounts = array_column($terms['payment_steps'], 'amount_label');
        self::assertSame(['220 €', '880 €'], $amounts, '20 % then 80 % of the store price, never 185,4 or 927 (the supplier scale)');
        $json = (string) json_encode($terms['cancel_steps']);
        self::assertStringNotContainsString('927', $json);
        self::assertStringNotContainsString('185', $json);
    }

    /**
     * Where the offer's terms ask part now and the rest later, its booking
     * form offers "Pay a deposit" (booking_payment_split.tpl), as a hotel
     * page does: the deposit and the balance at the store's price, the
     * balance due by the last date. No split to offer when all is due now
     * or nothing is.
     */
    public function testATripsPaymentTermsGiveItsDepositSplit(): void
    {
        $money = new MoneyFormatter(['symbol' => '€', 'after' => 'Y', 'decimals' => 0, 'decimals_separator' => ',', 'thousands_separator' => '.']);
        $split = static fn (array $rules): array => SphinxBookingSidebarBuilder::tripTerms(
            null,
            ['is_loaded' => true, 'rules' => $rules],
            1100.0,
            $money,
            '2027-05-10',
            '%d.%m.%Y',
        )['payment_split'];

        $deposit = $split([['until' => '2027-05-10', 'value' => 185.4], ['until' => '2027-07-01', 'value' => 927]]);
        self::assertSame(['220 €', '880 €'], [$deposit['deposit'] ?? null, $deposit['balance'] ?? null], '20 % now, never 185,4 (the supplier scale)');
        self::assertStringContainsString('2027', $deposit['balance_due'] ?? '');
        self::assertSame([], $split([['until' => '2027-05-10', 'value' => 927]]), 'all due on booking');
        self::assertSame([], $split([['until' => '2027-05-20', 'value' => 185.4], ['until' => '2027-07-01', 'value' => 927]]), 'nothing due on booking');
    }

    public function testATripWithoutAPriceKeepsOnlyItsLines(): void
    {
        $terms = SphinxBookingSidebarBuilder::tripTerms(['text' => 'Nerambursabil'], null, 0.0, new MoneyFormatter(['symbol' => '€']), '2027-05-10', '%d.%m.%Y');

        self::assertSame([[], [], [], ['Nerambursabil']], [$terms['cancel_steps'], $terms['payment_steps'], $terms['payment_split'], $terms['cancel_lines']]);
    }

    public function testProseOrLegacyTermsGiveNoTimeline(): void
    {
        self::assertSame([[], []], SphinxBookingSidebarBuilder::terms(['text' => 'Non-refundable'], ['20% on booking']));
        self::assertSame([[], []], SphinxBookingSidebarBuilder::terms(null, null));
    }

    /**
     * The cart booking card reads the raw API terms the cart line keeps in
     * terms_raw — a hotel's, a package's or a circuit's line alike.
     */
    public function testCartLineTermsComeFromTermsRaw(): void
    {
        $terms = SphinxBookingSidebarBuilder::cartTerms([
            'terms_raw' => (string) json_encode([
                'cancellation' => ['is_loaded' => true, 'is_free' => false, 'rules' => [
                    ['since' => '2026-10-01', 'value' => 1144],
                    ['since' => '2026-10-04', 'value' => 3813],
                ]],
                'payment' => ['is_loaded' => true, 'rules' => [['until' => '2026-09-26', 'value' => 3813]]],
            ]),
            'cancellation_fees' => ['30% from 10/01/2026'],
            'payment_terms' => ['100% until 09/26/2026'],
        ]);

        self::assertSame([
            ['from' => '2026-10-01', 'percent' => 30.0],
            ['from' => '2026-10-04', 'percent' => 100.0],
        ], $terms['cancel_windows']);
        self::assertSame([['due' => '2026-09-26', 'percent' => 100.0]], $terms['payment_rows']);
        self::assertSame(['30% from 10/01/2026'], $terms['cancel_lines']);
        self::assertSame(['100% until 09/26/2026'], $terms['payment_lines']);

        self::assertSame(
            ['cancel_windows' => [], 'payment_rows' => [], 'cancel_lines' => [], 'payment_lines' => []],
            SphinxBookingSidebarBuilder::cartTerms(['travel_provider' => 'sphinx']),
            'circuit / package lines keep no terms',
        );
    }
}
