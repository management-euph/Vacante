<?php

declare(strict_types=1);

namespace Tygh\Addons\SphinxHolidays\Tests\Unit\ViewModels;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\SphinxHolidays\ViewModels\SphinxBookingSidebarBuilder;

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

    public function testProseOrLegacyTermsGiveNoTimeline(): void
    {
        self::assertSame([[], []], SphinxBookingSidebarBuilder::terms(['text' => 'Non-refundable'], ['20% on booking']));
        self::assertSame([[], []], SphinxBookingSidebarBuilder::terms(null, null));
    }

    /**
     * The cart booking card reads the raw API terms the cart line keeps in
     * terms_raw; circuit / package lines keep none.
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
