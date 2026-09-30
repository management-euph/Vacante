<?php

declare(strict_types=1);

namespace Tygh\Addons\NovotonHolidays\Tests\Unit\ViewModels;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\NovotonHolidays\ViewModels\NovotonBookingSidebarBuilder;

/**
 * Novoton's own API data feeding the shared booking sidebar: the badge from
 * the search quota, the timeline from the price quote's terms.
 */
final class NovotonBookingSidebarTermsTest extends TestCase
{
    public function testQuotaGivesTheBadgeAndTheRoomsLeftNote(): void
    {
        self::assertSame(['on_request', 0], NovotonBookingSidebarBuilder::availability(true, 3));
        self::assertSame(['available', 2], NovotonBookingSidebarBuilder::availability(false, 2));
        self::assertSame(['available', 0], NovotonBookingSidebarBuilder::availability(false, 12), 'no note above 5, as on the search card');
    }

    /**
     * parseCancellationTerms() rows: tillDate ends each window, Type is a
     * percent or a number of nights, FREE is 0 %, a row without a date is
     * the no-show rule. parsePaymentTerms(): no date = on booking.
     */
    public function testQuoteTermsBecomeWindowsAndInstallments(): void
    {
        [$windows, $installments] = NovotonBookingSidebarBuilder::terms(
            [
                ['value' => 'FREE', 'type' => 'Percent', 'till_date' => '2026-09-30', 'is_penalty' => false],
                ['value' => 2.0, 'type' => 'Over Nights', 'till_date' => '2026-10-02', 'is_penalty' => true],
                ['value' => 100.0, 'type' => 'Percent', 'till_date' => '', 'is_penalty' => true],
            ],
            [
                ['percent' => 30, 'date' => '', 'is_on_booking' => true],
                ['percent' => 70, 'date' => '2026-10-01', 'is_on_booking' => false],
            ],
        );

        self::assertSame(['to' => '2026-09-30', 'percent' => 0.0, 'nights' => null, 'no_show' => false], $windows[0]);
        self::assertSame(['to' => '2026-10-02', 'percent' => null, 'nights' => 2, 'no_show' => false], $windows[1]);
        self::assertTrue($windows[2]['no_show']);
        self::assertSame([
            ['due' => null, 'percent' => 30.0],
            ['due' => '2026-10-01', 'percent' => 70.0],
        ], $installments);
    }

    /**
     * The cart booking card reads the quote's XML the cart line keeps, with
     * the formatted lines as the prose fallback.
     */
    public function testCartLineTermsComeFromTheStoredQuoteXml(): void
    {
        $terms = NovotonBookingSidebarBuilder::cartTerms([
            'check_in' => '2026-10-05',
            'terms_of_cancellation_raw' => '<TermsOfCancellation><Penalty tillDate="2026-10-01" Type="Percent">FREE</Penalty>'
                . '<Penalty tillDate="2026-10-04" Type="Percent">50</Penalty><Penalty Type="Percent">100</Penalty></TermsOfCancellation>',
            'terms_of_payment_raw' => '<TermsOfPayment><Percent>100</Percent></TermsOfPayment>',
            'terms_of_cancellation' => "Free until 01.10.2026\n50% until 04.10.2026",
            'terms_of_payment' => '',
        ]);

        // The parser sorts by tillDate, so the dateless no-show rule comes
        // first; TermsTimelineFactory puts it last again.
        self::assertTrue($terms['cancel_windows'][0]['no_show']);
        self::assertSame(['to' => '2026-10-01', 'percent' => 0.0, 'nights' => null, 'no_show' => false], $terms['cancel_windows'][1]);
        self::assertSame(['to' => '2026-10-04', 'percent' => 50.0, 'nights' => null, 'no_show' => false], $terms['cancel_windows'][2]);
        self::assertSame([['due' => null, 'percent' => 100.0]], $terms['payment_rows']);
        self::assertSame(['Free until 01.10.2026', '50% until 04.10.2026'], $terms['cancel_lines']);
        self::assertSame([], $terms['payment_lines']);
    }

    public function testCartLineWithoutTermsGetsNone(): void
    {
        self::assertSame(
            ['cancel_windows' => [], 'payment_rows' => [], 'cancel_lines' => [], 'payment_lines' => []],
            NovotonBookingSidebarBuilder::cartTerms([]),
        );
    }
}
